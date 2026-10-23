/**
 * Browser test of the Tier 1 worker with the REAL Qwen3-Embedding-0.6B ONNX model, downloaded by
 * the worker itself from https://huggingface.co at the pinned revision through its pinned,
 * SHA-256-verifying fetch (exactly what an administrator's browser does). CI only (needs internet).
 *
 *   node tests/real-model/browser-real.mjs <real-model-vectors.json> [<manifest.json>]
 *
 * Scenarios (results in tests/e2e/artifacts/real-model-browser.json):
 *   A. q4f16 (the default precision) on WASM in a persistent browser profile: load, self-test,
 *      embed; vectors compared with the Python ONNX Runtime vectors of the same inputs
 *      (tests/real-model/verify_model.py). Required to pass.
 *   B. Second load is served from the verified browser cache (no model download). Required.
 *   C. fp16 (offered) and q8 (not offered) on WASM, each compared with Python: recorded.
 *   D. WebGPU through SwiftShader (software adapter) if Chromium exposes one: recorded.
 * Variants the plugin does not offer use the Hub-metadata pins from the manifest of fetch_verify.py.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { startServer } from '../e2e/static-server.mjs';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const OUT = path.join( ROOT, 'tests/e2e/artifacts' );
const ref = JSON.parse( fs.readFileSync( process.argv[ 2 ] || path.join( OUT, 'real-model-vectors.json' ), 'utf8' ) );
const prof = JSON.parse( execFileSync( 'php', [ path.join( ROOT, 'tests/real-model/export.php' ), 'profile' ] ) );
const manifest = process.argv[ 3 ] ? JSON.parse( fs.readFileSync( process.argv[ 3 ], 'utf8' ) ) : { files: {} };
// Variants the plugin does not offer are only measured: their self-test cannot block embedding.
const MEASURE_ONLY = 1.0;
const results = [];
const info = {};

function record( name, ok, detail, required = true ) {
	results.push( { name, ok, required, detail } );
	console.log( ( ok ? 'PASS ' : required ? 'FAIL ' : 'INFO ' ) + name + ( detail ? ' — ' + detail : '' ) );
}

function profileFor( dtype ) {
	const files = prof.files[ dtype ] || manifest.files[ dtype ];
	const reference = prof.reference.tolerance[ dtype ] ? prof.reference : { ...prof.reference, tolerance: { ...prof.reference.tolerance, [ dtype ]: MEASURE_ONLY } };
	return {
		model_id: prof.model_id,
		revision: prof.revision,
		host: prof.host,
		dtype,
		dimension: prof.dimension,
		pooling: prof.pooling,
		normalize: true,
		preprocess_version: prof.preprocess_version,
		max_tokens: prof.max_tokens,
		query_task: prof.query_task,
		files: { ...prof.files.common, ...files },
		reference,
	};
}

function withTimeout( promise, ms, label ) {
	let t;
	return Promise.race( [
		promise,
		new Promise( ( resolve ) => {
			t = setTimeout( () => resolve( { out: [ { type: 'error', message: label + ' timed out after ' + ms / 1000 + ' s' } ], progress: [] } ), ms );
		} ),
	] ).finally( () => clearTimeout( t ) );
}

const { server, port } = await startServer();
const origin = 'http://127.0.0.1:' + port;
const vendorUrl = origin + '/plugin/assets/vendor/transformers/';

async function openPage( args, persistentDir ) {
	const opts = { executablePath: process.env.CHROMIUM_PATH || undefined, args };
	const browser = persistentDir ? null : await chromium.launch( opts );
	const context = persistentDir ? await chromium.launchPersistentContext( persistentDir, opts ) : await browser.newContext();
	const page = await context.newPage();
	const hosts = new Map();
	context.on( 'request', ( r ) => {
		const u = new URL( r.url() );
		if ( u.protocol === 'http:' || u.protocol === 'https:' ) {
			const k = u.host;
			hosts.set( k, ( hosts.get( k ) || 0 ) + 1 );
		}
	} );
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( String( e ) ) );
	await page.goto( origin + '/plugin/tests/e2e/fixture-worker.html' );
	const storage = await page.evaluate( async () => {
		const est = navigator.storage && navigator.storage.estimate ? await navigator.storage.estimate() : {};
		return { quota_mb: Math.round( ( est.quota || 0 ) / 1048576 ), usage_mb: Math.round( ( est.usage || 0 ) / 1048576 ) };
	} );
	return { close: () => ( browser ? browser.close() : context.close() ), page, hosts, errors, storage };
}

function decode( b64 ) {
	const buf = Buffer.from( b64, 'base64' );
	return Array.from( new Float32Array( buf.buffer, buf.byteOffset, buf.length / 4 ) );
}

function cosine( a, b ) {
	let d = 0;
	let na = 0;
	let nb = 0;
	for ( let i = 0; i < a.length; i++ ) {
		d += a[ i ] * b[ i ];
		na += a[ i ] * a[ i ];
		nb += b[ i ] * b[ i ];
	}
	return d / Math.sqrt( na * nb );
}

const items = ref.items.map( ( it, i ) => ( { queue_id: i + 1, text: it.text, role: it.role } ) );
const fmt = ( m ) => JSON.stringify( m.map( ( r ) => r.map( ( x ) => +x.toFixed( 4 ) ) ) );

/**
 * Loads one precision, runs the self-test and (when it passes) embeds the comparison inputs.
 */
