/**
 * Pure helpers shared by the browser worker, the private worker and the JS test-suite.
 * No DOM, no network: everything here is deterministic and unit-tested.
 */

/**
 * L2-normalizes a vector (returns a new Float32Array).
 *
 * @param {ArrayLike<number>} v Vector.
 * @return {Float32Array}
 */
export function l2normalize( v ) {
	let sum = 0;
	for ( let i = 0; i < v.length; i++ ) {
		sum += v[ i ] * v[ i ];
	}
	const n = Math.sqrt( sum );
	const out = new Float32Array( v.length );
	for ( let i = 0; i < v.length; i++ ) {
		out[ i ] = n > 0 ? v[ i ] / n : 0;
	}
	return out;
}

/**
 * Matryoshka (MRL) truncation followed by re-normalization.
 *
 * @param {ArrayLike<number>} v   Vector.
 * @param {number}            dim Target dimension.
 * @return {Float32Array}
 */
export function mrlTruncate( v, dim ) {
	if ( ! dim || dim >= v.length ) {
		return l2normalize( v );
	}
	return l2normalize( Array.prototype.slice.call( v, 0, dim ) );
}

/**
 * Dot product.
 *
 * @param {ArrayLike<number>} a A.
 * @param {ArrayLike<number>} b B.
 * @return {number}
 */
export function dot( a, b ) {
	let s = 0;
	const n = Math.min( a.length, b.length );
	for ( let i = 0; i < n; i++ ) {
		s += a[ i ] * b[ i ];
	}
	return s;
}

/**
 * Last-token pooling over a [batch, seq, hidden] hidden-state tensor given an attention mask,
 * handling both left and right padding (as in the Qwen3-Embedding reference implementation).
 *
 * @param {Float32Array} hidden Flattened hidden states.
 * @param {number[][]}   mask   Attention mask per sequence.
 * @param {number}       seq    Sequence length.
 * @param {number}       size   Hidden size.
 * @return {Float32Array[]}
 */
export function lastTokenPool( hidden, mask, seq, size ) {
	const out = [];
	const leftPadded = mask.every( ( m ) => m[ seq - 1 ] === 1 );
	for ( let b = 0; b < mask.length; b++ ) {
		let idx = seq - 1;
		if ( ! leftPadded ) {
			const len = mask[ b ].reduce( ( a, x ) => a + x, 0 );
			idx = len - 1;
		}
		const start = ( b * seq + idx ) * size;
		out.push( Float32Array.from( hidden.subarray( start, start + size ) ) );
	}
	return out;
}

/**
 * Query formatting from the model card: "Instruct: {task}\nQuery:{query}". Documents get no prefix.
 *
 * @param {string} task  Task description.
 * @param {string} query Query.
 * @return {string}
 */
export function formatQuery( task, query ) {
	return 'Instruct: ' + task + '\nQuery:' + query;
}

/**
 * Expected self-test matrix for a precision: the matrix measured for that dtype with the real
 * pinned files when the profile provides one, else the model-card (fp32) matrix.
 *
 * @param {object} reference Profile reference ({scores, scores_by_dtype?}).
 * @param {string} dtype     Precision.
 * @return {number[][]}
 */
export function expectedScores( reference, dtype ) {
	const byDtype = reference && reference.scores_by_dtype;
	return byDtype && Array.isArray( byDtype[ dtype ] ) ? byDtype[ dtype ] : reference.scores;
}

/**
 * Compares measured similarities with the expected reference matrix.
 *
 * @param {number[][]} measured  Query × document similarities.
 * @param {number[][]} reference Reference matrix.
 * @param {number}     tolerance Max absolute difference.
 * @return {{ok: boolean, maxDiff: number}}
 */
export function compareReference( measured, reference, tolerance ) {
	let maxDiff = 0;
	for ( let i = 0; i < reference.length; i++ ) {
		for ( let j = 0; j < reference[ i ].length; j++ ) {
			const m = measured[ i ] && typeof measured[ i ][ j ] === 'number' ? measured[ i ][ j ] : NaN;
			const d = Math.abs( m - reference[ i ][ j ] );
			maxDiff = Number.isNaN( d ) ? Infinity : Math.max( maxDiff, d );
		}
	}
	// Ranking must also be preserved: each query's best document is the reference's best document.
	const rankOk = reference.every( ( row, i ) => argmax( row ) === argmax( measured[ i ] || [] ) );
	return { ok: maxDiff <= tolerance && rankOk, maxDiff };
}

/**
 * Index of the maximum value.
 *
 * @param {number[]} row Row.
 * @return {number}
 */
function argmax( row ) {
	let best = -1;
	let bestV = -Infinity;
	for ( let i = 0; i < row.length; i++ ) {
		if ( row[ i ] > bestV ) {
			bestV = row[ i ];
			best = i;
		}
	}
	return best;
}

/**
 * Hex SHA-256 of an ArrayBuffer using WebCrypto (browser or Node 20+ globalThis.crypto).
 *
 * @param {ArrayBuffer} buf Data.
 * @return {Promise<string>}
 */
