#!/usr/bin/env node
/**
 * Copies the pinned browser runtime (Transformers.js web build + ONNX Runtime Web WASM) into
 * assets/vendor/transformers/ and writes a manifest with SHA-256 hashes. Model weights are never
 * bundled. Run after `npm ci`.
 */
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const out = join( root, 'assets/vendor/transformers' );
const tf = join( root, 'node_modules/@huggingface/transformers' );
const ort = join( root, 'node_modules/onnxruntime-web' );
const files = [
	[ join( tf, 'dist/transformers.min.js' ), 'transformers.min.js' ],
	[ join( ort, 'dist/ort-wasm-simd-threaded.asyncify.mjs' ), 'ort-wasm-simd-threaded.asyncify.mjs' ],
	[ join( ort, 'dist/ort-wasm-simd-threaded.asyncify.wasm' ), 'ort-wasm-simd-threaded.asyncify.wasm' ],
	[ join( ort, 'dist/ort-wasm-simd-threaded.mjs' ), 'ort-wasm-simd-threaded.mjs' ],
	[ join( ort, 'dist/ort-wasm-simd-threaded.wasm' ), 'ort-wasm-simd-threaded.wasm' ],
];
mkdirSync( out, { recursive: true } );
const manifest = {
	transformers: JSON.parse( readFileSync( join( tf, 'package.json' ), 'utf8' ) ).version,
	onnxruntime_web: JSON.parse( readFileSync( join( ort, 'package.json' ), 'utf8' ) ).version,
	licenses: { transformers: 'Apache-2.0', 'onnxruntime-web': 'MIT' },
	files: {},
};
for ( const [ src, name ] of files ) {
	if ( ! existsSync( src ) ) {
		console.error( 'Missing ' + src + ' — run npm ci first.' );
		process.exit( 1 );
	}
	copyFileSync( src, join( out, name ) );
	const buf = readFileSync( join( out, name ) );
	manifest.files[ name ] = { bytes: buf.length, sha256: createHash( 'sha256' ).update( buf ).digest( 'hex' ) };
}
for ( const [ lic, name ] of [ [ join( tf, 'LICENSE' ), 'LICENSE-transformers.js.txt' ] ] ) {
	if ( existsSync( lic ) ) {
		copyFileSync( lic, join( out, name ) );
	}
}
writeFileSync(
	join( out, 'LICENSE-onnxruntime-web.txt' ),
	'onnxruntime-web ' + manifest.onnxruntime_web + ' — MIT License, Copyright (c) Microsoft Corporation. https://github.com/microsoft/onnxruntime/blob/main/LICENSE\n'
);
writeFileSync( join( out, 'manifest.json' ), JSON.stringify( manifest, null, 2 ) + '\n' );
console.log( 'Vendor runtime written to', out );
console.log( JSON.stringify( manifest, null, 2 ) );
