/**
 * The beta.4 SEO dashboard in real Chromium, on a disposable WordPress site (never production),
 * driven only through the Vietnamese admin UI the way a site owner uses it: system check, the
 * read-only 20-page trial, the whole-site scan (paused and resumed), the priority list with
 * filters and CSV export, the device check of the optional model, an emulated phone, axe-core,
 * and an independent before/after snapshot of posts, meta and terms.
 *
 *   node tests/e2e/dashboard-e2e.mjs <site-dir>
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
const BASE = wp( 'option', 'get', 'siteurl' );
const SHOTS = path.join( ROOT, 'docs', 'screenshots' );
const AXE = fs.readFileSync( path.join( ROOT, 'node_modules', 'axe-core', 'axe.min.js' ), 'utf8' );
const DASH = BASE + '/wp-admin/admin.php?page=sseo';
const PRIO = BASE + '/wp-admin/admin.php?page=sseo-priorities';
const results = [];
const a11y = {};

function wp( ...args ) {
	return execFileSync( 'wp', [ '--allow-root', '--path=' + SITE, ...args ], { encoding: 'utf8' } ).trim();
}
function snapshot() {
	const s = JSON.parse( execFileSync( 'wp', [ '--allow-root', '--path=' + SITE, 'eval-file', path.join( ROOT, 'tests', 'e2e', 'snapshot.php' ) ], { encoding: 'utf8' } ) );
	return JSON.stringify( [ 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'roles', 'rewrite_rules' ].map( ( k ) => s[ k ] ) );
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
async function login( context ) {
	await context.route( 'https://secure.gravatar.com/**', ( r ) => r.fulfill( { status: 200, contentType: 'image/gif', body: Buffer.from( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64' ) } ) );
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
		if ( m.type() === 'error' && ! /favicon|status of 404/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text() );
		}
	} );
	page.on( 'dialog', ( d ) => d.accept() );
	return errors;
}
async function axe( page, key ) {
	await page.evaluate( AXE );
	a11y[ key ] = await page.evaluate( async () => {
		// eslint-disable-next-line no-undef
		const out = await axe.run( '.sseo-wrap', { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] }, resultTypes: [ 'violations' ] } );
		return out.violations.map( ( v ) => ( { id: v.id, impact: v.impact, nodes: v.nodes.length, sample: v.nodes[ 0 ] && v.nodes[ 0 ].target.join( ' ' ) } ) );
	} );
}
/** Waits while the dashboard reloads itself as the scan advances. */
async function waitFor( page, text, ms ) {
	const until = Date.now() + ms;
	while ( Date.now() < until ) {
		if ( ( await page.content() ).includes( text ) ) {
			return;
		}
		await page.waitForTimeout( 1500 );
		if ( ! ( await page.locator( '[data-sseo-job]' ).count() ) ) {
			await page.goto( DASH );
		}
	}
	throw new Error( 'timeout waiting for: ' + text );
}