export async function sha256Hex( buf ) {
	const digest = await globalThis.crypto.subtle.digest( 'SHA-256', buf );
	return Array.from( new Uint8Array( digest ) )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

/**
 * Finds the pinned file entry matching a model URL.
 *
 * @param {string} url   Requested URL.
 * @param {object} files Profile files map: path => [bytes, sha256].
 * @param {string} modelId Model ID.
 * @param {string} revision Pinned revision.
 * @param {boolean} anyRevision Match the path under any revision (metadata probes only).
 * @return {{path: string, bytes: number, sha256: string}|null}
 */
export function pinnedFileFor( url, files, modelId, revision, anyRevision = false ) {
	const marker = '/' + modelId + '/resolve/';
	const at = url.indexOf( marker );
	if ( at === -1 ) {
		return null;
	}
	const rest = url.slice( at + marker.length );
	const slash = rest.indexOf( '/' );
	if ( slash === -1 || ( ! anyRevision && decodeURIComponent( rest.slice( 0, slash ) ) !== revision ) ) {
		return null;
	}
	const path = rest.slice( slash + 1 ).split( '?' )[ 0 ];
	if ( ! Object.prototype.hasOwnProperty.call( files, path ) ) {
		return null;
	}
	return { path, bytes: files[ path ][ 0 ], sha256: files[ path ][ 1 ] };
}

/**
 * Verifies a downloaded file against its pinned size and (when known) SHA-256.
 *
 * @param {ArrayBuffer} buf    Data.
 * @param {{bytes:number, sha256:string}} pin Pin.
 * @return {Promise<{ok: boolean, reason: string}>}
 */
export async function verifyPinned( buf, pin ) {
	if ( buf.byteLength !== pin.bytes ) {
		return { ok: false, reason: 'size ' + buf.byteLength + ' ≠ pinned ' + pin.bytes };
	}
	if ( pin.sha256 ) {
		const h = await sha256Hex( buf );
		if ( h !== pin.sha256 ) {
			return { ok: false, reason: 'sha256 mismatch' };
		}
	}
	return { ok: true, reason: '' };
}

/**
 * Encodes a Float32Array as base64 (little-endian float32), as accepted by the REST API.
 *
 * @param {Float32Array} v Vector.
 * @return {string}
 */
export function float32ToBase64( v ) {
	const bytes = new Uint8Array( v.buffer, v.byteOffset, v.byteLength );
	let bin = '';
	for ( let i = 0; i < bytes.length; i += 0x8000 ) {
		bin += String.fromCharCode.apply( null, bytes.subarray( i, i + 0x8000 ) );
	}
	return typeof btoa === 'function' ? btoa( bin ) : Buffer.from( bytes ).toString( 'base64' );
}

/**
 * Decides which ONNX Runtime WASM build to use (mirrors Transformers.js defaults).
 *
 * @param {{webgpu: boolean, safariBelow26: boolean}} caps Capabilities.
 * @return {string} File suffix ('.asyncify' or '').
 */
export function wasmSuffix( caps ) {
	return caps.safariBelow26 && ! caps.webgpu ? '' : '.asyncify';
}

/**
 * Reads a header from a Headers instance, a plain object or an array of pairs.
 *
 * @param {*}      headers Headers.
 * @param {string} name    Lower-case name.
 * @return {string}
 */
function headerValue( headers, name ) {
	if ( ! headers ) {
		return '';
	}
	if ( typeof headers.get === 'function' ) {
		return headers.get( name ) || '';
	}
	const entries = Array.isArray( headers ) ? headers : Object.entries( headers );
	for ( const [ k, v ] of entries ) {
		if ( String( k ).toLowerCase() === name ) {
			return String( v );
		}
	}
	return '';
}

/**
 * Reads a response body into a buffer of exactly the expected size, aborting on overflow.
 *
 * @param {Response} res        Response.
 * @param {number}   expected   Expected bytes.
 * @param {Function} onProgress Progress callback (loaded).
 * @return {Promise<ArrayBuffer>}
 */
async function readCapped( res, expected, onProgress ) {
	if ( ! res.body || typeof res.body.getReader !== 'function' ) {
		const buf = await res.arrayBuffer();
		onProgress( buf.byteLength );
		return buf;
	}
	const out = new Uint8Array( expected );
	const reader = res.body.getReader();
	let loaded = 0;
	for ( ;; ) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		if ( loaded + value.byteLength > expected ) {
			await reader.cancel();
			throw new Error( 'download larger than pinned size' );
		}
		out.set( value, loaded );
		loaded += value.byteLength;
		onProgress( loaded );
	}
	return loaded === expected ? out.buffer : out.slice( 0, loaded ).buffer;
}

/**
 * Builds the fetch() used by Transformers.js: only pinned model files from the pinned revision and
 * same-origin runtime files are allowed; every model file is size/SHA-256 verified before use, also
 * when it comes from the local cache. Any other URL is refused.
 *
 * @param {object}   o             Options.
 * @param {Function} o.nativeFetch Underlying fetch.
 * @param {object}   o.files       path => [bytes, sha256].
 * @param {string}   o.host        Model host (no trailing slash).
 * @param {string}   o.modelId     Model ID.
 * @param {string}   o.revision    Pinned revision.
 * @param {string[]} [o.allow]     URL prefixes passed through untouched (bundled runtime files).
 * @param {object}   [o.cache]     Cache API instance (match/put/delete) or null.
 * @param {Function} [o.onProgress] Progress callback ({file, loaded, total, cached}).
 * @return {Function}
 */