async function runVariant( P, dtype, device ) {
	const p = profileFor( dtype );
	const init = { type: 'init', vendorUrl, profile: p, device, wasmSuffix: '.asyncify', threads: 1 };
	const t0 = Date.now();
	const run = await withTimeout(
		P.page.evaluate( ( a ) => window.sseoRun( [ a.init, { type: 'selftest', id: 1, reference: a.reference }, { type: 'embed', id: 2, items: a.items } ] ), { init, reference: p.reference, items } ),
		20 * 60 * 1000,
		dtype + '/' + device
	);
	const [ ready, self, emb ] = run.out;
	const out = { init, ready, selftest: self && self.type === 'selftest' ? { ok: self.ok, maxDiff: self.maxDiff, matrix: self.matrix, expected: self.expected, tolerance: self.tolerance } : self, seconds: ( Date.now() - t0 ) / 1000 };
	if ( emb && emb.type === 'embedded' && ref.vectors[ dtype ] ) {
		out.cosine_vs_python = emb.results.map( ( r, i ) => +cosine( decode( r.vector ), ref.vectors[ dtype ][ i ] ).toFixed( 6 ) );
		out.embed_ms = emb.ms;
	} else if ( emb ) {
		out.embed = emb.type === 'error' ? emb.message : 'no python reference';
	}
	return out;
}

const describe = ( v ) => ( v.ready && v.ready.type === 'error' ? v.ready.message : v.selftest && v.selftest.ok !== undefined ? 'self-test max |Δ| ' + v.selftest.maxDiff.toFixed( 4 ) + ' vs expected ' + fmt( v.selftest.expected ) + ' (tolerance ' + v.selftest.tolerance + '), measured ' + fmt( v.selftest.matrix ) + ( v.cosine_vs_python ? ', cosine vs Python min ' + Math.min( ...v.cosine_vs_python ).toFixed( 4 ) : '' ) + ', ' + v.seconds + ' s' : JSON.stringify( v.selftest ) );

