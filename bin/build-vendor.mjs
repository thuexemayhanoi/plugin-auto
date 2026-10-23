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
const mit = ( holder ) =>
	'MIT License\n\nCopyright (c) ' + holder + '\n\n' +
	'Permission is hereby granted, free of charge, to any person obtaining a copy\n' +
	'of this software and associated documentation files (the "Software"), to deal\n' +
	'in the Software without restriction, including without limitation the rights\n' +
	'to use, copy, modify, merge, publish, distribute, sublicense, and/or sell\n' +
	'copies of the Software, and to permit persons to whom the Software is\n' +
	'furnished to do so, subject to the following conditions:\n\n' +
	'The above copyright notice and this permission notice shall be included in all\n' +
	'copies or substantial portions of the Software.\n\n' +
	'THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\n' +
	'IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\n' +
	'FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\n' +
	'AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\n' +
	'LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\n' +
	'OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\n' +
	'SOFTWARE.\n';
writeFileSync( join( out, 'LICENSE-onnxruntime-web.txt' ), 'onnxruntime-web ' + manifest.onnxruntime_web + ' (https://github.com/microsoft/onnxruntime)\n\n' + mit( 'Microsoft Corporation' ) );
const jinja = join( root, 'node_modules/@huggingface/jinja/package.json' );
const toks = join( root, 'node_modules/@huggingface/tokenizers/package.json' );
writeFileSync(
	join( out, 'THIRD-PARTY-NOTICES.txt' ),
	'transformers.min.js bundles the following packages:\n' +
		'- @huggingface/transformers ' + manifest.transformers + ' — Apache-2.0 (LICENSE-transformers.js.txt)\n' +
		'- onnxruntime-web / onnxruntime-common — MIT (LICENSE-onnxruntime-web.txt)\n' +
		( existsSync( jinja ) ? '- @huggingface/jinja ' + JSON.parse( readFileSync( jinja, 'utf8' ) ).version + ' — MIT, Copyright (c) Hugging Face\n' : '' ) +
		( existsSync( toks ) ? '- @huggingface/tokenizers ' + JSON.parse( readFileSync( toks, 'utf8' ) ).version + ' — Apache-2.0 (same terms as LICENSE-transformers.js.txt)\n' : '' ) +
		'\nModel weights are not included. Qwen3-Embedding-0.6B (Apache-2.0) is downloaded by the\n' +
		'administrator\'s browser from huggingface.co only after explicit consent, at a pinned revision,\n' +
		'and verified by size and SHA-256 before use.\n\n' + mit( 'Hugging Face' ).replace( 'MIT License', 'MIT License (@huggingface/jinja)' )
);
writeFileSync( join( out, 'manifest.json' ), JSON.stringify( manifest, null, 2 ) + '\n' );
console.log( 'Vendor runtime written to', out );
console.log( JSON.stringify( manifest, null, 2 ) );
