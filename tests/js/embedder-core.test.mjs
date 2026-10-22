import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	batches,
	chooseDevice,
	compareReference,
	createPinnedFetch,
	dot,
	float32ToBase64,
	formatQuery,
	l2normalize,
	lastTokenPool,
	maskRows,
	mrlTruncate,
	pinnedFileFor,
	sha256Hex,
	verifyPinned,
	wasmSuffix,
} from '../../assets/js/ai-worker/embedder-core.js';

const enc = ( s ) => new TextEncoder().encode( s ).buffer;
const SHA_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

test( 'l2normalize yields unit vectors and handles zero', () => {
	const v = l2normalize( [ 3, 4 ] );
	assert.ok( Math.abs( v[ 0 ] - 0.6 ) < 1e-7 && Math.abs( v[ 1 ] - 0.8 ) < 1e-7 );
	assert.deepEqual( Array.from( l2normalize( [ 0, 0 ] ) ), [ 0, 0 ] );
} );

test( 'MRL truncation keeps the prefix direction and re-normalizes', () => {
	const full = l2normalize( Array.from( { length: 1024 }, ( _, i ) => Math.sin( i + 1 ) ) );
	const cut = mrlTruncate( full, 256 );
	assert.equal( cut.length, 256 );
	assert.ok( Math.abs( dot( cut, cut ) - 1 ) < 1e-5 );
	const ratio = cut[ 10 ] / full[ 10 ];
	for ( let i = 0; i < 256; i += 37 ) {
		assert.ok( Math.abs( cut[ i ] / full[ i ] - ratio ) < 1e-3 );
	}
	assert.equal( mrlTruncate( full, 1024 ).length, 1024 );
	assert.equal( mrlTruncate( full, 0 ).length, 1024 );
} );

test( 'last-token pooling picks the last real token with left or right padding', () => {
	// batch 2, seq 3, hidden 2; values encode (batch, position).
	const hidden = Float32Array.from( [ 0, 0, 0, 1, 0, 2, 1, 0, 1, 1, 1, 2 ] );
	const left = lastTokenPool( hidden, [ [ 0, 1, 1 ], [ 1, 1, 1 ] ], 3, 2 );
	assert.deepEqual( Array.from( left[ 0 ] ), [ 0, 2 ] );
	assert.deepEqual( Array.from( left[ 1 ] ), [ 1, 2 ] );
	const right = lastTokenPool( hidden, [ [ 1, 1, 0 ], [ 1, 1, 1 ] ], 3, 2 );
	assert.deepEqual( Array.from( right[ 0 ] ), [ 0, 1 ] );
	assert.deepEqual( Array.from( right[ 1 ] ), [ 1, 2 ] );
} );

test( 'query formatting matches the model card exactly; documents are unchanged', () => {
	assert.equal( formatQuery( 'Given a web search query, retrieve relevant passages that answer the query', 'Explain gravity' ), 'Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:Explain gravity' );
} );

test( 'self-test comparison enforces tolerance and ranking', () => {
	const ref = [ [ 0.7646, 0.1414 ], [ 0.1355, 0.6 ] ];
	assert.equal( compareReference( [ [ 0.75, 0.15 ], [ 0.14, 0.59 ] ], ref, 0.08 ).ok, true );
	assert.equal( compareReference( [ [ 0.6, 0.15 ], [ 0.14, 0.59 ] ], ref, 0.08 ).ok, false );
	// Within tolerance numerically but ranking inverted.
	assert.equal( compareReference( [ [ 0.2, 0.21 ], [ 0.14, 0.59 ] ], [ [ 0.21, 0.2 ], [ 0.1355, 0.6 ] ], 0.08 ).ok, false );
	assert.equal( compareReference( [ [ NaN, 0.1 ], [ 0.1, 0.6 ] ], ref, 0.08 ).ok, false );
	assert.equal( compareReference( [], ref, 0.08 ).ok, false );
} );

test( 'sha256 and pinned verification', async () => {
	assert.equal( await sha256Hex( enc( 'abc' ) ), SHA_ABC );
	assert.deepEqual( await verifyPinned( enc( 'abc' ), { bytes: 3, sha256: SHA_ABC } ), { ok: true, reason: '' } );
	assert.equal( ( await verifyPinned( enc( 'abd' ), { bytes: 3, sha256: SHA_ABC } ) ).ok, false );
	assert.equal( ( await verifyPinned( enc( 'abcd' ), { bytes: 3, sha256: SHA_ABC } ) ).ok, false );
	assert.equal( ( await verifyPinned( enc( 'xyz' ), { bytes: 3, sha256: '' } ) ).ok, true );
} );

