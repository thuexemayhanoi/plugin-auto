/**
 * Browser UI tests in real Chromium against a disposable WordPress site prepared by
 * tests/e2e/prepare-ui-site.sh. Captures genuine screenshots of the 12 admin pages (desktop and an
 * emulated iPhone viewport), runs axe-core on the plugin UI, checks keyboard reachability, drives
 * the Human Mode change workflow (preview → approve → apply → rollback) through the UI, runs the
 * browser inference loop with the TEST-ONLY tiny fixture model (NOT Qwen3-Embedding), checks the
 * unsupported-device path and verifies that the front end never loads a model.
 *
 *   node tests/e2e/ui-e2e.mjs <site-dir>
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, devices } from 'playwright';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const SITE = process.argv[ 2 ];
const PASS = fs.readFileSync( path.join( SITE, '.admin-pass' ), 'utf8' ).trim();
const WPV = execFileSync( 'wp', [ '--allow-root', '--path=' + SITE, 'core', 'version' ], { encoding: 'utf8' } ).trim();
const BASE = execFileSync( 'wp', [ '--allow-root', '--path=' + SITE, 'option', 'get', 'siteurl' ], { encoding: 'utf8' } ).trim();
const SHOTS = path.join( ROOT, 'docs', 'screenshots' );
const AXE = fs.readFileSync( path.join( ROOT, 'node_modules', 'axe-core', 'axe.min.js' ), 'utf8' );
const PAGES = [ 'sseo', 'sseo-matrix', 'sseo-semantic', 'sseo-links', 'sseo-cannibalization', 'sseo-clusters', 'sseo-qa', 'sseo-related', 'sseo-jobs', 'sseo-agents', 'sseo-settings', 'sseo-audit' ];
const results = [];
const a11y = {};

function wp( ...args ) {
	return execFileSync( 'wp', [ '--allow-root', '--path=' + SITE, ...args ], { encoding: 'utf8' } ).trim();
}
async function check( name, fn ) {
	try {
		const detail = await fn();
		results.push( { name, ok: true, detail: detail || '' } );
		console.log( 'PASS ' + name + ( detail ? ' — ' + detail : '' ) );
	} catch ( e ) {
		results.push( { name, ok: false, detail: String( e && e.message ).slice( 0, 600 ) } );
		console.log( 'FAIL ' + name + ' — ' + String( e && e.message ).slice( 0, 600 ) );
	}
}
/**
 * Test-environment accommodations for third-party code, never for this plugin: Polylang built
 * from source ships without compiled admin JS, and the WordPress admin bar requests Gravatar,
 * which the sandbox network blocks.
 */
