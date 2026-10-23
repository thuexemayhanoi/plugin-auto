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
	'peak_memory_mb_this_process' => round( memory_get_peak_usage( true ) / 1048576, 1 ),
);
WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
