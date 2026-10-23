<?php
/**
 * CI helper (not shipped): prints the plugin's pinned model profile or the labelled fixture corpus
 * as JSON, so the real-model checks use exactly the pins and labels the plugin and tests use.
 *
 * Usage: php tests/real-model/export.php profile|corpus
 *
 * @package SemanticSEO
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/includes/Semantic/ModelProfile.php';

use SemanticSEO\Semantic\ModelProfile;

$what = isset( $argv[1] ) ? $argv[1] : '';
if ( 'profile' === $what ) {
	$out = array(
		'model_id'           => ModelProfile::MODEL_ID,
		'revision'           => ModelProfile::REVISION,
		'base_model'         => ModelProfile::BASE_MODEL,
		'host'               => ModelProfile::HOST,
		'pooling'            => ModelProfile::POOLING,
		'preprocess_version' => ModelProfile::PREPROCESS,
		'max_tokens'         => ModelProfile::MAX_TOKENS,
		'query_task'         => ModelProfile::QUERY_TASK,
		'dimension'          => ModelProfile::MAX_DIMENSION,
		'files'              => ModelProfile::FILES,
		'reference'          => ModelProfile::REFERENCE,
	);
} elseif ( 'corpus' === $what ) {
	$corpus = require dirname( __DIR__ ) . '/fixtures/corpus.php';
	$docs   = array();
	foreach ( $corpus['docs'] as $d ) {
		$html   = preg_replace( '/\{\{url:[a-z0-9-]+\}\}/', '#', $d['content'] );
		$text   = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( str_replace( '>', '> ', $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
		$docs[] = array(
			'key'   => $d['key'],
			'lang'  => $d['lang'],
			'type'  => $d['type'],
			'group' => isset( $d['group'] ) ? $d['group'] : '',
			'title' => $d['title'],
			'text'  => $d['title'] . "\n" . $text,
		);
	}
	$out = array(
		'docs'   => $docs,
		'labels' => $corpus['labels'],
	);
} else {
	fwrite( STDERR, "usage: php tests/real-model/export.php profile|corpus\n" );
	exit( 2 );
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ), "\n";