async function isolateThirdParty( context ) {
	await context.route( '**/wp-content/plugins/polylang/js/build/**', ( r ) => r.fulfill( { status: 200, contentType: 'text/javascript', body: '/* not built in test env */' } ) );
	await context.route( 'https://secure.gravatar.com/**', ( r ) => r.fulfill( { status: 200, contentType: 'image/gif', body: Buffer.from( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64' ) } ) );
}
async function login( context ) {
	await isolateThirdParty( context );
	const page = await context.newPage();
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	return page;
}
function watch( page ) {
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! /favicon|Failed to load resource: the server responded with a status of 404/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text() );
		}
	} );
	page.on( 'dialog', ( d ) => d.accept() );
	return errors;
}
const phpNoise = ( html ) => /(<b>(Warning|Notice|Deprecated|Fatal error)<\/b>|Fatal error:|Warning: [a-z_]+\(|Notice: )/.test( html );

const browser = await chromium.launch();
try {
	// ---------------------------------------------------------------- Desktop pages + axe + screenshots.
	const desktop = await browser.newContext( { viewport: { width: 1366, height: 900 }, locale: 'vi-VN' } );
	const page = await login( desktop );
	const errors = watch( page );
	fs.mkdirSync( path.join( SHOTS, 'desktop' ), { recursive: true } );
	fs.mkdirSync( path.join( SHOTS, 'mobile' ), { recursive: true } );

	await check( 'all 12 admin pages render (desktop) without PHP notices or JS errors', async () => {
		const timings = [];
		for ( const [ i, slug ] of PAGES.entries() ) {
			const t0 = Date.now();
			const res = await page.goto( BASE + '/wp-admin/admin.php?page=' + slug );
			timings.push( slug + ' ' + ( Date.now() - t0 ) + 'ms' );
			assert.equal( res.status(), 200, slug );
			const html = await page.content();
			assert.ok( html.includes( 'sseo-wrap' ), slug + ' wrap' );
			assert.ok( ! phpNoise( html ), slug + ' PHP notice in output' );
			await page.screenshot( { path: path.join( SHOTS, 'desktop', String( i + 1 ).padStart( 2, '0' ) + '-' + slug + '.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
			await page.evaluate( AXE );
			const r = await page.evaluate( async () => {
				// eslint-disable-next-line no-undef
				const out = await axe.run( '.sseo-wrap', { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] }, resultTypes: [ 'violations' ] } );
				return out.violations.map( ( v ) => ( { id: v.id, impact: v.impact, nodes: v.nodes.length, sample: v.nodes[ 0 ] && v.nodes[ 0 ].target.join( ' ' ) } ) );
			} );
			a11y[ slug ] = r;
		}
		assert.deepEqual( errors, [] );
		return timings.join( ', ' );
	} );

	await check( 'axe-core: no critical or serious WCAG 2.1 A/AA violations inside the plugin UI', async () => {
		const bad = [];
		for ( const [ slug, list ] of Object.entries( a11y ) ) {
			for ( const v of list ) {
				if ( v.impact === 'critical' || v.impact === 'serious' ) {
					bad.push( slug + ': ' + v.id + ' (' + v.nodes + ') ' + v.sample );
				}
			}
		}
		assert.deepEqual( bad, [] );
		const minor = Object.values( a11y ).flat().length;
		return minor + ' moderate/minor findings recorded';
	} );

	await check( 'admin UI is in Vietnamese by default', async () => {
		await page.goto( BASE + '/wp-admin/admin.php?page=sseo-audit' );
		const h1 = await page.textContent( 'h1.sseo-title' );
		assert.equal( h1.trim(), 'Nhật ký / Hoàn tác' );
		return h1.trim();
	} );

	await check( 'keyboard: every inference control is reachable with Tab and shows a focus indicator', async () => {
		await page.goto( BASE + '/wp-admin/admin.php?page=sseo-jobs' );
		await page.focus( 'h1.sseo-title' ).catch( () => {} );
		const seen = new Set();
		let visible = true;
		for ( let i = 0; i < 160 && seen.size < 3; i++ ) {
			await page.keyboard.press( 'Tab' );
			const info = await page.evaluate( () => {
				const el = document.activeElement;
				if ( ! el ) {
					return null;
				}
				const cs = getComputedStyle( el );
				return { key: el.getAttribute( 'data-sseo-action' ) || ( el.hasAttribute( 'data-sseo-consent' ) ? 'consent' : '' ), outline: cs.outlineStyle !== 'none' || cs.boxShadow !== 'none' };
			} );
			if ( info && info.key ) {
				seen.add( info.key );
				visible = visible && info.outline;
			}
		}
		assert.ok( seen.has( 'consent' ) && seen.has( 'detect' ) && seen.has( 'load' ), [ ...seen ].join( ',' ) );
		assert.ok( visible, 'focus indicator' );
		return [ ...seen ].join( ', ' );
	} );

	// ---------------------------------------------------------------- Human Mode workflow through the UI.
	await check( 'Human Mode via UI: create preview → approve (with confirmation) → apply (readback) → rollback restores content', async () => {
		await page.goto( BASE + '/wp-admin/admin.php?page=sseo-links' );
		await Promise.all( [ page.waitForNavigation(), page.click( 'form:has(input[name="action"][value="sseo_create_preview"]) button' ) ] );
		assert.ok( await page.isVisible( '.notice-success' ), 'preview notice' );
		const proposal = JSON.parse( wp( 'eval', 'global $wpdb; echo wp_json_encode( $wpdb->get_row( "SELECT id, object_id FROM {$wpdb->prefix}sseo_proposals ORDER BY id DESC LIMIT 1", ARRAY_A ) );' ) );
		const before = wp( 'post', 'get', proposal.object_id, '--field=post_content' );
		await page.goto( BASE + '/wp-admin/admin.php?page=sseo-audit&tab=proposals' );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '13-audit-proposal-diff.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		const form = page.locator( 'form.sseo-approve' ).first();
		await form.locator( 'input[name="confirm"]' ).check();
		await Promise.all( [ page.waitForNavigation(), form.locator( 'button' ).click() ] );
		assert.ok( await page.isVisible( '.notice-success' ), 'approved notice' );
		await page.goto( BASE + '/wp-admin/admin.php?page=sseo-audit&tab=proposals&status=approved' );
		await Promise.all( [ page.waitForNavigation(), page.click( 'form:has(input[name="action"][value="sseo_apply"]) button' ) ] );
		assert.ok( await page.isVisible( '.notice-success' ), 'applied notice: ' + ( await page.textContent( '.notice' ).catch( () => '' ) ) );
		const after = wp( 'post', 'get', proposal.object_id, '--field=post_content' );
		assert.notEqual( after, before );
		assert.equal( ( after.match( /<a /g ) || [] ).length, ( before.match( /<a /g ) || [] ).length + 1, 'exactly one link added' );
		await page.goto( BASE + '/wp-admin/admin.php?page=sseo-audit&tab=changes' );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '14-audit-applied-change.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		const rb = page.locator( 'form:has(input[name="action"][value="sseo_rollback"])' ).first();
		await rb.locator( 'input[name="confirm"]' ).check();
		await Promise.all( [ page.waitForNavigation(), rb.locator( 'button' ).click() ] );
		assert.ok( await page.isVisible( '.notice-success' ), 'rollback notice' );
		assert.equal( wp( 'post', 'get', proposal.object_id, '--field=post_content' ), before );
		return 'post #' + proposal.object_id + ' restored byte-identical';
	} );

	// ---------------------------------------------------------------- Browser inference (fixture model).
	await check( 'browser inference loop on the Jobs page (TEST FIXTURE model, WASM): load → self-test → pause/resume → page reload releases the lease → stop → finish', async () => {
		const key = wp( 'eval', 'echo \\SemanticSEO\\Semantic\\ModelProfile::active()["model_key"];' );
		const q = ( where ) => Number( wp( 'db', 'query', "SELECT COUNT(*) FROM wp_sseo_queue WHERE model_key = '" + key + "'" + where, '--skip-column-names' ) );
		const queued = q( '' );
		assert.ok( queued > 0, 'queue filled' );
		// Slow uploads down (300 ms each) so pause/stop/reload happen mid-run on this tiny model.
		await page.route( '**/semantic-seo/v1/embeddings/complete', async ( r ) => {
			await new Promise( ( res ) => setTimeout( res, 300 ) );
			await r.continue().catch( () => {} ); // The page may have been reloaded meanwhile.
		} );
		const batchLines = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-sseo-log] p' ) ].filter( ( p ) => /tổng|total/.test( p.textContent ) ).length );
		const waitBatches = ( n ) => page.waitForFunction( ( k ) => [ ...document.querySelectorAll( '[data-sseo-log] p' ) ].filter( ( p ) => /tổng|total/.test( p.textContent ) ).length >= k, n, { timeout: 60000 } );
		const waitNoLease = async () => {
			for ( let i = 0; i < 40 && q( " AND status = 'leased'" ) > 0; i++ ) {
				await page.waitForTimeout( 250 );
			}
			return q( " AND status = 'leased'" );
		};
		const load = async () => {
			await page.goto( BASE + '/wp-admin/admin.php?page=sseo-jobs' );
			await page.click( '[data-sseo-action="detect"]' );
			await page.waitForFunction( () => document.querySelector( '[data-sseo-device-facts]' ).children.length > 0 );
			await page.check( '[data-sseo-consent]' );
			await page.click( '[data-sseo-action="load"]' );
			await page.waitForFunction( () => ! document.querySelector( '[data-sseo-action="start"]' ).disabled, null, { timeout: 120000 } );
		};
		const steps = [];
		await load();
		const selftest = await page.textContent( '[data-sseo-log]' );
		assert.match( selftest, /Tự kiểm thử đạt|Self-test passed/ );

		// Pause: the batch in flight is saved, nothing stays leased, work can resume.
		await page.click( '[data-sseo-action="start"]' );
		await waitBatches( 1 );
		await page.click( '[data-sseo-action="pause"]' );
		await page.waitForTimeout( 1200 );
		const pausedDone = q( " AND status = 'done'" );
		assert.equal( await waitNoLease(), 0, 'no lease left after pause' );
		assert.ok( pausedDone > 0 && pausedDone < queued, 'paused mid-run: ' + pausedDone + '/' + queued );
		await page.waitForTimeout( 1000 );
		assert.equal( q( " AND status = 'done'" ), pausedDone, 'nothing processed while paused' );
		steps.push( 'paused at ' + pausedDone );

		// Resume, then reload the page while an upload is in flight: pagehide releases the lease
		// immediately (the token had already been handed to the upload request).
		const uploading = page.waitForRequest( ( r ) => r.url().includes( '/embeddings/complete' ) );
		await page.click( '[data-sseo-action="start"]' );
		await uploading;
		await page.reload();
		assert.equal( await waitNoLease(), 0, 'lease released on page unload during an upload' );
		steps.push( 'reload during upload released the lease at ' + q( " AND status = 'done'" ) );

		// If the tab closes while a *claim* is in flight the browser never learns the token; those
		// items return to the queue when the 180 s lease expires. Simulate the expiry clock.
		await load();
		const claiming = page.waitForRequest( ( r ) => r.url().includes( '/embeddings/claim' ) );
		await page.click( '[data-sseo-action="start"]' );
		await claiming;
		await page.reload();
		await page.waitForTimeout( 1500 );
		const raced = q( " AND status = 'leased'" );
		wp( 'db', 'query', "UPDATE wp_sseo_queue SET lease_expires = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE status = 'leased'" );
		wp( 'eval', '\\SemanticSEO\\Semantic\\EmbeddingQueue::expire_leases();' );
		assert.equal( q( " AND status = 'leased'" ), 0, 'expired leases return to the queue' );
		steps.push( 'reload during claim: ' + raced + ' item(s) waited for lease expiry' );

		// Load again, start, stop (releases the claimed batch), start again and finish.
		await load();
		await page.click( '[data-sseo-action="start"]' );
		await waitBatches( 1 );
		await page.click( '[data-sseo-action="cancel"]' );
		assert.equal( await waitNoLease(), 0, 'stop releases the lease' );
		steps.push( 'stopped at ' + q( " AND status = 'done'" ) );
		await page.waitForFunction( () => ! document.querySelector( '[data-sseo-action="start"]' ).disabled );
		await page.click( '[data-sseo-action="start"]' );
		await page.waitForFunction( () => /Hàng đợi trống|Queue empty/.test( document.querySelector( '[data-sseo-log]' ).textContent ), null, { timeout: 180000 } );
		await page.unroute( '**/semantic-seo/v1/embeddings/complete' );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '15-jobs-inference-fixture.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		const stored = Number( wp( 'db', 'query', "SELECT COUNT(*) FROM wp_sseo_embeddings WHERE model_key = '" + key + "'", '--skip-column-names' ) );
		assert.equal( stored, queued );
		assert.equal( q( " AND status <> 'done'" ), 0 );
		return stored + '/' + queued + ' vectors stored; ' + steps.join( '; ' );
	} );

	await check( 'unsupported device (no WebAssembly, no WebGPU): clear message, model loading disabled, lexical pages still work', async () => {
		const ctx = await browser.newContext( { viewport: { width: 1366, height: 900 } } );
		const p = await login( ctx );
		await p.addInitScript( () => {
			delete window.WebAssembly;
			Object.defineProperty( navigator, 'gpu', { value: undefined, configurable: true } );
		} );
		await p.goto( BASE + '/wp-admin/admin.php?page=sseo-jobs' );
		await p.click( '[data-sseo-action="detect"]' );
		await p.waitForFunction( () => document.querySelector( '[data-sseo-device-facts]' ).children.length > 0 );
		const status = await p.textContent( '[data-sseo-device-status]' );
		assert.match( status, /không hỗ trợ|Unsupported/ );
		assert.equal( await p.isDisabled( '[data-sseo-action="load"]' ), true );
		const r = await p.goto( BASE + '/wp-admin/admin.php?page=sseo-cannibalization' );
		assert.equal( r.status(), 200 );
		await ctx.close();
		return status.trim();
	} );

	// ---------------------------------------------------------------- Mobile.
	const iphone = await browser.newContext( { ...devices[ 'iPhone 13' ], locale: 'vi-VN' } );
	const m = await login( iphone );
	const mErrors = watch( m );
	await check( 'emulated iPhone 13 viewport (Chromium emulation, not a real iPhone): 12 pages, no horizontal page overflow', async () => {
		const over = [];
		for ( const [ i, slug ] of PAGES.entries() ) {
			await m.goto( BASE + '/wp-admin/admin.php?page=' + slug );
			const o = await m.evaluate( () => ( { doc: document.documentElement.scrollWidth, vw: window.innerWidth } ) );
			if ( o.doc > o.vw + 1 ) {
				over.push( slug + ' ' + o.doc + '>' + o.vw );
			}
			await m.screenshot( { path: path.join( SHOTS, 'mobile', String( i + 1 ).padStart( 2, '0' ) + '-' + slug + '.jpg' ), type: 'jpeg', quality: 70, scale: 'css' } );
		}
		assert.deepEqual( over, [] );
		assert.deepEqual( mErrors, [] );
		return '390px wide';
	} );
	await check( 'iOS detection on the inference panel (emulated UA) warns about speed/memory and screen lock', async () => {
		await m.goto( BASE + '/wp-admin/admin.php?page=sseo-jobs' );
		await m.click( '[data-sseo-action="detect"]' );
		await m.waitForFunction( () => document.querySelector( '[data-sseo-device-facts]' ).children.length > 0 );
		const facts = await m.textContent( '[data-sseo-device-facts]' );
		assert.match( facts, /iOS/ );
		return facts.replace( /\s+/g, ' ' ).slice( 0, 200 );
	} );

	// ---------------------------------------------------------------- Front end.
	await check( 'front end never loads a model, worker, admin script or third-party request (related block enabled)', async () => {
		wp( 'eval', '\\SemanticSEO\\Settings::update( array( "related_enabled" => true ) );' );
		const map = JSON.parse( fs.readFileSync( path.join( SITE, '.corpus-map.json' ), 'utf8' ) );
		const id = String( map[ 'vi-pillar' ] );
		const content = wp( 'post', 'get', id, '--field=post_content' );
		wp( 'post', 'update', id, '--post_content=' + content + '\n<!-- wp:shortcode -->[sseo_related]<!-- /wp:shortcode -->' );
		const ctx = await browser.newContext();
		await isolateThirdParty( ctx );
		const p = await ctx.newPage();
		const reqs = [];
		ctx.on( 'request', ( r ) => reqs.push( r.url() ) );
		for ( const u of [ '/', '/thue-xe-may-ha-noi/', '/category/thue-xe-may/', '/?s=xe' ] ) {
			await p.goto( BASE + u );
		}
		const html = await p.content();
		await ctx.close();
		const bad = reqs.filter( ( u ) => ! ( u.startsWith( BASE ) || u.startsWith( 'blob:' + BASE ) ) || /huggingface|transformers|ort-wasm|ai-worker|inference\.js|admin\.js|\.onnx/.test( u ) );
		assert.deepEqual( bad, [] );
		const related = await ( async () => {
			const c2 = await browser.newContext();
			const p2 = await c2.newPage();
			await p2.goto( BASE + '/thue-xe-may-ha-noi/' );
			const n = await p2.locator( '.sseo-related li' ).count();
			await p2.screenshot( { path: path.join( SHOTS, 'desktop', '16-frontend-related-block.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
			await c2.close();
			return n;
		} )();
		assert.ok( related > 0, 'related list rendered' );
		return reqs.length + ' requests, all same-origin; related items: ' + related + ( html ? '' : '' );
	} );

	await check( 'no PHP notices from the plugin in debug.log during the UI session', async () => {
		const log = fs.existsSync( path.join( SITE, 'wp-content', 'debug.log' ) ) ? fs.readFileSync( path.join( SITE, 'wp-content', 'debug.log' ), 'utf8' ) : '';
		const mine = log.split( '\n' ).filter( ( l ) => /semantic-seo-engine-vi-en|SemanticSEO/.test( l ) );
		assert.deepEqual( mine, [] );
	} );
} finally {
	await browser.close();
}

const failed = results.filter( ( r ) => ! r.ok );
fs.mkdirSync( path.join( ROOT, 'tests/e2e/artifacts' ), { recursive: true } );
fs.writeFileSync( path.join( ROOT, 'tests/e2e/artifacts/ui-' + WPV + '.json' ), JSON.stringify( { wordpress: WPV, browser: 'chromium (Playwright)', when: new Date().toISOString(), results, axe: a11y }, null, 1 ) );
console.log( results.length - failed.length + '/' + results.length + ' passed on WordPress ' + WPV );
process.exit( failed.length ? 1 : 0 );
