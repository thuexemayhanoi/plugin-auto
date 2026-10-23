/**
 * Browser test of the Tier 1 worker in real Chromium, using the TINY RANDOM FIXTURE model from
 * tests/fixtures/tiny-qwen3 (NOT Qwen3-Embedding: the real weights could not be downloaded in the
 * build environment). It proves the pipeline — pinned/SHA-verified download, tokenizer, ONNX
 * Runtime Web (bundled, same-origin), position ids, last-token pooling, L2, MRL, base64, self-test
 * gate — against an independent NumPy reference.
 *
 * Run: node tests/e2e/worker-fixture.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { startServer } from './static-server.mjs';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const expected = JSON.parse( fs.readFileSync( path.join( ROOT, 'tests/fixtures/tiny-qwen3/expected.json' ), 'utf8' ) );
const results = [];

function record( name, ok, detail ) {
	results.push( { name, ok, detail } );
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( detail ? ' — ' + detail : '' ) );
}

const { server, log, port } = await startServer();
const origin = 'http://127.0.0.1:' + port;
const browser = await chromium.launch( {
	executablePath: process.env.CHROMIUM_PATH || undefined,
} );
const page = await browser.newPage();
const external = [];
page.context().on( 'request', ( r ) => {
	if ( ! r.url().startsWith( origin ) && ! r.url().startsWith( 'data:' ) && ! r.url().startsWith( 'blob:' ) ) {
		external.push( r.url() );
	}
} );
const consoleErrors = [];
page.on( 'pageerror', ( e ) => consoleErrors.push( String( e ) ) );
await page.goto( origin + '/plugin/tests/e2e/fixture-worker.html' );

const profile = { ...expected.profile, host: origin + '/hf' };
const init = { type: 'init', vendorUrl: origin + '/plugin/assets/vendor/transformers/', profile, device: 'wasm', wasmSuffix: '.asyncify', threads: 1 };

try {
	// 1. Happy path: load → self-test → embed.
	const t0 = Date.now();
	const run = await page.evaluate(
		( a ) => window.sseoRun( [ a.init, { type: 'selftest', id: 1, reference: a.reference }, { type: 'embed', id: 2, items: a.items } ] ),
		{ init, reference: expected.reference, items: expected.items }
	);
	const [ ready, self, emb ] = run.out;
	record( 'worker loads bundled runtime + fixture model on WASM', ready && ready.type === 'ready', ready && ( ready.type === 'ready' ? ready.device + ' ' + ready.ms + ' ms' : ready.message ) );
	record( 'self-test gate passes against NumPy reference', self && self.type === 'selftest' && self.ok, self && ( self.type === 'selftest' ? 'max Δ ' + self.maxDiff.toExponential( 2 ) : self.message ) );
	let maxDiff = 0;
	let normOk = true;
	if ( emb && emb.type === 'embedded' ) {
		for ( let i = 0; i < emb.results.length; i++ ) {
			const v = await page.evaluate( ( b ) => window.sseoDecode( b ), emb.results[ i ].vector );
			assert.equal( emb.results[ i ].queue_id, expected.items[ i ].queue_id );
			assert.equal( v.length, expected.profile.dimension );
			const n = Math.sqrt( v.reduce( ( s, x ) => s + x * x, 0 ) );
			normOk = normOk && Math.abs( n - 1 ) < 1e-5;
			v.forEach( ( x, j ) => {
				maxDiff = Math.max( maxDiff, Math.abs( x - expected.vectors[ i ][ j ] ) );
			} );
		}
	}
	record( 'embeddings (left padding, query instruction, MRL ' + expected.profile.dimension + ', base64) match NumPy', emb && emb.type === 'embedded' && maxDiff < 1e-4 && normOk, emb && ( emb.type === 'embedded' ? 'max |Δ| ' + maxDiff.toExponential( 2 ) + ', unit norm ' + normOk + ', ' + emb.ms + ' ms' : emb.message ) );
	record( 'model files downloaded with verification progress', run.progress.some( ( p ) => p.file === 'onnx/model.onnx' && p.loaded === p.total ), run.progress.length + ' progress events' );
	console.log( '  total ' + ( Date.now() - t0 ) + ' ms' );

	// 2. Second load is served from the verified cache.
	const again = await page.evaluate( ( a ) => window.sseoRun( [ a.init ] ), { init } );
	const cached = again.progress.filter( ( p ) => p.cached ).map( ( p ) => p.file );
	record( 'second load re-verifies files from the browser cache (no re-download)', again.out[ 0 ].type === 'ready' && cached.includes( 'onnx/model.onnx' ), cached.join( ', ' ) );

	// 3. Tampered model file is refused.
	const tamperedInit = { ...init, profile: { ...profile, revision: 'tampered' } };
	const bad = await page.evaluate( ( a ) => window.sseoRun( [ a.init ] ), { init: tamperedInit } );
	record( 'tampered model file is rejected (SHA-256 mismatch)', bad.out[ 0 ].type === 'error' && /Integrity check failed/.test( bad.out[ 0 ].message ), bad.out[ 0 ].message );

	// 4. Embedding is refused until the self-test passes; a failing self-test keeps it refused.
	const wrongRef = { ...expected.reference, scores: [ [ 0.1, 0.9 ], [ 0.9, 0.1 ] ] };
	const gate = await page.evaluate(
		( a ) => window.sseoRun( [ a.init, { type: 'embed', id: 1, items: a.items } ] ),
		{ init, items: expected.items.slice( 0, 1 ) }
	);
	record( 'embedding refused before self-test', gate.out[ 1 ].type === 'error' && /Self-test/.test( gate.out[ 1 ].message ), gate.out[ 1 ].message );
	const failing = await page.evaluate(
		( a ) => window.sseoRun( [ a.init, { type: 'selftest', id: 1, reference: a.ref }, { type: 'embed', id: 2, items: a.items } ] ),
		{ init, ref: wrongRef, items: expected.items.slice( 0, 1 ) }
	);
	record( 'failed self-test blocks embedding', failing.out[ 1 ].ok === false && failing.out[ 2 ].type === 'error', failing.out[ 2 ].message );

	// 5. Network isolation.
	const hfPaths = log.filter( ( p ) => p.startsWith( '/hf/' ) );
	const unexpected = hfPaths.filter( ( p ) => ! /\/(config\.json|tokenizer\.json|tokenizer_config\.json|onnx\/model\.onnx)$/.test( p ) );
	record( 'no request leaves the allow-list (no CDN, no unpinned files)', external.length === 0 && unexpected.length === 0, 'external: ' + external.length + ', unpinned model paths: ' + unexpected.join( ',' ) );
	record( 'no page errors', consoleErrors.length === 0, consoleErrors.join( ' | ' ) );
} catch ( e ) {
	record( 'harness', false, String( e && e.stack ) );
} finally {
	await browser.close();
	server.close();
}

const failed = results.filter( ( r ) => ! r.ok );
fs.mkdirSync( path.join( ROOT, 'tests/e2e/artifacts' ), { recursive: true } );
fs.writeFileSync( path.join( ROOT, 'tests/e2e/artifacts/worker-fixture.json' ), JSON.stringify( { when: new Date().toISOString(), browser: 'chromium', results }, null, 1 ) );
console.log( results.length - failed.length + '/' + results.length + ' passed' );
process.exit( failed.length ? 1 : 0 );
