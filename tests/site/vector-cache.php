<?php
/**
 * CI only (not shipped): saves / restores the real-model vectors of the read-only pilot copy so a
 * re-run embeds only chunks whose text changed (the first full embedding takes ≈ 85 minutes on a
 * 4-vCPU runner). Keyed by URL path, chunk index, chunk content hash and model key, so a vector is
 * reused only for exactly the same text and model. The file lives in the repository's Actions cache
 * (not in artifacts or logs) and holds derived vectors of public content only.
 *
 *   SSEO_VECTOR_CACHE=/path/vectors.jsonl SSEO_VECTOR_CACHE_MODE=export|import wp eval-file tests/site/vector-cache.php
 *
 * @package SemanticSEO
 */

// phpcs:disable

use SemanticSEO\Database\Db;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\VectorMath;

$file    = (string) getenv( 'SSEO_VECTOR_CACHE' );
$mode    = (string) getenv( 'SSEO_VECTOR_CACHE_MODE' );
$profile = ModelProfile::active();
$path_of = static function ( $url ) {
	return rtrim( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/' ) . '/';
};

if ( 'export' === $mode ) {
	$fh = fopen( $file, 'w' );
	$n  = 0;
	foreach ( Db::rows( 'SELECT d.url, e.chunk_id, e.content_hash, e.vector FROM {embeddings} e JOIN {documents} d ON d.id = e.document_id WHERE e.model_key = %s', array( $profile['model_key'] ) ) as $r ) {
		fwrite( $fh, wp_json_encode( array( 'p' => $path_of( $r['url'] ), 'c' => (int) $r['chunk_id'], 'h' => $r['content_hash'], 'k' => $profile['model_key'], 'v' => base64_encode( $r['vector'] ) ) ) . "\n" );
		++$n;
	}
	fclose( $fh );
	WP_CLI::line( wp_json_encode( array( 'exported_vectors' => $n ) ) );
	return;
}

if ( 'import' === $mode ) {
	if ( ! is_readable( $file ) ) {
		WP_CLI::line( wp_json_encode( array( 'imported_vectors' => 0, 'note' => 'no cache' ) ) );
		return;
	}
	$docs = array();
	foreach ( Db::rows( "SELECT id, url, locale FROM {documents} WHERE status = 'publish'" ) as $d ) {
		$docs[ $path_of( $d['url'] ) ] = $d;
	}
	$imported = 0;
	$skipped  = 0;
	$fh       = fopen( $file, 'r' );
	while ( false !== ( $line = fgets( $fh ) ) ) {
		$r = json_decode( $line, true );
		if ( ! is_array( $r ) || $r['k'] !== $profile['model_key'] || ! isset( $docs[ $r['p'] ] ) ) {
			++$skipped;
			continue;
		}
		$d     = $docs[ $r['p'] ];
		$chunk = Db::row( 'SELECT content_hash FROM {chunks} WHERE document_id = %d AND chunk_index = %d', array( (int) $d['id'], (int) $r['c'] ) );
		$vec   = VectorMath::unpack( base64_decode( $r['v'] ) );
		if ( null === $chunk || $chunk['content_hash'] !== $r['h'] || true !== VectorMath::validate( $vec, (int) $profile['dimension'] ) ) {
			++$skipped;
			continue;
		}
		EmbeddingRepository::save(
			array(
				'document_id'  => (int) $d['id'],
				'chunk_id'     => (int) $r['c'],
				'content_hash' => $r['h'],
				'locale'       => $d['locale'],
			),
			$vec,
			$profile,
			'cache'
		);
		++$imported;
	}
	fclose( $fh );
	WP_CLI::line( wp_json_encode( array( 'imported_vectors' => $imported, 'skipped' => $skipped ) ) );
	return;
}

WP_CLI::error( 'Set SSEO_VECTOR_CACHE_MODE to export or import.' );
