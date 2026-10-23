/**
 * HTTP-level Agent Mode and security tests against a disposable WordPress site prepared by
 * tests/e2e/prepare-agent-site.sh (real Application Passwords, real REST server, real Abilities API,
 * official MCP Adapter). Never point this at a production site.
 *
 *   node tests/e2e/http-agent.mjs <site-dir>
 *
 * Writes tests/e2e/artifacts/http-agent-<wp>.json (credentials are never written).
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const SITE = process.argv[ 2 ];
const creds = JSON.parse( fs.readFileSync( path.join( SITE, '.agents.json' ), 'utf8' ) );
const BASE = creds.url;
const NS = BASE + '/wp-json/semantic-seo/v1';
const SECRETS = Object.values( creds )
	.filter( ( c ) => c && typeof c === 'object' )
	.flatMap( ( c ) => [ c.app, c.password ] )
	.filter( Boolean );
const results = [];

function wp( ...args ) {
	return execFileSync( 'wp', [ '--allow-root', '--path=' + SITE, ...args ], { encoding: 'utf8' } ).trim();
}

function auth( who ) {
	if ( ! who ) {
		return {};
	}
	const c = creds[ who ];
	return { Authorization: 'Basic ' + Buffer.from( c.user + ':' + c.app ).toString( 'base64' ) };
}

async function call( who, method, url, body, extra = {} ) {
	const res = await fetch( url.startsWith( 'http' ) ? url : NS + url, {
		method,
		headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...auth( who ), ...extra },
		body: body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify( body ),
	} );
	const text = await res.text();
	let json = null;
	try {
		json = JSON.parse( text );
	} catch ( e ) {
		json = null;
	}
	for ( const s of SECRETS ) {
		if ( text.includes( s ) ) {
			throw new Error( 'SECRET LEAKED in response of ' + method + ' ' + url );
		}
	}
	return { status: res.status, json, text, headers: res.headers };
}

async function check( name, fn ) {
	try {
		const detail = await fn();
		results.push( { name, ok: true, detail: detail || '' } );
		console.log( 'PASS ' + name + ( detail ? ' — ' + detail : '' ) );
	} catch ( e ) {
		results.push( { name, ok: false, detail: String( e && e.message ) } );
		console.log( 'FAIL ' + name + ' — ' + ( e && e.message ) );
	}
}

const wpVersion = wp( 'core', 'version' );
const hasAbilities = wp( 'eval', 'echo function_exists("wp_register_ability") ? 1 : 0;' ) === '1';
const hasMcp = ( () => {
	try {
		wp( 'plugin', 'is-active', 'mcp-adapter' );
		return true;
	} catch ( e ) {
		return false;
	}
} )();

// --- Authentication & authorization -------------------------------------------------------------
await check( 'guest is refused (401) on every read route', async () => {
	for ( const r of [ '/status', '/inventory', '/cannibalization', '/audit', '/jobs' ] ) {
		const res = await call( null, 'GET', r );
		assert.equal( res.status, 401, r + ' → ' + res.status );
		// WordPress core formats permission_callback failures ({code, message, data.status}).
		assert.ok( res.json && ( res.json.code || res.json.ok === false ), res.text.slice( 0, 120 ) );
	}
	return '5 routes';
} );

await check( 'wrong Application Password is refused', async () => {
	const res = await fetch( NS + '/status', { headers: { Authorization: 'Basic ' + Buffer.from( 'agent_reader:wrong wrong wrong wrong' ).toString( 'base64' ) } } );
	assert.ok( [ 401, 403 ].includes( res.status ), String( res.status ) );
	return String( res.status );
} );

await check( 'cookie session without REST nonce is treated as a guest', async () => {
	const res = await fetch( NS + '/status', { headers: { Cookie: 'wordpress_logged_in_x=forged' } } );
	assert.equal( res.status, 401 );
} );

await check( 'reader (Application Password) reads status, inventory with pagination, URL analysis', async () => {
	const st = await call( 'reader', 'GET', '/status' );
	assert.equal( st.status, 200 );
	assert.equal( st.json.ok, true );
	assert.equal( st.json.data.mode.read_only_default, true );
	const inv = await call( 'reader', 'GET', '/inventory?limit=5' );
	assert.equal( inv.status, 200 );
	assert.equal( inv.json.data.items.length, 5 );
	assert.ok( inv.json.data.next_cursor, 'next_cursor present' );
	const page2 = await call( 'reader', 'GET', '/inventory?limit=5&cursor=' + encodeURIComponent( inv.json.data.next_cursor ) );
	assert.equal( page2.status, 200 );
	assert.notEqual( page2.json.data.items[ 0 ].document_id, inv.json.data.items[ 0 ].document_id );
	const one = await call( 'reader', 'GET', '/analysis?url=' + encodeURIComponent( BASE + '/thue-xe-may-ha-noi/' ) );
	assert.equal( one.status, 200, one.text.slice( 0, 200 ) );
	assert.ok( one.json.data.provenance && one.json.data.facts && one.json.data.inferences );
	return 'total ' + inv.json.data.total;
} );

await check( 'schema validation rejects invalid parameters (400)', async () => {
	const bad = await call( 'reader', 'GET', '/inventory?limit=1000' );
	assert.equal( bad.status, 400 );
	const bad2 = await call( 'reader', 'GET', '/cannibalization?locale=vietnamese' );
	assert.equal( bad2.status, 400 );
	return bad.json.error ? bad.json.error.code : '';
} );

await check( 'reader cannot start jobs, preview, apply or claim embeddings (403)', async () => {
	assert.equal( ( await call( 'reader', 'POST', '/jobs', { type: 'analyze' } ) ).status, 403 );
	assert.equal( ( await call( 'reader', 'POST', '/changes/preview', { finding_ids: [ 1 ] } ) ).status, 403 );
	assert.equal( ( await call( 'reader', 'POST', '/changes/apply', { proposal_id: 1, approval_id: 1, idempotency_key: 'reader-12345' } ) ).status, 403 );
	assert.equal( ( await call( 'reader', 'POST', '/embeddings/claim', { limit: 2 } ) ).status, 403 );
} );

await check( 'WordPress editor without plugin capabilities is refused', async () => {
	assert.equal( ( await call( 'editor', 'GET', '/status' ) ).status, 403 );
} );

let previewId = 0;
await check( 'analyst starts a bounded job; duplicate active job is refused; agent quota enforced', async () => {
	const a = await call( 'analyst', 'POST', '/jobs', { type: 'analyze', post_ids: [ 1, 2, 3 ], idempotency_key: 'e2e-job-1' } );
	assert.ok( [ 200, 201 ].includes( a.status ), a.text.slice( 0, 300 ) );
	const again = await call( 'analyst', 'POST', '/jobs', { type: 'analyze', post_ids: [ 1, 2, 3 ], idempotency_key: 'e2e-job-1' } );
	assert.equal( again.json.data.id, a.json.data.id, 'idempotent' );
	const other = await call( 'analyst', 'POST', '/jobs', { type: 'analyze', idempotency_key: 'e2e-job-2' } );
	assert.ok( [ 409, 429 ].includes( other.status ), 'second active job: ' + other.status );
	await call( 'analyst', 'POST', '/jobs/' + a.json.data.id + '/cancel' );
	return 'job #' + a.json.data.id + ', duplicate → ' + other.status;
} );

const contentHash = () => wp( 'db', 'query', 'SELECT MD5(GROUP_CONCAT(post_content ORDER BY ID)) FROM wp_posts', '--skip-column-names' );
await check( 'analyst can create an exact-diff preview (proposal), which writes nothing', async () => {
	const links = await call( 'analyst', 'GET', '/suggestions/links?limit=50' );
	assert.equal( links.status, 200, links.text.slice( 0, 200 ) );
	const f = links.json.data.items.find( ( x ) => JSON.stringify( x.evidence || {} ).includes( '"offset"' ) );
	assert.ok( f, 'anchored suggestion among ' + links.json.data.items.length );
	const before = contentHash();
	const p = await call( 'analyst', 'POST', '/changes/preview', { finding_ids: [ Number( f.id ) ] } );
	assert.equal( p.status, 200, p.text.slice( 0, 300 ) );
	const prop = p.json.data.previews[ 0 ].proposal;
	assert.ok( prop, JSON.stringify( p.json.data.previews[ 0 ] ).slice( 0, 300 ) );
	assert.equal( prop.diff_hash.length, 64 );
	previewId = prop.id;
	assert.equal( contentHash(), before );
	return 'proposal #' + prop.id + ', diff_hash ' + prop.diff_hash.slice( 0, 12 );
} );

await check( 'no REST route exists to approve (agents can never approve)', async () => {
	for ( const r of [ '/changes/approve', '/approvals', '/changes/' + ( previewId || 1 ) + '/approve' ] ) {
		const res = await call( 'operator', 'POST', r, { proposal_id: previewId || 1 } );
		assert.equal( res.status, 404, r + ' → ' + res.status );
	}
	assert.equal( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_sseo_approvals', '--skip-column-names' ), '0' );
} );

await check( 'operator cannot apply while "agents may apply" is off; forged approval refused', async () => {
	const res = await call( 'operator', 'POST', '/changes/apply', { proposal_id: previewId || 1, approval_id: 999, idempotency_key: 'forged-approval-1' } );
	assert.equal( res.status, 403, res.text.slice( 0, 200 ) );
} );

await check( 'administrator over Application Password is treated as an agent (cannot apply/approve)', async () => {
	const res = await call( 'admin', 'POST', '/changes/apply', { proposal_id: previewId || 1, approval_id: 1, idempotency_key: 'admin-app-pass-1' } );
	assert.equal( res.status, 403, res.text.slice( 0, 200 ) );
	const st = await call( 'admin', 'GET', '/status' );
	assert.equal( st.status, 200 );
} );

await check( 'with "agents may apply" on, operator still needs a human approval (forged/unknown IDs refused)', async () => {
	wp( 'eval', '\\SemanticSEO\\Settings::update( array( "agents_may_apply" => true ) );' );
	const res = await call( 'operator', 'POST', '/changes/apply', { proposal_id: previewId || 1, approval_id: 4242, idempotency_key: 'operator-forged-1' } );
	wp( 'eval', '\\SemanticSEO\\Settings::update( array( "agents_may_apply" => false ) );' );
	assert.ok( [ 403, 404, 409 ].includes( res.status ), String( res.status ) );
	assert.equal( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_sseo_changes', '--skip-column-names' ), '0' );
	return String( res.status ) + ' ' + ( res.json && res.json.error ? res.json.error.code : '' );
} );

// --- Hostile input ---------------------------------------------------------------------------------
await check( 'SSRF: external or metadata URLs are not fetched and not accepted as targets', async () => {
	for ( const u of [ 'http://169.254.169.254/latest/meta-data/', 'http://localhost:22/', 'file:///etc/passwd', 'https://evil.example/x' ] ) {
		const res = await call( 'reader', 'GET', '/analysis?url=' + encodeURIComponent( u ) );
		assert.ok( [ 400, 404 ].includes( res.status ), u + ' → ' + res.status );
	}
} );

await check( 'SQL injection strings are inert', async () => {
	const res = await call( 'reader', 'GET', '/inventory?search=' + encodeURIComponent( "' OR 1=1 -- " ) + '&limit=5' );
	assert.equal( res.status, 200 );
	assert.equal( res.json.data.items.length, 0 );
	const res2 = await call( 'reader', 'GET', '/inventory?sort=' + encodeURIComponent( 'id;DROP TABLE wp_posts' ) );
	assert.equal( res2.status, 400 );
	assert.ok( Number( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_posts', '--skip-column-names' ) ) > 0 );
} );

await check( 'XSS payloads are returned as JSON-escaped data, never as HTML', async () => {
	const res = await call( 'reader', 'GET', '/inventory?search=' + encodeURIComponent( '<script>alert(1)</script>' ) );
	assert.equal( res.status, 200 );
	assert.match( res.headers.get( 'content-type' ), /application\/json/ );
	assert.equal( res.headers.get( 'x-content-type-options' ), 'nosniff' );
	// The search term is sanitized (tags stripped) and never reflected as markup.
	assert.ok( ! res.text.includes( '<script>alert(1)</script>' ) );
} );

await check( 'oversized payload is rejected (413)', async () => {
	const big = JSON.stringify( { finding_ids: [ 1 ], pad: 'x'.repeat( 600 * 1024 ) } );
	const res = await call( 'analyst', 'POST', '/changes/preview', big );
	assert.equal( res.status, 413 );
} );

// --- Embedding worker protocol --------------------------------------------------------------------
await check( 'embedding lease: claim → wrong model rejected → replay rejected → release', async () => {
	const prof = await call( 'worker', 'GET', '/embeddings/profile' );
	assert.equal( prof.status, 200 );
	const key = prof.json.data.model_key || prof.json.data.profile.model_key;
	wp( 'sseo', 'start', 'embed', '--user=admin' );
	for ( let i = 0; i < 3; i++ ) {
		wp( 'eval', '\\SemanticSEO\\Jobs\\JobRunner::tick( 15 );' );
	}
	const claim = await call( 'worker', 'POST', '/embeddings/claim', { limit: 2 } );
	assert.equal( claim.status, 200, claim.text.slice( 0, 200 ) );
	if ( ! claim.json.data.items.length ) {
		return 'queue empty';
	}
	const token = claim.json.data.lease_token;
	assert.match( token, /^[a-f0-9]{48}$/ );
	assert.equal( wp( 'db', 'query', "SELECT COUNT(*) FROM wp_sseo_queue WHERE lease_hash = '" + token + "'", '--skip-column-names' ), '0', 'raw token never stored' );
	const wrong = await call( 'worker', 'POST', '/embeddings/complete', { lease_token: token, model_key: 'a'.repeat( 40 ), results: [ { queue_id: claim.json.data.items[ 0 ].queue_id, vector: [ 1 ] } ] } );
	assert.equal( wrong.status, 409 );
	const bad = await call( 'worker', 'POST', '/embeddings/complete', { lease_token: token, model_key: key, results: [ { queue_id: claim.json.data.items[ 0 ].queue_id, vector: [ 1, 2, 3 ] } ] } );
	assert.equal( bad.status, 200 );
	assert.equal( bad.json.data.stored, 0 );
	const replay = await call( 'worker', 'POST', '/embeddings/complete', { lease_token: token, model_key: key, results: [ { queue_id: claim.json.data.items[ 0 ].queue_id, vector: [ 1, 2, 3 ] } ] } );
	assert.equal( replay.json.data.stored, 0 );
	assert.match( replay.json.data.rejected[ 0 ].reason, /lease invalid|already used/ );
	const rel = await call( 'worker', 'POST', '/embeddings/release', { lease_token: token } );
	assert.equal( rel.status, 200 );
	const other = await call( 'analyst', 'POST', '/embeddings/claim', { limit: 1 } );
	assert.equal( other.status, 403 );
	return claim.json.data.items.length + ' items leased';
} );

await check( 'rate limiting returns 429 with Retry-After', async () => {
	wp( 'eval', '\\SemanticSEO\\Settings::update( array( "rate_analyze" => 3 ) );' );
	let last = null;
	for ( let i = 0; i < 8; i++ ) {
		last = await call( 'analyst', 'POST', '/jobs', { type: 'verify_links', idempotency_key: 'rate-' + i + '-' + Date.now() } );
		if ( last.status === 429 && last.headers.get( 'retry-after' ) ) {
			break;
		}
	}
	wp( 'eval', '\\SemanticSEO\\Settings::update( array( "rate_analyze" => 10 ) );' );
	assert.equal( last.status, 429 );
	assert.ok( Number( last.headers.get( 'retry-after' ) ) > 0 );
	return 'Retry-After ' + last.headers.get( 'retry-after' );
} );

await check( 'audit log records agent actions without secrets; agents only see their own entries', async () => {
	const res = await call( 'analyst', 'GET', '/audit?limit=50' );
	assert.equal( res.status, 200 );
	const users = new Set( res.json.data.items.map( ( r ) => String( r.user_id ) ) );
	assert.ok( users.size <= 1 );
	const rows = wp( 'db', 'query', 'SELECT details FROM wp_sseo_audit', '--skip-column-names' );
	for ( const s of SECRETS ) {
		assert.ok( ! rows.includes( s ), 'secret in audit table' );
	}
	return res.json.data.items.length + ' own entries';
} );

// --- Abilities API --------------------------------------------------------------------------------
if ( hasAbilities ) {
	await check( 'Abilities: registered but not exposed over REST until opted in', async () => {
		const list = await call( 'reader', 'GET', BASE + '/wp-json/wp-abilities/v1/abilities?per_page=100' );
		assert.equal( list.status, 200, list.text.slice( 0, 200 ) );
		assert.ok( ! JSON.stringify( list.json ).includes( 'semantic-seo/' ) );
		const registered = wp( 'eval', 'echo count( array_filter( wp_get_abilities(), function( $a ) { return 0 === strpos( $a->get_name(), "semantic-seo/" ); } ) );' );
		assert.equal( registered, '15' );
		return 'registered: ' + registered + ', exposed over REST: 0';
	} );
	await check( 'Abilities REST (opted in): reader runs read abilities, write abilities refused', async () => {
		wp( 'eval', '\\SemanticSEO\\Settings::update( array( "abilities_rest" => array( "semantic-seo/get-site-status", "semantic-seo/list-urls", "semantic-seo/start-analysis" ) ) );' );
		const list = await call( 'reader', 'GET', BASE + '/wp-json/wp-abilities/v1/abilities?per_page=100' );
		const names = ( list.json || [] ).map( ( a ) => a.name ).filter( ( n ) => n.startsWith( 'semantic-seo/' ) );
		assert.ok( names.includes( 'semantic-seo/get-site-status' ), names.join( ',' ) );
		const run = await call( 'reader', 'GET', BASE + '/wp-json/wp-abilities/v1/abilities/semantic-seo/get-site-status/run' );
		assert.equal( run.status, 200, run.text.slice( 0, 300 ) );
		const urls = await call( 'reader', 'GET', BASE + '/wp-json/wp-abilities/v1/abilities/semantic-seo/list-urls/run?input%5Blimit%5D=3' );
		assert.equal( urls.status, 200, urls.text.slice( 0, 300 ) );
		const write = await call( 'reader', 'POST', BASE + '/wp-json/wp-abilities/v1/abilities/semantic-seo/start-analysis/run', { input: { type: 'analyze' } } );
		assert.ok( [ 401, 403 ].includes( write.status ), String( write.status ) + ' ' + write.text.slice( 0, 200 ) );
		const hidden = await call( 'reader', 'GET', BASE + '/wp-json/wp-abilities/v1/abilities/semantic-seo/apply-approved-change' );
		assert.equal( hidden.status, 404 );
		return names.length + ' exposed';
	} );
} else {
	await check( 'Abilities API absent on WordPress ' + wpVersion + ': version-gated, REST still works', async () => {
		const st = await call( 'reader', 'GET', '/status' );
		assert.equal( st.status, 200 );
		assert.equal( st.json.data.agent_interface.abilities_api, false );
	} );
}

// --- MCP Adapter ----------------------------------------------------------------------------------
async function mcp( who, sessionId, method, params, id ) {
	const headers = { Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18' };
	if ( sessionId ) {
		headers[ 'Mcp-Session-Id' ] = sessionId;
	}
	return call( who, 'POST', BASE + '/wp-json/mcp/mcp-adapter-default-server', { jsonrpc: '2.0', id, method, params }, headers );
}
async function mcpSession( who ) {
	const init = await mcp( who, null, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'sseo-e2e', version: '1.0' } }, 1 );
	return { init, sid: init.headers.get( 'mcp-session-id' ) };
}
function abilityNames( res ) {
	try {
		const inner = JSON.parse( res.json.result.content[ 0 ].text );
		return ( inner.abilities || [] ).map( ( a ) => a.name );
	} catch ( e ) {
		return [];
	}
}
function toolNames( res ) {
	return ( ( res.json && res.json.result && res.json.result.tools ) || [] ).map( ( t ) => t.name );
}

if ( hasMcp ) {
	await check( 'MCP: unauthenticated client is refused', async () => {
		const res = await mcp( null, null, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'x', version: '1' } }, 1 );
		assert.ok( [ 401, 403 ].includes( res.status ), String( res.status ) );
	} );
	await check( 'MCP: no Semantic SEO tool is exposed by default (opt-in)', async () => {
		const { init, sid } = await mcpSession( 'reader' );
		assert.equal( init.status, 200, init.text.slice( 0, 300 ) );
		const tools = await mcp( 'reader', sid, 'tools/list', {}, 2 );
		const names = toolNames( tools );
		assert.ok( ! names.some( ( n ) => n.includes( 'semantic-seo' ) ), names.join( ',' ) );
		const disc = await mcp( 'reader', sid, 'tools/call', { name: 'mcp-adapter-discover-abilities', arguments: {} }, 3 );
		assert.ok( disc.json && disc.json.result && ! disc.json.error, 'adapter discovery works: ' + disc.text.slice( 0, 160 ) );
		assert.ok( ! abilityNames( disc ).some( ( n ) => n.startsWith( 'semantic-seo/' ) ), 'discover lists none' );
		return names.length + ' tools (adapter meta-tools only)';
	} );
	await check( 'MCP (opted in): only selected read-only abilities exposed; execution works; writes never exposed', async () => {
		wp( 'eval', '\\SemanticSEO\\Settings::update( array( "mcp_enabled" => true, "mcp_abilities" => array( "semantic-seo/get-site-status", "semantic-seo/list-urls", "semantic-seo/apply-approved-change" ) ) );' );
		const stored = wp( 'eval', 'echo implode( ",", \\SemanticSEO\\Settings::get( "mcp_abilities" ) );' );
		assert.ok( ! stored.includes( 'apply-approved-change' ), 'write ability filtered out of MCP selection' );
		const { sid } = await mcpSession( 'reader' );
		const disc = await mcp( 'reader', sid, 'tools/call', { name: 'mcp-adapter-discover-abilities', arguments: {} }, 2 );
		const exposed = abilityNames( disc ).filter( ( n ) => n.startsWith( 'semantic-seo/' ) ).sort();
		assert.deepEqual( exposed, [ 'semantic-seo/get-site-status', 'semantic-seo/list-urls' ], exposed.join( ',' ) );
		const exec = await mcp( 'reader', sid, 'tools/call', { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'semantic-seo/get-site-status', parameters: {} } }, 3 );
		assert.equal( exec.status, 200 );
		assert.ok( exec.text.includes( 'plugin_version' ), exec.text.slice( 0, 400 ) );
		const denied = await mcp( 'reader', sid, 'tools/call', { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'semantic-seo/apply-approved-change', parameters: { proposal_id: 1, approval_id: 1, idempotency_key: 'mcp-attempt-1' } } }, 4 );
		assert.ok( ! denied.text.includes( '"change_id"' ), 'write not executed' );
		assert.ok( /not|denied|permission|public|error/i.test( denied.text ), denied.text.slice( 0, 300 ) );
		const tools = await mcp( 'reader', sid, 'tools/list', {}, 5 );
		return 'exposed: ' + exposed.join( ', ' ) + ' | tools: ' + toolNames( tools ).join( ', ' );
	} );
	await check( 'MCP: editor without plugin capabilities cannot execute even exposed abilities', async () => {
		const { sid, init } = await mcpSession( 'editor' );
		if ( init.status !== 200 ) {
			return 'transport refused (' + init.status + ')';
		}
		const exec = await mcp( 'editor', sid, 'tools/call', { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'semantic-seo/get-site-status', parameters: {} } }, 2 );
		assert.ok( ! exec.text.includes( 'plugin_version' ), exec.text.slice( 0, 300 ) );
	} );
} else {
	await check( 'MCP Adapter not installed: status reports it explicitly', async () => {
		const st = await call( 'reader', 'GET', '/status' );
		assert.equal( st.json.data.agent_interface.mcp_adapter, false );
	} );
}

const failed = results.filter( ( r ) => ! r.ok );
fs.mkdirSync( path.join( ROOT, 'tests/e2e/artifacts' ), { recursive: true } );
fs.writeFileSync( path.join( ROOT, 'tests/e2e/artifacts/http-agent-' + wpVersion + '.json' ), JSON.stringify( { wordpress: wpVersion, abilities: hasAbilities, mcp_adapter: hasMcp, when: new Date().toISOString(), results }, null, 1 ) );
console.log( results.length - failed.length + '/' + results.length + ' passed on WordPress ' + wpVersion );
process.exit( failed.length ? 1 : 0 );
