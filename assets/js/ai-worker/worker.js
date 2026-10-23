/**
 * Module Web Worker running Qwen3-Embedding-0.6B (ONNX) with the bundled Transformers.js runtime.
 *
 * Messages in:  {type:'init', vendorUrl, profile, device, wasmSuffix, threads}
 *               {type:'selftest', id, reference}
 *               {type:'embed', id, items:[{queue_id, text, role}]}
 * Messages out: {type:'progress', file, loaded, total, cached}
 *               {type:'ready', device, dtype, ms}
 *               {type:'selftest', id, ok, maxDiff, matrix, tolerance, vectors:{queries, documents}}
 *               {type:'embedded', id, results:[{queue_id, vector}], ms}
 *               {type:'error', id, message}
 *
 * Nothing here talks to WordPress: the page script owns claim/complete so the lease token never
 * enters the worker.
 */

import {
	batches,
	compareReference,
	createPinnedFetch,
	expectedScores,
	dot,
	formatQuery,
	l2normalize,
	lastTokenPool,
	maskRows,
	mrlTruncate,
	float32ToBase64,
} from './embedder-core.js';

const BATCH = 4;
let state = null;

/**
 * Posts an error.
 *
 * @param {*}     id  Request ID.
 * @param {Error} err Error.
 */
function fail( id, err ) {
	self.postMessage( { type: 'error', id, message: String( ( err && err.message ) || err ) } );
}

/**
 * Loads the runtime, tokenizer and model.
 *
 * @param {object} msg Init message.
 */
async function init( msg ) {
	const started = performance.now();
	const vendor = new URL( msg.vendorUrl, self.location.href );
	if ( vendor.origin !== self.location.origin ) {
		throw new Error( 'Runtime must be served from this site.' );
	}
	const p = msg.profile;
	const T = await import( new URL( 'transformers.min.js', vendor ).href );
	const env = T.env;
	let cache = null;
	try {
		cache = await caches.open( 'sseo-model-' + p.revision.slice( 0, 12 ) );
	} catch ( e ) {
		cache = null;
	}
	env.allowLocalModels = false;
	env.allowRemoteModels = true;
	env.useBrowserCache = false; // Our own cache below re-verifies every file before use.
	env.remoteHost = p.host.replace( /\/+$/, '' ) + '/';
	env.remotePathTemplate = '{model}/resolve/{revision}/';
	env.fetch = createPinnedFetch( {
		nativeFetch: self.fetch.bind( self ),
		files: p.files,
		host: p.host,
		modelId: p.model_id,
		revision: p.revision,
		allow: [ vendor.href ],
		cache,
		onProgress: ( e ) => self.postMessage( Object.assign( { type: 'progress' }, e ) ),
	} );
	const suffix = msg.wasmSuffix === '' ? '' : '.asyncify';
	env.backends.onnx.wasm.wasmPaths = {
		mjs: new URL( 'ort-wasm-simd-threaded' + suffix + '.mjs', vendor ).href,
		wasm: new URL( 'ort-wasm-simd-threaded' + suffix + '.wasm', vendor ).href,
	};
	env.backends.onnx.wasm.numThreads = Math.max( 1, Math.min( 4, msg.threads || 1 ) );

	const opts = { revision: p.revision };
	const tokenizer = await T.AutoTokenizer.from_pretrained( p.model_id, opts );
	const model = await T.AutoModel.from_pretrained( p.model_id, Object.assign( {}, opts, { dtype: p.dtype, device: msg.device } ) );
	state = { tokenizer, model, profile: p, device: msg.device };
	self.postMessage( { type: 'ready', device: msg.device, dtype: p.dtype, ms: Math.round( performance.now() - started ) } );
}

/**
 * Embeds texts (already formatted) into full-dimension, L2-normalized vectors.
 *
 * @param {string[]} texts Texts.
 * @return {Promise<Float32Array[]>}
 */
async function embedTexts( texts ) {
	const out = [];
	for ( const group of batches( texts, BATCH ) ) {
		const inputs = state.tokenizer( group, { padding: true, truncation: true, max_length: state.profile.max_tokens } );
		const outputs = await state.model( inputs );
		let hidden = outputs.last_hidden_state;
		if ( hidden.type !== 'float32' ) {
			hidden = hidden.to( 'float32' );
		}
		const [ , seq, size ] = hidden.dims;
		const pooled = lastTokenPool( hidden.data, maskRows( inputs.attention_mask ), seq, size );
		for ( const v of pooled ) {
			out.push( l2normalize( v ) );
		}
	}
	return out;
}

/**
 * Runs the model-card reference check.
 *
 * @param {object} msg Message.
 */
async function selftest( msg ) {
	const ref = msg.reference;
	const p = state.profile;
	const q = await embedTexts( ref.queries.map( ( x ) => formatQuery( p.query_task, x ) ) );
	const d = await embedTexts( ref.documents );
	const matrix = q.map( ( qv ) => d.map( ( dv ) => dot( qv, dv ) ) );
	const tolerance = ref.tolerance[ p.dtype ] || 0.03;
	const expected = expectedScores( ref, p.dtype );
	const cmp = compareReference( matrix, expected, tolerance );
	state.verified = cmp.ok;
	// The vectors go to the server, which recomputes the matrix itself: the page's "ok" is not trusted.
	const vectors = { queries: q.map( ( v ) => Array.from( v ) ), documents: d.map( ( v ) => Array.from( v ) ) };
	self.postMessage( { type: 'selftest', id: msg.id, ok: cmp.ok, maxDiff: cmp.maxDiff, matrix, expected, tolerance, vectors } );
}

/**
 * Embeds claimed items.
 *
 * @param {object} msg Message.
 */
async function embed( msg ) {
	if ( ! state || ! state.verified ) {
		throw new Error( 'Self-test has not passed; refusing to embed.' );
	}
	const started = performance.now();
	const p = state.profile;
	const texts = msg.items.map( ( it ) => ( it.role === 'query' ? formatQuery( p.query_task, it.text ) : String( it.text ) ) );
	const vectors = await embedTexts( texts );
	const results = msg.items.map( ( it, i ) => ( {
		queue_id: it.queue_id,
		vector: float32ToBase64( mrlTruncate( vectors[ i ], p.dimension ) ),
	} ) );
	self.postMessage( { type: 'embedded', id: msg.id, results, ms: Math.round( performance.now() - started ) } );
}

self.onmessage = async ( ev ) => {
	const msg = ev.data || {};
	try {
		if ( msg.type === 'init' ) {
			await init( msg );
		} else if ( msg.type === 'selftest' ) {
			await selftest( msg );
		} else if ( msg.type === 'embed' ) {
			await embed( msg );
		}
	} catch ( err ) {
		fail( msg.id, err );
	}
};
