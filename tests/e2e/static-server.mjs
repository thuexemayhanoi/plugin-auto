/**
 * Tiny static server for the browser fixture test.
 *
 *   /plugin/...                       → plugin root (worker, bundled runtime)
 *   /hf/<model>/resolve/<rev>/<file>  → tests/fixtures/tiny-qwen3/repo (TEST FIXTURE, not Qwen)
 *   /hf/<model>/resolve/tampered/<f>  → same files with one byte flipped in onnx/model.onnx
 *
 * Every request is recorded so the test can assert that nothing else was contacted.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const REPO = path.join( ROOT, 'tests', 'fixtures', 'tiny-qwen3', 'repo' );
const TYPES = {
	'.js': 'text/javascript',
	'.mjs': 'text/javascript',
	'.wasm': 'application/wasm',
	'.json': 'application/json',
	'.html': 'text/html; charset=utf-8',
	'.onnx': 'application/octet-stream',
};

export function startServer() {
	const log = [];
	const server = http.createServer( ( req, res ) => {
		const url = new URL( req.url, 'http://localhost' );
		log.push( url.pathname );
		let file = null;
		let tamper = false;
		const hf = url.pathname.match( /^\/hf\/[^/]+\/[^/]+\/resolve\/([^/]+)\/(.+)$/ );
		if ( hf ) {
			tamper = hf[ 1 ] === 'tampered';
			file = path.join( REPO, hf[ 2 ] );
		} else if ( url.pathname.startsWith( '/plugin/' ) ) {
			file = path.join( ROOT, decodeURIComponent( url.pathname.slice( 8 ) ) );
		}
		if ( ! file || ! file.startsWith( ROOT ) || ! fs.existsSync( file ) || fs.statSync( file ).isDirectory() ) {
			res.writeHead( 404 );
			res.end( 'not found' );
			return;
		}
		let body = fs.readFileSync( file );
		if ( tamper && file.endsWith( '.onnx' ) ) {
			body = Buffer.from( body );
			body[ body.length - 10 ] ^= 0xff;
		}
		res.writeHead( 200, {
			'Content-Type': TYPES[ path.extname( file ) ] || 'application/octet-stream',
			'Content-Length': body.length,
			'Access-Control-Allow-Origin': '*',
		} );
		res.end( body );
	} );
	return new Promise( ( resolve ) => {
		server.listen( startServer.port || 0, '127.0.0.1', () => resolve( { server, log, port: server.address().port } ) );
	} );
}

// Standalone: node tests/e2e/static-server.mjs <port>
if ( process.argv[ 1 ] && path.resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url ) ) {
	startServer.port = Number( process.argv[ 2 ] || 0 );
	const { port } = await startServer();
	console.log( 'fixture server on http://127.0.0.1:' + port );
}