let failed = false;
const profileDir = fs.mkdtempSync( path.join( process.env.RUNNER_TEMP || '/tmp', 'sseo-chromium-' ) );
try {
	// A. q4f16 on WASM against the real Hub, persistent profile (like an administrator's browser).
	const A = await openPage( [], profileDir );
	info.storage = A.storage;
	const a = await runVariant( A, 'q4f16', 'wasm' );
	info.q4f16_wasm = a;
	record( 'A1 worker downloads the pinned q4f16 files from huggingface.co (SHA-256 verified) and loads them on WASM', a.ready && a.ready.type === 'ready', a.ready && ( a.ready.type === 'ready' ? 'load ' + a.ready.ms + ' ms' : a.ready.message ) );
	record( 'A2 self-test passes in the browser (q4f16 vs its measured matrix)', !! ( a.selftest && a.selftest.ok ), describe( a ) );
	const minA = a.cosine_vs_python ? Math.min( ...a.cosine_vs_python ) : 0;
	record( 'A3 browser vectors match Python ONNX Runtime vectors of the same inputs (cosine ≥ 0.99)', minA >= 0.99, 'min cosine ' + minA.toFixed( 6 ) + ' over ' + ( a.cosine_vs_python || [] ).length + ' texts' );
	const hostsA = Object.fromEntries( A.hosts );
	info.q4f16_wasm.hosts = hostsA;
	const allowed = ( h ) => h === '127.0.0.1:' + port || h === 'huggingface.co' || /(^|\.)hf\.co$/.test( h ) || /^cdn-lfs[a-z0-9.-]*\.huggingface\.co$/.test( h );
	const bad = Object.keys( hostsA ).filter( ( h ) => ! allowed( h ) );
	record( 'A4 only this site and the Hugging Face model host/CDN were contacted', bad.length === 0, JSON.stringify( hostsA ) );

	// B. Reload from the verified cache (same persistent profile).
	A.hosts.clear();
	const t0 = Date.now();
	const again = await withTimeout( A.page.evaluate( ( x ) => window.sseoRun( [ x.init ] ), { init: a.init } ), 10 * 60 * 1000, 'cached load' );
	const cached = again.progress.filter( ( p ) => p.cached ).map( ( p ) => p.file );
	const remote = [ ...A.hosts.keys() ].filter( ( h ) => h !== '127.0.0.1:' + port );
	record( 'B1 second load re-verifies files from the browser cache without downloading the model again', again.out[ 0 ].type === 'ready' && cached.includes( 'onnx/model_q4f16.onnx' ) && remote.length === 0, 'cached: ' + cached.join( ', ' ) + '; remote hosts: ' + ( remote.join( ', ' ) || 'none' ) + '; ' + ( Date.now() - t0 ) + ' ms; storage quota ' + A.storage.quota_mb + ' MB' );
	record( 'A5 no page errors', A.errors.length === 0, A.errors.join( ' | ' ) );
	await A.close();

	// C. Other precisions on WASM (measured, not required).
	for ( const dtype of [ 'fp16', 'q8' ].filter( ( d ) => prof.files[ d ] || manifest.files[ d ] ) ) {
		const C = await openPage( [] );
		const c = await runVariant( C, dtype, 'wasm' );
		info[ dtype + '_wasm' ] = c;
		record( 'C ' + dtype + ' on WASM' + ( prof.files[ dtype ] ? ' (offered)' : ' (not offered)' ), !! ( c.selftest && c.selftest.ok ), describe( c ), false );
		await C.close();
	}

	// D. WebGPU through SwiftShader (informational; no GPU on CI runners).
	const D = await openPage( [ '--enable-unsafe-webgpu', '--enable-features=Vulkan', '--use-vulkan=swiftshader', '--use-webgpu-adapter=swiftshader', '--disable-vulkan-surface' ] );
	const caps = await D.page.evaluate( async () => {
		if ( ! navigator.gpu ) {
			return { gpu: false };
		}
		const ad = await navigator.gpu.requestAdapter();
		return ad ? { gpu: true, adapter: true, f16: ad.features.has( 'shader-f16' ), info: ad.info ? { vendor: ad.info.vendor, architecture: ad.info.architecture } : null } : { gpu: true, adapter: false };
	} );
	info.webgpu = { caps };
	if ( caps.adapter ) {
		const dtype = caps.f16 ? 'q4f16' : ( manifest.files.q4 ? 'q4' : 'q4f16' );
		const d = await runVariant( D, dtype, 'webgpu' );
		info.webgpu.run = d;
		record( 'D WebGPU (SwiftShader software adapter) ' + dtype, !! ( d.selftest && d.selftest.ok ), describe( d ), false );
	} else {
		record( 'D WebGPU adapter available in headless Chromium', false, JSON.stringify( caps ), false );
	}
	await D.close();
} catch ( e ) {
	record( 'harness', false, String( e && e.stack ) );
} finally {
	server.close();
}

failed = results.some( ( r ) => r.required && ! r.ok );
for ( const k of Object.keys( info ) ) {
	if ( info[ k ] && info[ k ].init ) {
		delete info[ k ].init;
	}
}
fs.mkdirSync( OUT, { recursive: true } );
fs.writeFileSync( path.join( OUT, 'real-model-browser.json' ), JSON.stringify( { when: new Date().toISOString(), model: prof.model_id + '@' + prof.revision, results, info }, null, 1 ) );
console.log( 'INFO ' + JSON.stringify( info ) );
console.log( results.filter( ( r ) => r.ok ).length + '/' + results.length + ' passed (' + results.filter( ( r ) => r.required ).length + ' required)' );
process.exit( failed ? 1 : 0 );
