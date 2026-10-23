/**
 * Browser test of the Tier 1 worker with the REAL Qwen3-Embedding-0.6B ONNX model, downloaded by
 * the worker itself from https://huggingface.co at the pinned revision through its pinned,
 * SHA-256-verifying fetch (exactly what an administrator's browser does). CI only (needs internet).
 *
 *   node tests/real-model/browser-real.mjs <real-model-vectors.json>
 *
 * Scenarios (results in tests/e2e/artifacts/real-model-browser.json):
 *   A. q8 on WASM: load, model-card self-test, embed; vectors compared with the Python ONNX Runtime
 *      vectors of the same inputs (tests/real-model/verify_model.py). Required to pass.
 *   B. Second load is served from the verified browser cache (no model download).
 *   C. q4f16 on WASM (the default dtype on a device without WebGPU): recorded, not required.
 *   D. WebGPU through SwiftShader (software adapter) if Chromium exposes one: recorded, not required.
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
const results = [];
const info = {};

function record( name, ok, detail, required = true ) {
	results.push( { name, ok, required, detail } );
	console.log( ( ok ? 'PASS ' : required ? 'FAIL ' : 'INFO ' ) + name + ( detail ? ' — ' + detail : '' ) );
}

function profileFor( dtype ) {
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
		files: { ...prof.files.common, ...prof.files[ dtype ] },
		reference: prof.reference,
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

async function openPage( args ) {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_PATH || undefined, args } );
	const page = await browser.newPage();
	const hosts = new Map();
	page.context().on( 'request', ( r ) => {
		const u = new URL( r.url() );
		if ( u.protocol === 'http:' || u.protocol === 'https:' ) {
			const k = u.host;
			hosts.set( k, ( hosts.get( k ) || 0 ) + 1 );
		}
	} );
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( String( e ) ) );
	await page.goto( origin + '/plugin/tests/e2e/fixture-worker.html' );
	return { browser, page, hosts, errors };
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

let failed = false;
try {
	// A. q8 on WASM against the real Hub.
	const A = await openPage( [] );
	const init = { type: 'init', vendorUrl, profile: profileFor( 'q8' ), device: 'wasm', wasmSuffix: '.asyncify', threads: 1 };
	const items = ref.items.map( ( it, i ) => ( { queue_id: i + 1, text: it.text, role: it.role } ) );
	let t0 = Date.now();
	const run = await withTimeout(
		A.page.evaluate( ( a ) => window.sseoRun( [ a.init, { type: 'selftest', id: 1, reference: a.reference }, { type: 'embed', id: 2, items: a.items } ] ), { init, reference: prof.reference, items } ),
		20 * 60 * 1000,
		'q8/wasm'
	);
	const [ ready, self, emb ] = run.out;
	info.q8_wasm = { ready, selftest: self && { ok: self.ok, maxDiff: self.maxDiff, matrix: self.matrix, tolerance: self.tolerance }, total_seconds: ( Date.now() - t0 ) / 1000 };
	record( 'A1 worker downloads the pinned q8 files from huggingface.co (SHA-256 verified) and loads them on WASM', ready && ready.type === 'ready', ready && ( ready.type === 'ready' ? 'load ' + ready.ms + ' ms' : ready.message ) );
	record( 'A2 model-card self-test passes in the browser (q8)', !! ( self && self.type === 'selftest' && self.ok ), self && ( self.type === 'selftest' ? 'max |Δ| ' + self.maxDiff.toFixed( 4 ) + ' (tolerance ' + self.tolerance + '), matrix ' + JSON.stringify( self.matrix.map( ( r ) => r.map( ( x ) => +x.toFixed( 4 ) ) ) ) : self.message ) );
	if ( emb && emb.type === 'embedded' ) {
		const cos = emb.results.map( ( r, i ) => cosine( decode( r.vector ), ref.vectors[ i ] ) );
		const min = Math.min( ...cos );
		info.q8_wasm.embed_ms = emb.ms;
		info.q8_wasm.cosine_vs_python = cos.map( ( c ) => +c.toFixed( 6 ) );
		record( 'A3 browser vectors match Python ONNX Runtime vectors of the same inputs (cosine ≥ 0.99)', min >= 0.99, 'min cosine ' + min.toFixed( 6 ) + ' over ' + cos.length + ' texts, ' + emb.ms + ' ms' );
	} else {
		record( 'A3 browser vectors match Python ONNX Runtime vectors', false, emb ? emb.message : 'no embed result' );
	}
	const hostsA = Object.fromEntries( A.hosts );
	info.q8_wasm.hosts = hostsA;
	const allowed = ( h ) => h === '127.0.0.1:' + port || h === 'huggingface.co' || /(^|\.)hf\.co$/.test( h ) || /^cdn-lfs[a-z0-9.-]*\.huggingface\.co$/.test( h );
	const bad = Object.keys( hostsA ).filter( ( h ) => ! allowed( h ) );
	record( 'A4 only this site and the Hugging Face model host/CDN were contacted', bad.length === 0, JSON.stringify( hostsA ) );

	// B. Reload from the verified cache.
	A.hosts.clear();
	t0 = Date.now();
	const again = await withTimeout( A.page.evaluate( ( a ) => window.sseoRun( [ a.init ] ), { init } ), 10 * 60 * 1000, 'cached load' );
	const cached = again.progress.filter( ( p ) => p.cached ).map( ( p ) => p.file );
	const remote = [ ...A.hosts.keys() ].filter( ( h ) => h !== '127.0.0.1:' + port );
	record( 'B1 second load re-verifies files from the browser cache without downloading the model again', again.out[ 0 ].type === 'ready' && cached.includes( 'onnx/model_quantized.onnx' ) && remote.length === 0, 'cached: ' + cached.join( ', ' ) + '; remote hosts: ' + ( remote.join( ', ' ) || 'none' ) + '; ' + ( Date.now() - t0 ) + ' ms' );
	record( 'A5 no page errors', A.errors.length === 0, A.errors.join( ' | ' ) );
	await A.browser.close();

	// C. q4f16 on WASM (informational).
	const C = await openPage( [] );
	t0 = Date.now();
	const c = await withTimeout(
		C.page.evaluate( ( a ) => window.sseoRun( [ a.init, { type: 'selftest', id: 1, reference: a.reference } ] ), { init: { ...init, profile: profileFor( 'q4f16' ) }, reference: prof.reference } ),
		20 * 60 * 1000,
		'q4f16/wasm'
	);
	const cs = c.out[ 1 ];
	info.q4f16_wasm = { ready: c.out[ 0 ], selftest: cs && { ok: cs.ok, maxDiff: cs.maxDiff, matrix: cs.matrix, tolerance: cs.tolerance }, total_seconds: ( Date.now() - t0 ) / 1000 };
	record( 'C q4f16 on WASM: load + self-test', !! ( cs && cs.type === 'selftest' && cs.ok ), c.out[ 0 ].type === 'error' ? c.out[ 0 ].message : cs && ( cs.type === 'selftest' ? 'max |Δ| ' + cs.maxDiff.toFixed( 4 ) + ' (tolerance ' + cs.tolerance + '), ' + info.q4f16_wasm.total_seconds + ' s' : cs.message ), false );
	await C.browser.close();

	// D. WebGPU through SwiftShader (informational; no GPU on CI runners).
	const D = await openPage( [ '--enable-unsafe-webgpu', '--enable-features=Vulkan', '--use-vulkan=swiftshader', '--use-webgpu-adapter=swiftshader', '--disable-vulkan-surface' ] );
	const caps = await D.page.evaluate( async () => {
		if ( ! navigator.gpu ) {
			return { gpu: false };
		}
		const a = await navigator.gpu.requestAdapter();
		return a ? { gpu: true, adapter: true, f16: a.features.has( 'shader-f16' ), info: a.info ? { vendor: a.info.vendor, architecture: a.info.architecture, description: a.info.description } : null } : { gpu: true, adapter: false };
	} );
	info.webgpu = { caps };
	if ( caps.adapter ) {
		const dtype = caps.f16 ? 'q4f16' : 'q8';
		t0 = Date.now();
		const d = await withTimeout(
			D.page.evaluate( ( a ) => window.sseoRun( [ a.init, { type: 'selftest', id: 1, reference: a.reference } ] ), { init: { ...init, device: 'webgpu', profile: profileFor( dtype ) }, reference: prof.reference } ),
			20 * 60 * 1000,
			'webgpu'
		);
		const ds = d.out[ 1 ];
		info.webgpu.run = { dtype, ready: d.out[ 0 ], selftest: ds && { ok: ds.ok, maxDiff: ds.maxDiff, matrix: ds.matrix }, total_seconds: ( Date.now() - t0 ) / 1000 };
		record( 'D WebGPU (SwiftShader software adapter) ' + dtype + ': load + self-test', !! ( ds && ds.type === 'selftest' && ds.ok ), d.out[ 0 ].type === 'error' ? d.out[ 0 ].message : ds && ( ds.type === 'selftest' ? 'max |Δ| ' + ds.maxDiff.toFixed( 4 ) : ds.message ), false );
	} else {
		record( 'D WebGPU adapter available in headless Chromium', false, JSON.stringify( caps ), false );
	}
	await D.browser.close();
} catch ( e ) {
	record( 'harness', false, String( e && e.stack ) );
} finally {
	server.close();
}

failed = results.some( ( r ) => r.required && ! r.ok );
fs.mkdirSync( OUT, { recursive: true } );
fs.writeFileSync( path.join( OUT, 'real-model-browser.json' ), JSON.stringify( { when: new Date().toISOString(), model: prof.model_id + '@' + prof.revision, chromium: chromium.name(), results, info }, null, 1 ) );
console.log( results.filter( ( r ) => r.ok ).length + '/' + results.length + ' passed (' + results.filter( ( r ) => r.required ).length + ' required)' );
process.exit( failed ? 1 : 0 );