export function createPinnedFetch( o ) {
	const allow = o.allow || [];
	const onProgress = o.onProgress || ( () => {} );
	const host = o.host.replace( /\/+$/, '' );
	return async function pinnedFetch( input, init = {} ) {
		const url = String( input && input.href ? input.href : input );
		if ( allow.some( ( p ) => url.startsWith( p ) ) ) {
			return o.nativeFetch( url, init );
		}
		if ( ! url.startsWith( host + '/' ) ) {
			throw new Error( 'Blocked request outside the allow-list: ' + url );
		}
		if ( headerValue( init.headers, 'range' ) ) {
			// Metadata probe (the library probes some files at revision "main"): answer from the pin
			// without touching the network. Actual downloads below still require the pinned revision.
			const probe = pinnedFileFor( url, o.files, o.modelId, o.revision, true );
			if ( ! probe ) {
				return new Response( null, { status: 404, statusText: 'Not pinned' } );
			}
			return new Response( new Uint8Array( 1 ), {
				status: 206,
				headers: { 'content-range': 'bytes 0-0/' + probe.bytes, 'content-length': '1' },
			} );
		}
		const pin = pinnedFileFor( url, o.files, o.modelId, o.revision );
		if ( ! pin ) {
			// Optional files probed by the library that are not part of the pinned set.
			return new Response( null, { status: 404, statusText: 'Not pinned' } );
		}
		const key = host + '/' + o.modelId + '/resolve/' + o.revision + '/' + pin.path;
		let buf = null;
		if ( o.cache ) {
			try {
				const hit = await o.cache.match( key );
				if ( hit ) {
					const cached = await hit.arrayBuffer();
					const v = await verifyPinned( cached, pin );
					if ( v.ok ) {
						buf = cached;
						onProgress( { file: pin.path, loaded: pin.bytes, total: pin.bytes, cached: true } );
					} else {
						await o.cache.delete( key );
					}
				}
			} catch ( e ) {
				buf = null;
			}
		}
		if ( ! buf ) {
			const res = await o.nativeFetch( url, { credentials: 'omit', referrerPolicy: 'no-referrer' } );
			if ( ! res.ok ) {
				throw new Error( 'Download failed for ' + pin.path + ': HTTP ' + res.status );
			}
			buf = await readCapped( res, pin.bytes, ( loaded ) =>
				onProgress( { file: pin.path, loaded, total: pin.bytes, cached: false } )
			);
			const v = await verifyPinned( buf, pin );
			if ( ! v.ok ) {
				throw new Error( 'Integrity check failed for ' + pin.path + ': ' + v.reason );
			}
			if ( o.cache ) {
				try {
					await o.cache.put( key, new Response( buf, { headers: { 'content-length': String( pin.bytes ) } } ) );
				} catch ( e ) {
					// Quota exceeded: keep working without the cache.
				}
			}
		}
		return new Response( buf, {
			status: 200,
			headers: { 'content-length': String( pin.bytes ), 'content-type': 'application/octet-stream' },
		} );
	};
}

/**
 * Converts an attention-mask tensor (int64 BigInt data) to number rows.
 *
 * @param {{dims: number[], data: ArrayLike<number|bigint>}} t Tensor.
 * @return {number[][]}
 */
export function maskRows( t ) {
	const [ b, s ] = t.dims;
	const rows = [];
	for ( let i = 0; i < b; i++ ) {
		const row = new Array( s );
		for ( let j = 0; j < s; j++ ) {
			row[ j ] = Number( t.data[ i * s + j ] );
		}
		rows.push( row );
	}
	return rows;
}

/**
 * Picks the inference device from detected capabilities.
 *
 * @param {object} caps   {webgpu, shaderF16, wasm, simd, moduleWorker}.
 * @param {string} dtype  Profile dtype.
 * @return {{device: string|null, reason: string}}
 */
export function chooseDevice( caps, dtype ) {
	if ( ! caps.moduleWorker ) {
		return { device: null, reason: 'module-workers-unsupported' };
	}
	const needsF16 = /f16/.test( dtype );
	if ( caps.webgpu && ( ! needsF16 || caps.shaderF16 ) ) {
		return { device: 'webgpu', reason: 'webgpu' };
	}
	if ( caps.wasm && caps.simd ) {
		return { device: 'wasm', reason: caps.webgpu ? 'webgpu-without-f16' : 'wasm' };
	}
	return { device: null, reason: 'no-webgpu-no-wasm-simd' };
}

/**
 * Splits work into bounded batches.
 *
 * @param {Array} items Items.
 * @param {number} size Batch size.
 * @return {Array[]}
 */
export function batches( items, size ) {
	const out = [];
	for ( let i = 0; i < items.length; i += size ) {
		out.push( items.slice( i, i + size ) );
	}
	return out;
}