test( 'pinned file lookup requires the pinned revision and a known path', () => {
	const files = { 'config.json': [ 3, '' ], 'onnx/model_q4f16.onnx': [ 9, 'aa' ] };
	const base = 'https://huggingface.co/onnx-community/M/resolve/abc123/';
	assert.deepEqual( pinnedFileFor( base + 'onnx/model_q4f16.onnx', files, 'onnx-community/M', 'abc123' ), { path: 'onnx/model_q4f16.onnx', bytes: 9, sha256: 'aa' } );
	assert.equal( pinnedFileFor( base + 'onnx/evil.onnx', files, 'onnx-community/M', 'abc123' ), null );
	assert.equal( pinnedFileFor( 'https://huggingface.co/onnx-community/M/resolve/main/config.json', files, 'onnx-community/M', 'abc123' ), null );
} );

test( 'base64 encoding is little-endian float32 (matches PHP unpack("g*"))', () => {
	const v = Float32Array.from( [ 1, -0.5, 0.25 ] );
	const b64 = float32ToBase64( v );
	const buf = Buffer.from( b64, 'base64' );
	assert.equal( buf.length, 12 );
	assert.equal( buf.readFloatLE( 0 ), 1 );
	assert.equal( buf.readFloatLE( 4 ), -0.5 );
	assert.equal( buf.readFloatLE( 8 ), 0.25 );
	// Known bytes for 1.0f LE.
	assert.deepEqual( Array.from( buf.subarray( 0, 4 ) ), [ 0, 0, 128, 63 ] );
	// Subarray views encode only their window.
	const big = Float32Array.from( [ 9, 1, 9 ] );
	assert.equal( Buffer.from( float32ToBase64( big.subarray( 1, 2 ) ), 'base64' ).readFloatLE( 0 ), 1 );
} );

test( 'attention mask conversion from BigInt tensors', () => {
	const rows = maskRows( { dims: [ 2, 3 ], data: BigInt64Array.from( [ 0n, 1n, 1n, 1n, 1n, 1n ] ) } );
	assert.deepEqual( rows, [ [ 0, 1, 1 ], [ 1, 1, 1 ] ] );
} );

test( 'device choice: WebGPU (f16-capable) → WASM SIMD → unsupported', () => {
	const base = { moduleWorker: true, webgpu: false, shaderF16: false, wasm: true, simd: true };
	assert.equal( chooseDevice( { ...base, webgpu: true, shaderF16: true }, 'q4f16' ).device, 'webgpu' );
	assert.equal( chooseDevice( { ...base, webgpu: true, shaderF16: false }, 'q4f16' ).device, 'wasm' );
	assert.equal( chooseDevice( { ...base, webgpu: true, shaderF16: false }, 'q8' ).device, 'webgpu' );
	assert.equal( chooseDevice( base, 'q8' ).device, 'wasm' );
	assert.equal( chooseDevice( { ...base, simd: false }, 'q8' ).device, null );
	assert.equal( chooseDevice( { ...base, moduleWorker: false, webgpu: true, shaderF16: true }, 'q8' ).device, null );
	assert.equal( wasmSuffix( { safariBelow26: true, webgpu: false } ), '' );
	assert.equal( wasmSuffix( { safariBelow26: false, webgpu: false } ), '.asyncify' );
} );

test( 'batches are bounded', () => {
	assert.deepEqual( batches( [ 1, 2, 3, 4, 5 ], 2 ), [ [ 1, 2 ], [ 3, 4 ], [ 5 ] ] );
	assert.deepEqual( batches( [], 4 ), [] );
} );

/**
 * Minimal Cache API double.
 */
function memoryCache() {
	const m = new Map();
	return {
		map: m,
		async match( k ) {
			return m.has( k ) ? new Response( m.get( k ).slice( 0 ) ) : undefined;
		},
		async put( k, res ) {
			m.set( k, await res.arrayBuffer() );
		},
		async delete( k ) {
			return m.delete( k );
		},
	};
}

function pinnedSetup( body, opts = {} ) {
	const calls = [];
	const progress = [];
	const cache = opts.cache === undefined ? memoryCache() : opts.cache;
	const nativeFetch = async ( url, init ) => {
		calls.push( { url, init } );
		if ( opts.status ) {
			return new Response( 'x', { status: opts.status } );
		}
		return new Response( body );
	};
	const f = createPinnedFetch( {
		nativeFetch,
		files: { 'tok.json': [ 3, SHA_ABC ], 'config.json': [ 3, '' ] },
		host: 'https://huggingface.co',
		modelId: 'org/model',
		revision: 'r1',
		allow: [ 'https://example.test/wp-content/plugins/x/vendor/' ],
		cache,
		onProgress: ( e ) => progress.push( e ),
	} );
	return { f, calls, cache, progress };
}

