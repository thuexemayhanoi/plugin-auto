<?php
/**
 * CI only (not shipped): AGGREGATE numbers of the plugin's analysis of the disposable copy, for the
 * read-only pilot. No titles, URLs or per-page findings are printed (the CI log and artifacts of a
 * public repository are public); the owner can see the detail by running the plugin on staging.
 *
 *   wp eval-file tests/site/pilot-report.php
 *
 * @package SemanticSEO
 */

// phpcs:disable

use SemanticSEO\Database\Db;

$rows = static function ( $sql ) {
	return Db::rows( $sql );
};
$kv   = static function ( array $rows, $k, $v ) {
	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r[ $k ] ] = (int) $r[ $v ];
	}
	return $out;
};
$report = array(
	'documents_by_type_locale' => $kv( $rows( "SELECT CONCAT(object_type, '/', subtype, '/', locale) AS k, COUNT(*) AS n FROM {documents} GROUP BY k ORDER BY k" ), 'k', 'n' ),
	'documents_by_status'      => $kv( $rows( 'SELECT status AS k, COUNT(*) AS n FROM {documents} GROUP BY status' ), 'k', 'n' ),
	'locale_sources'           => $kv( $rows( 'SELECT locale_source AS k, COUNT(*) AS n FROM {documents} GROUP BY locale_source' ), 'k', 'n' ),
	'indexable'                => $kv( $rows( 'SELECT indexable AS k, COUNT(*) AS n FROM {documents} GROUP BY indexable' ), 'k', 'n' ),
	'word_count'               => Db::row( "SELECT MIN(word_count) AS min, ROUND(AVG(word_count)) AS avg, MAX(word_count) AS max FROM {documents} WHERE object_type = 'post'" ),
	'chunks'                   => (int) Db::value( 'SELECT COUNT(*) FROM {chunks}' ),
	'links'                    => $kv( $rows( "SELECT CONCAT(IF(is_internal = 1, 'internal', 'external'), '/', resolution) AS k, COUNT(*) AS n FROM {links} GROUP BY k" ), 'k', 'n' ),
	'orphans_published_posts'  => (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE object_type = 'post' AND subtype = 'post' AND status = 'publish' AND inbound_count = 0" ),
	'findings_by_type_check'   => $kv( $rows( "SELECT CONCAT(type, '/', check_id, '/', label) AS k, COUNT(*) AS n FROM {findings} WHERE status = 'open' GROUP BY k ORDER BY n DESC" ), 'k', 'n' ),
	'link_suggestion_sources'  => (int) Db::value( "SELECT COUNT(DISTINCT document_id) FROM {findings} WHERE type = 'link' AND status = 'open'" ),
	'cannibalization_groups'   => (int) Db::value( "SELECT COUNT(DISTINCT group_key) FROM {findings} WHERE type = 'cannibalization' AND status = 'open' AND group_key <> ''" ),
	'cross_locale_overlaps'    => (int) Db::value( "SELECT COUNT(*) FROM {findings} f JOIN {documents} a ON a.id = f.document_id JOIN {documents} b ON b.id = f.target_id WHERE f.type = 'cannibalization' AND a.locale <> b.locale" ),
	'clusters'                 => $kv( $rows( 'SELECT cluster_role AS k, COUNT(*) AS n FROM {documents} GROUP BY cluster_role' ), 'k', 'n' ),
	'cluster_count'            => (int) Db::value( 'SELECT COUNT(*) FROM {clusters}' ),
	'related_rows'             => (int) Db::value( 'SELECT COUNT(*) FROM {related}' ),
	'embeddings'               => (int) Db::value( 'SELECT COUNT(*) FROM {embeddings}' ),
	'jobs'                     => $rows( 'SELECT type, status, progress_done, progress_total, TIMESTAMPDIFF(SECOND, started_at, finished_at) AS seconds FROM {jobs} ORDER BY id' ),
	'translation_pairs'        => array(
		'post_pairs'        => count( (array) get_option( 'nh_vien_translation_pairs_v1', array() ) ),
		'page_pairs'        => count( (array) get_option( 'nh_vien_page_pairs_v1', array() ) ),
		'standalone_en'     => count( (array) get_option( 'nh_vien_independent_en_posts_v1', array() ) ),
		'documents_in_pair' => (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE object_type = 'post' AND translation_group <> ''" ),
	),
	'semantic_baseline'        => ( static function () {
		$b = get_option( 'sseo_similarity_baseline' );
		return is_array( $b ) && isset( $b['locales'] ) ? $b['locales'] : null;
	} )(),
	'cannibalization_by_category_confidence' => $kv( $rows( "SELECT CONCAT(check_id, '/', confidence) AS k, COUNT(*) AS n FROM {findings} WHERE type = 'cannibalization' AND status = 'open' GROUP BY k ORDER BY n DESC" ), 'k', 'n' ),
	'peak_memory_mb_this_process' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
);

// Distribution of the overlap findings: per document, and the evidence values that admitted them.
$q = static function ( array $xs ) {
	sort( $xs );
	$n = count( $xs );
	if ( 0 === $n ) {
		return null;
	}
	$at = static function ( $p ) use ( $xs, $n ) {
		return round( (float) $xs[ (int) floor( ( $n - 1 ) * $p ) ], 3 );
	};
	return array( 'n' => $n, 'p10' => $at( 0.1 ), 'p50' => $at( 0.5 ), 'p90' => $at( 0.9 ), 'max' => round( (float) $xs[ $n - 1 ], 3 ) );
};
$per_doc  = array();
$evidence = array( 'semantic' => array(), 'semantic_calibrated_gate' => array(), 'lexical' => array(), 'title_overlap' => array(), 'topic_calibrated' => array() );
foreach ( Db::rows( "SELECT document_id, target_id, evidence FROM {findings} WHERE type = 'cannibalization' AND status = 'open'" ) as $r ) {
	foreach ( array( $r['document_id'], $r['target_id'] ) as $d ) {
		$per_doc[ $d ] = ( isset( $per_doc[ $d ] ) ? $per_doc[ $d ] : 0 ) + 1;
	}
	$e = json_decode( (string) $r['evidence'], true );
	foreach ( array( 'semantic', 'lexical', 'title_overlap', 'topic_calibrated' ) as $k ) {
		if ( isset( $e[ $k ] ) && null !== $e[ $k ] ) {
			$evidence[ $k ][] = (float) $e[ $k ];
		}
	}
	if ( isset( $e['semantic'], $e['calibration']['floor'], $e['calibration']['ceiling'] ) && null !== $e['semantic'] ) {
		$evidence['semantic_calibrated_gate'][] = max( 0.0, min( 1.0, ( (float) $e['semantic'] - (float) $e['calibration']['floor'] ) / ( (float) $e['calibration']['ceiling'] - (float) $e['calibration']['floor'] ) ) );
	}
}
$published_posts                  = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE object_type = 'post' AND status = 'publish'" );
$report['cannibalization_per_document'] = array(
	'documents_with_any' => count( $per_doc ),
	'share_of_posts'     => $published_posts ? round( count( $per_doc ) / $published_posts, 3 ) : null,
	'findings_per_document' => $q( array_values( $per_doc ) ),
);
$report['cannibalization_evidence'] = array_map( $q, $evidence );

// Cross-language rule measured against the pairs the site itself announces (hreflang), when vectors exist.
$model_key = \SemanticSEO\Semantic\ModelProfile::active()['model_key'];
if ( (int) Db::value( 'SELECT COUNT(*) FROM {embeddings} WHERE model_key = %s', array( $model_key ) ) > 0 ) {
	$group_of = array();
	foreach ( Db::rows( "SELECT id, translation_group FROM {documents} WHERE object_type = 'post' AND status = 'publish'" ) as $r ) {
		$group_of[ (int) $r['id'] ] = (string) $r['translation_group'];
	}
	$with_vec = \SemanticSEO\Semantic\EmbeddingRepository::summary_vectors( array_keys( $group_of ), $model_key );
	$groups   = array();
	foreach ( $group_of as $id => $g ) {
		if ( '' !== $g && isset( $with_vec[ $id ] ) ) {
			$groups[ $g ][] = $id;
		}
	}
	$known = count( array_filter( $groups, static function ( $ids ) {
		return 2 === count( $ids );
	} ) );
	$before = \SemanticSEO\Settings::get( 'translation_min_similarity', 0.70 );
	$sweep  = array();
	foreach ( array( 0.6, 0.65, 0.7, 0.75, 0.8 ) as $t ) {
		\SemanticSEO\Settings::update( array( 'translation_min_similarity' => $t ) );
		$res     = \SemanticSEO\Semantic\CrossLanguage::candidates( $model_key, true );
		$correct = 0;
		$wrong   = 0;
		$unknown = 0;
		foreach ( $res['pairs'] as $p ) {
			if ( $p['linked'] ) {
				++$correct;
			} elseif ( '' !== $group_of[ $p['a'] ] && '' !== $group_of[ $p['b'] ] ) {
				++$wrong; // Both pages have another announced partner.
			} else {
				++$unknown; // At least one page has no announced partner: possibly a real unlinked translation.
			}
		}
		$sweep[] = array(
			'threshold'           => $t,
			'suggested'           => count( $res['pairs'] ),
			'announced_pair'      => $correct,
			'contradicts_hreflang' => $wrong,
			'unannounced'         => $unknown,
			'recall_of_announced' => $known ? round( $correct / $known, 3 ) : null,
			'skipped'             => $res['skipped'],
		);
	}
	\SemanticSEO\Settings::update( array( 'translation_min_similarity' => $before ) );
	$report['cross_language_vs_hreflang'] = array(
		'announced_pairs_with_vectors' => $known,
		'sweep'                        => $sweep,
	);
}
WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