const browser = await chromium.launch();
const t = {};
try {
	const ctx = await browser.newContext( { viewport: { width: 1366, height: 900 }, locale: 'vi-VN', acceptDownloads: true } );
	const page = await login( ctx );
	const errors = watch( page );
	fs.mkdirSync( path.join( SHOTS, 'desktop' ), { recursive: true } );
	fs.mkdirSync( path.join( SHOTS, 'mobile' ), { recursive: true } );

	await check( 'dashboard opens in Vietnamese: health banner and the 3 steps', async () => {
		await page.goto( DASH );
		const html = await page.content();
		assert.ok( html.includes( 'Bảng điều khiển SEO' ), 'title' );
		assert.ok( html.includes( 'Plugin hoạt động bình thường' ), 'health banner' );
		for ( const s of [ '1. Kiểm tra hệ thống', '2. Phân tích thử 20 bài', '3. Phân tích toàn website' ] ) {
			assert.ok( html.includes( s ), s );
		}
		await page.click( 'summary:has-text("Xem chi tiết kiểm tra hệ thống")' );
		const checks = await page.locator( '.sseo-checks li' ).count();
		assert.ok( checks >= 8, 'system check rows: ' + checks );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '20-dashboard-start.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		await axe( page, 'dashboard-start' );
		return checks + ' system checks shown';
	} );

	const before = snapshot();
	await check( '"Phân tích thử 20 bài" runs read-only and shows real results', async () => {
		const t0 = Date.now();
		await Promise.all( [ page.waitForNavigation(), page.click( 'button:has-text("Phân tích thử 20 bài")' ) ] );
		assert.ok( ( await page.content() ).includes( 'Đã bắt đầu phân tích thử' ), 'start notice' );
		await waitFor( page, 'Đã xác minh chỉ đọc', 240000 );
		t.trial = ( Date.now() - t0 ) / 1000;
		const html = await page.content();
		assert.ok( html.includes( 'Thời gian xử lý ước tính' ), 'estimate from the measured trial' );
		assert.ok( html.includes( 'Bộ nhớ đỉnh đo được' ), 'measured memory' );
		assert.equal( snapshot(), before, 'posts, meta, terms, users, roles and rewrite rules unchanged' );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '21-dashboard-trial-done.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		return 'trial done in ' + t.trial.toFixed( 1 ) + ' s (browser clock; the open page drives the scan, WP-Cron also enabled)';
	} );

	await check( 'whole-site scan: started, paused, resumed, finished; SEO health cards and work list shown', async () => {
		const t0 = Date.now();
		await Promise.all( [ page.waitForNavigation(), page.click( 'button:has-text("Phân tích toàn website")' ) ] );
		const pause = page.locator( 'button:has-text("Tạm dừng")' ).first();
		let paused = false;
		if ( await pause.count() ) {
			await Promise.all( [ page.waitForNavigation(), pause.click() ] );
			assert.ok( await page.locator( 'button:has-text("Tiếp tục")' ).count(), 'resume button while paused' );
			await page.screenshot( { path: path.join( SHOTS, 'desktop', '22-dashboard-paused.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
			await Promise.all( [ page.waitForNavigation(), page.click( 'button:has-text("Tiếp tục")' ) ] );
			paused = true;
		}
		await waitFor( page, 'Sức khỏe SEO', 300000 );
		await waitFor( page, 'Đây là lần quét toàn site đầu tiên', 60000 );
		t.full = ( Date.now() - t0 ) / 1000;
		const html = await page.content();
		for ( const s of [ 'Số trang đã phân tích', 'Vấn đề theo mức ưu tiên', 'Bài chưa có liên kết nội bộ trỏ tới', 'Bài có nguy cơ trùng từ khóa hoặc ý định tìm kiếm', 'Bài cần cải thiện chất lượng nội dung', 'Việc nên làm trước' ] ) {
			assert.ok( html.includes( s ), s );
		}
		assert.ok( await page.locator( 'table.sseo-priorities tbody tr' ).count() > 0, 'work list rows' );
		assert.equal( snapshot(), before, 'still nothing changed on the site' );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '23-dashboard-full-scan.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		await axe( page, 'dashboard-results' );
		return ( paused ? 'paused and resumed; ' : 'finished before the pause click; ' ) + 'done in ' + t.full.toFixed( 1 ) + ' s';
	} );

	await check( 'device check of the optional model downloads nothing and gives a verdict', async () => {
		const requests = [];
		page.on( 'request', ( r ) => requests.push( r.url() ) );
		await page.click( '#sseo-device-check' );
		await page.waitForFunction( () => document.querySelector( '#sseo-device-result' ).getAttribute( 'data-sseo-device' ) );
		const kind = await page.getAttribute( '#sseo-device-result', 'data-sseo-device' );
		const text = await page.textContent( '#sseo-device-result' );
		assert.ok( [ 'webgpu', 'wasm', 'none' ].includes( kind ) );
		assert.deepEqual( requests.filter( ( u ) => /onnx|huggingface|\.bin|transformers/.test( u ) ), [] );
		return kind + ': ' + text.slice( 0, 120 );
	} );

	await check( '"Việc nên làm trước": filters by type and language, CSV export', async () => {
		await page.goto( PRIO );
		const all = await page.locator( 'table.sseo-priorities tbody tr' ).count();
		await page.selectOption( '#sseo-category', 'links' );
		await Promise.all( [ page.waitForNavigation(), page.click( 'form.sseo-filters button' ) ] );
		const cats = await page.locator( 'table.sseo-priorities tbody tr td:first-child small' ).allTextContents();
		assert.ok( cats.length > 0 && cats.every( ( c ) => c.trim() === 'Liên kết nội bộ' ), 'only internal-link items: ' + [ ...new Set( cats ) ].join( '|' ) );
		await page.screenshot( { path: path.join( SHOTS, 'desktop', '24-priorities-links.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		await axe( page, 'priorities' );
		let langNote = 'one language on this site';
		const locales = await page.locator( '#sseo-locale option' ).evaluateAll( ( o ) => o.map( ( x ) => x.value ).filter( Boolean ) );
		if ( locales.includes( 'en' ) && locales.length > 1 ) {
			await page.selectOption( '#sseo-locale', 'en' );
			await Promise.all( [ page.waitForNavigation(), page.click( 'form.sseo-filters button' ) ] );
			const langs = await page.locator( 'table.sseo-priorities .sseo-lang' ).allTextContents();
			assert.ok( langs.every( ( l ) => l.trim() === 'EN' ), 'only English pages: ' + [ ...new Set( langs ) ].join( '|' ) );
			langNote = langs.length + ' English link items';
		}
		// The export follows the filters shown (type, and language when the site has two).
		const listed = Number( await page.getAttribute( 'p.sseo-total', 'data-total' ) );
		const [ dl ] = await Promise.all( [ page.waitForEvent( 'download' ), page.click( 'button:has-text("Tải CSV (Excel)")' ) ] );
		const file = await dl.path();
		const csv = fs.readFileSync( file, 'utf8' );
		assert.ok( csv.startsWith( '﻿priority,' ), 'BOM + header' );
		// Records, not lines: a quoted cell may contain a line break.
		let records = 0;
		let quoted = false;
		for ( const ch of csv ) {
			if ( ch === '"' ) {
				quoted = ! quoted;
			} else if ( ch === '\n' && ! quoted ) {
				records++;
			}
		}
		const lines = records - 1;
		assert.equal( lines, listed, 'csv rows ' + lines + ' = items listed with these filters ' + listed );
		return all + ' items on page 1 unfiltered; ' + cats.length + ' link items; ' + langNote + '; CSV ' + lines + ' rows (' + dl.suggestedFilename() + ')';
	} );

	await check( 'languages and plugins detected as on the blog (when run next to them)', async () => {
		const counts = JSON.parse( wp( 'eval', 'echo wp_json_encode( \\SemanticSEO\\Services\\HealthReport::counts()["by_language"] );', '--user=admin' ) );
		const fw = wp( 'eval', 'echo \\SemanticSEO\\Language\\LanguageResolver::framework();' );
		if ( process.env.EXPECT_VI_EN ) {
			assert.ok( counts.vi > 0 && counts.en > 0, 'VI and EN pages: ' + JSON.stringify( counts ) );
		}
		if ( process.env.EXPECT_FRAMEWORK ) {
			assert.equal( fw, process.env.EXPECT_FRAMEWORK );
		}
		if ( process.env.EXPECT_SEO ) {
			assert.equal( wp( 'eval', 'echo \\SemanticSEO\\Compatibility\\SeoAdapter::primary();' ), process.env.EXPECT_SEO );
		}
		return 'framework ' + fw + ', pages by language ' + JSON.stringify( counts ) + ', active plugins: ' + wp( 'plugin', 'list', '--status=active', '--field=name' ).split( '\n' ).join( ' ' );
	} );

	await check( 'no JavaScript errors on the dashboard pages', async () => {
		assert.deepEqual( errors, [] );
	} );

	const phone = await browser.newContext( { ...devices[ 'iPhone 13' ], locale: 'vi-VN' } );
	const m = await login( phone );
	const mErrors = watch( m );
	await check( 'emulated iPhone 13 (Chromium emulation, not a real iPhone): dashboard and work list fit the screen', async () => {
		const over = [];
		for ( const [ name, url ] of [ [ 'dashboard', DASH ], [ 'priorities', PRIO ] ] ) {
			await m.goto( url );
			const o = await m.evaluate( () => ( { doc: document.documentElement.scrollWidth, vw: window.innerWidth } ) );
			if ( o.doc > o.vw + 1 ) {
				over.push( name + ' ' + o.doc + '>' + o.vw );
			}
			await m.screenshot( { path: path.join( SHOTS, 'mobile', ( 'dashboard' === name ? '20' : '21' ) + '-' + name + '.jpg' ), type: 'jpeg', quality: 70, fullPage: true } );
		}
		assert.deepEqual( over, [] );
		assert.deepEqual( mErrors, [] );
		return '390 px wide, no horizontal page scroll';
	} );

	await check( 'axe-core: no critical or serious WCAG 2.1 A/AA violation on the new pages', async () => {
		const bad = [];
		for ( const [ key, list ] of Object.entries( a11y ) ) {
			for ( const v of list ) {
				if ( v.impact === 'critical' || v.impact === 'serious' ) {
					bad.push( key + ': ' + v.id + ' (' + v.nodes + ') ' + v.sample );
				}
			}
		}
		assert.deepEqual( bad, [] );
		return Object.values( a11y ).flat().length + ' moderate/minor findings';
	} );

	await check( 'no PHP warning or error from the plugin in debug.log', async () => {
		const log = path.join( SITE, 'wp-content', 'debug.log' );
		const lines = fs.existsSync( log ) ? fs.readFileSync( log, 'utf8' ).split( '\n' ).filter( ( l ) => /semantic-seo-engine-vi-en|SemanticSEO/.test( l ) ) : [];
		assert.deepEqual( lines.slice( 0, 3 ), [] );
	} );
} finally {
	await browser.close();
	const out = path.join( ROOT, 'tests', 'e2e', 'artifacts', 'dashboard-e2e.json' );
	fs.writeFileSync( out, JSON.stringify( { site: BASE, wordpress: wp( 'core', 'version' ), database: wp( 'db', 'query', 'SELECT VERSION()', '--skip-column-names' ), timings_s: t, when: new Date().toISOString(), a11y, checks: results }, null, 2 ) );
	const failed = results.filter( ( r ) => ! r.ok ).length;
	console.log( ( results.length - failed ) + '/' + results.length + ' checks passed' );
	process.exitCode = failed ? 1 : 0;
}