const URL_TOK = 'https://huggingface.co/org/model/resolve/r1/tok.json';

test( 'pinned fetch: refuses every URL outside the allow-list', async () => {
	const { f, calls } = pinnedSetup( 'abc' );
	await assert.rejects( f( 'https://evil.test/org/model/resolve/r1/tok.json' ), /Blocked/ );
	await assert.rejects( f( 'https://huggingface.co.evil.test/x' ), /Blocked/ );
	await assert.rejects( f( 'https://cdn.jsdelivr.net/npm/onnxruntime-web/dist/ort.wasm' ), /Blocked/ );
	assert.equal( calls.length, 0 );
} );

test( 'pinned fetch: passes bundled runtime files through', async () => {
	const { f, calls } = pinnedSetup( 'wasm' );
	const r = await f( 'https://example.test/wp-content/plugins/x/vendor/ort.wasm' );
	assert.equal( await r.text(), 'wasm' );
	assert.equal( calls.length, 1 );
} );

test( 'pinned fetch: unknown files and other revisions are 404 without network', async () => {
	const { f, calls } = pinnedSetup( 'abc' );
	assert.equal( ( await f( 'https://huggingface.co/org/model/resolve/r1/generation_config.json' ) ).status, 404 );
	assert.equal( ( await f( 'https://huggingface.co/org/model/resolve/main/tok.json' ) ).status, 404 );
	assert.equal( calls.length, 0 );
} );

test( 'pinned fetch: range probes are answered from the pin', async () => {
	const { f, calls } = pinnedSetup( 'abc' );
	const r = await f( URL_TOK, { headers: new Headers( { Range: 'bytes=0-0' } ) } );
	assert.equal( r.status, 206 );
	assert.equal( r.headers.get( 'content-range' ), 'bytes 0-0/3' );
	// The library probes metadata at revision "main": answered from the pin, still no network …
	const main = await f( 'https://huggingface.co/org/model/resolve/main/tok.json', { headers: { Range: 'bytes=0-0' } } );
	assert.equal( main.status, 206 );
	assert.equal( ( await f( 'https://huggingface.co/org/model/resolve/main/x.json', { headers: { Range: 'bytes=0-0' } } ) ).status, 404 );
	// … while a real download at another revision is refused.
	assert.equal( ( await f( 'https://huggingface.co/org/model/resolve/main/tok.json' ) ).status, 404 );
	assert.equal( calls.length, 0 );
} );

test( 'pinned fetch: verified download is cached and re-verified on reuse', async () => {
	const { f, calls, cache, progress } = pinnedSetup( 'abc' );
	const r = await f( URL_TOK );
	assert.equal( await r.text(), 'abc' );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].init.credentials, 'omit' );
	assert.equal( cache.map.size, 1 );
	const again = await f( URL_TOK );
	assert.equal( await again.text(), 'abc' );
	assert.equal( calls.length, 1, 'served from cache' );
	assert.ok( progress.some( ( p ) => p.cached ) );
	// Tamper with the cache: the entry is discarded and the file is downloaded again.
	cache.map.set( [ ...cache.map.keys() ][ 0 ], enc( 'abd' ) );
	assert.equal( await ( await f( URL_TOK ) ).text(), 'abc' );
	assert.equal( calls.length, 2 );
} );

test( 'pinned fetch: tampered or oversized downloads are rejected and never cached', async () => {
	const bad = pinnedSetup( 'abd' );
	await assert.rejects( bad.f( URL_TOK ), /Integrity check failed/ );
	assert.equal( bad.cache.map.size, 0 );
	const big = pinnedSetup( 'abcdef' );
	await assert.rejects( big.f( URL_TOK ), /larger than pinned|Integrity/ );
	const short = pinnedSetup( 'ab' );
	await assert.rejects( short.f( URL_TOK ), /Integrity check failed/ );
	const http = pinnedSetup( 'abc', { status: 503 } );
	await assert.rejects( http.f( URL_TOK ), /HTTP 503/ );
} );

test( 'pinned fetch: works without a cache', async () => {
	const { f } = pinnedSetup( 'abc', { cache: null } );
	assert.equal( await ( await f( URL_TOK ) ).text(), 'abc' );
} );
