<?php
/**
 * SEO health dashboard data: system check, scan state, issue counts, model state and what the
 * plugin is and is not allowed to do. Shared by the admin dashboard and the REST API.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Services;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Analysis\PriorityQueue;
use SemanticSEO\Analysis\ScanHistory;
use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Language\LanguageResolver;
use SemanticSEO\Semantic\DeviceVerification;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every number comes from the plugin's own tables or WordPress; there is no SEO score.
 */
final class HealthReport {

	/**
	 * Recommended PHP memory for a full scan of about 1,000 posts (measured peak 131 MB on a
	 * 1,000-post benchmark, 303 MB at 6,000).
	 */
	const RECOMMENDED_MEMORY = 268435456;

	/**
	 * System check: one row per item, level ok|warning|error.
	 *
	 * @return array[] {id, level, label, detail}
	 */
	public static function system_check() {
		global $wp_version, $wpdb;
		$db     = Environment::db_schema();
		$env    = Environment::detected_plugins();
		$checks = array();

		if ( $db['ready'] ) {
			/* translators: 1: tables present, 2: tables expected, 3: schema version */
			$detail = sprintf( __( '%1$d/%2$d tables, schema %3$d/%3$d.', 'semantic-seo-engine-vi-en' ), $db['tables_present'], $db['tables_expected'], $db['expected'] );
		} else {
			$sql = array();
			foreach ( (array) $db['sql_errors'] as $table => $e ) {
				$sql[] = $table . ': ' . ( isset( $e['errno'] ) ? '#' . (int) $e['errno'] . ' ' : '' ) . ( isset( $e['sqlstate'] ) ? 'SQLSTATE ' . $e['sqlstate'] . ' ' : '' ) . ( isset( $e['message'] ) ? $e['message'] : '' );
			}
			/* translators: 1: tables present, 2: tables expected, 3: installed version, 4: expected version, 5: missing objects, 6: SQL errors */
			$detail = sprintf( __( '%1$d/%2$d tables, schema %3$d/%4$d. Missing: %5$s. %6$s', 'semantic-seo-engine-vi-en' ), $db['tables_present'], $db['tables_expected'], $db['installed'], $db['expected'], implode( ', ', array_merge( $db['missing'], $db['missing_columns'] ) ), implode( ' | ', $sql ) );
		}
		$checks[] = array(
			'id'     => 'database',
			'level'  => $db['ready'] ? 'ok' : 'error',
			'label'  => $db['ready'] ? __( 'Plugin database ready', 'semantic-seo-engine-vi-en' ) : __( 'Plugin database incomplete', 'semantic-seo-engine-vi-en' ),
			'detail' => trim( $detail . ' ' . (string) $wpdb->db_server_info() ),
		);

		$req_ok   = version_compare( PHP_VERSION, SSEO_MIN_PHP, '>=' ) && version_compare( (string) $wp_version, SSEO_MIN_WP, '>=' );
		$checks[] = array(
			'id'     => 'versions',
			'level'  => $req_ok ? 'ok' : 'error',
			/* translators: 1: WordPress version, 2: PHP version */
			'label'  => sprintf( __( 'WordPress %1$s, PHP %2$s', 'semantic-seo-engine-vi-en' ), (string) $wp_version, PHP_VERSION ),
			/* translators: 1: minimum WordPress, 2: minimum PHP */
			'detail' => sprintf( __( 'Required: WordPress %1$s+, PHP %2$s+.', 'semantic-seo-engine-vi-en' ), SSEO_MIN_WP, SSEO_MIN_PHP ),
		);

		$caps = array();
		foreach ( array(
			Capabilities::READ    => __( 'view reports', 'semantic-seo-engine-vi-en' ),
			Capabilities::ANALYZE => __( 'run scans', 'semantic-seo-engine-vi-en' ),
			Capabilities::REVIEW  => __( 'review proposals', 'semantic-seo-engine-vi-en' ),
			Capabilities::APPLY   => __( 'apply approved changes', 'semantic-seo-engine-vi-en' ),
			Capabilities::MANAGE  => __( 'change settings', 'semantic-seo-engine-vi-en' ),
		) as $cap => $label ) {
			if ( current_user_can( $cap ) ) {
				$caps[] = $label;
			}
		}
		$checks[] = array(
			'id'     => 'permissions',
			'level'  => current_user_can( Capabilities::ANALYZE ) ? 'ok' : 'warning',
			'label'  => __( 'Your WordPress permissions', 'semantic-seo-engine-vi-en' ),
			/* translators: %s: list of allowed actions */
			'detail' => sprintf( __( 'You may: %s.', 'semantic-seo-engine-vi-en' ), array() === $caps ? '—' : implode( ', ', $caps ) ),
		);

		$seo      = (string) \SemanticSEO\Compatibility\SeoAdapter::primary();
		$names    = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'tsf'      => 'The SEO Framework',
		);
		$checks[] = array(
			'id'     => 'seo_plugin',
			'level'  => isset( $names[ $seo ] ) ? 'ok' : 'warning',
			/* translators: %s: SEO plugin */
			'label'  => sprintf( __( 'SEO plugin: %s', 'semantic-seo-engine-vi-en' ), isset( $names[ $seo ] ) ? $names[ $seo ] : __( 'none detected', 'semantic-seo-engine-vi-en' ) ),
			'detail' => __( 'Titles, meta descriptions, canonicals and robots are read from it, never written.', 'semantic-seo-engine-vi-en' ),
		);

		$fw       = LanguageResolver::framework();
		$checks[] = array(
			'id'     => 'languages',
			'level'  => LanguageResolver::has_translation_mapping() ? 'ok' : 'warning',
			/* translators: %s: multilingual plugin */
			'label'  => sprintf( __( 'Languages: %s', 'semantic-seo-engine-vi-en' ), LanguageResolver::framework_label( $fw ) ),
			'detail' => LanguageResolver::has_translation_mapping() ? __( 'Translation links are read from the language plugin; Vietnamese and English are never compared as competitors.', 'semantic-seo-engine-vi-en' ) : __( 'No translation mapping: languages are told apart by URL prefix (/en/) or the site language only.', 'semantic-seo-engine-vi-en' ),
		);

		$cache    = array_values( (array) $env['cache'] );
		$checks[] = array(
			'id'     => 'cache',
			'level'  => 'ok',
			/* translators: %s: cache plugins */
			'label'  => array() === $cache ? __( 'No page cache plugin detected', 'semantic-seo-engine-vi-en' ) : sprintf( __( 'Cache: %s', 'semantic-seo-engine-vi-en' ), implode( ', ', $cache ) ),
			'detail' => __( 'The plugin prints nothing on public pages by default; its admin pages and API answers are marked as not cacheable.', 'semantic-seo-engine-vi-en' ),
		);

		$mem      = ScanHistory::bytes( (string) ini_get( 'memory_limit' ) );
		$time     = (int) ini_get( 'max_execution_time' );
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[] = array(
			'id'     => 'resources',
			'level'  => ( 0 !== $mem && $mem < self::RECOMMENDED_MEMORY ) ? 'warning' : 'ok',
			/* translators: 1: memory limit, 2: time limit */
			'label'  => sprintf( __( 'Server: memory %1$s, time limit %2$s', 'semantic-seo-engine-vi-en' ), (string) ini_get( 'memory_limit' ), 0 === $time ? '∞' : $time . ' s' ),
			'detail' => ( 0 !== $mem && $mem < self::RECOMMENDED_MEMORY ? __( 'Below the recommended 256M; a full scan of a large site may stop. Scans run in small batches and resume after an error.', 'semantic-seo-engine-vi-en' ) . ' ' : '' )
				. ( $cron_off ? __( 'WP-Cron is disabled: scans advance while this page is open or through WP-CLI.', 'semantic-seo-engine-vi-en' ) : __( 'Scans run in the background through WP-Cron in short batches.', 'semantic-seo-engine-vi-en' ) ),
		);

		$qwen     = self::qwen();
		$checks[] = array(
			'id'     => 'model',
			'level'  => 'ok',
			/* translators: %s: model state */
			'label'  => sprintf( __( 'AI model (optional): %s', 'semantic-seo-engine-vi-en' ), $qwen['label'] ),
			'detail' => $qwen['detail'],
		);

		$checks[] = array(
			'id'     => 'read_only',
			'level'  => 'ok',
			'label'  => __( 'Read-only mode', 'semantic-seo-engine-vi-en' ),
			'detail' => __( 'Scans never change posts, SEO data, URLs or settings of other plugins. A change happens only after you preview the exact diff and approve it, and it can be undone.', 'semantic-seo-engine-vi-en' ),
		);
		return $checks;
	}

	/**
	 * State of the optional Qwen3 embedding model.
	 *
	 * @return array{state:string,label:string,detail:string,runtime_bundled:bool,vectors:int,documents_with_vector:int,device_verified:bool}
	 */
	public static function qwen() {
		$profile  = ModelProfile::active();
		$stats    = EmbeddingRepository::stats( $profile['model_key'] );
		$bundled  = file_exists( SSEO_DIR . 'assets/vendor/transformers/transformers.min.js' );
		$verified = DeviceVerification::is_verified( get_current_user_id(), $profile['model_key'] );
		if ( ! $bundled ) {
			$state = 'unavailable';
			$label = __( 'not available in this build', 'semantic-seo-engine-vi-en' );
		} elseif ( $stats['documents_with_vector'] > 0 ) {
			$state = 'active';
			$label = __( 'in use', 'semantic-seo-engine-vi-en' );
		} elseif ( $verified ) {
			$state = 'ready';
			$label = __( 'ready on your device, no page embedded yet', 'semantic-seo-engine-vi-en' );
		} else {
			$state = 'not_enabled';
			$label = __( 'not enabled', 'semantic-seo-engine-vi-en' );
		}
		/* translators: 1: pages with a vector, 2: model ID */
		$detail = sprintf( __( '%1$d pages have a vector from %2$s. The model only measures meaning similarity (embeddings); it does not write text. It runs in your browser when you start it, never on the server and never for visitors; the SEO analysis works without it.', 'semantic-seo-engine-vi-en' ), (int) $stats['documents_with_vector'], (string) $profile['model_id'] );
		return array(
			'state'                 => $state,
			'label'                 => $label,
			'detail'                => $detail,
			'runtime_bundled'       => $bundled,
			'vectors'               => (int) $stats['vectors_current_model'],
			'documents_with_vector' => (int) $stats['documents_with_vector'],
			'device_verified'       => $verified,
			'model_id'              => (string) $profile['model_id'],
		);
	}

	/**
	 * Dashboard overview.
	 *
	 * @return array
	 */
	public static function overview() {
		$db     = Environment::db_schema();
		$counts = $db['ready'] ? self::counts() : null;
		$active = $db['ready'] ? array_map( array( JobRepository::class, 'to_public' ), self::active_jobs() ) : array();
		$last   = $db['ready'] ? JobRepository::find( array( 'limit' => 1 ) ) : array();
		$failed = array() !== $last && 'failed' === $last[0]['status'] ? JobRepository::to_public( $last[0] ) : null;
		$scope  = $db['ready'] ? InventoryScanner::scope_counts() : array( 'total' => 0 );
		$latest = ScanHistory::latest();

		if ( ! $db['ready'] ) {
			$health  = 'error';
			$message = __( 'The plugin database is incomplete. Scans are blocked until it is repaired.', 'semantic-seo-engine-vi-en' );
		} elseif ( null !== $failed ) {
			$health  = 'warning';
			$message = __( 'The last scan stopped with an error. See the error below and run it again.', 'semantic-seo-engine-vi-en' );
		} elseif ( array() !== $active ) {
			$health  = 'ok';
			$message = __( 'Working normally: a scan is in progress.', 'semantic-seo-engine-vi-en' );
		} elseif ( null === $latest ) {
			$health  = 'ok';
			$message = __( 'Working normally. No scan yet: start with the system check and the 20-page trial.', 'semantic-seo-engine-vi-en' );
		} else {
			$health  = 'ok';
			$message = __( 'Working normally.', 'semantic-seo-engine-vi-en' );
		}
		return array(
			'health'       => $health,
			'message'      => $message,
			'database'     => $db,
			'scope'        => (int) $scope['total'],
			'counts'       => $counts,
			'jobs'         => array(
				'active'      => $active,
				'last_failed' => $failed,
			),
			'last_scan'    => $latest,
			'last_pilot'   => ScanHistory::latest( 'pilot' ),
			'diff'         => $db['ready'] ? ScanHistory::diff( 10 ) : null,
			'estimate'     => ScanHistory::estimate( (int) $scope['total'] ),
			'qwen'         => self::qwen(),
			'permissions'  => self::permissions(),
			'read_only'    => true,
			'generated_at' => gmdate( 'c' ),
			'score_notice' => __( 'There is no overall SEO score: counts are findings to review, and no ranking in Google is predicted.', 'semantic-seo-engine-vi-en' ),
		);
	}

	/**
	 * Running, queued and paused scan jobs.
	 *
	 * @return array[]
	 */
	public static function active_jobs() {
		$out = array();
		foreach ( array( 'running', 'queued', 'paused' ) as $status ) {
			foreach ( JobRepository::find(
				array(
					'status' => $status,
					'limit'  => 5,
				)
			) as $j ) {
				if ( in_array( $j['type'], array( 'inventory', 'analyze', 'incremental' ), true ) ) {
					$out[] = $j;
				}
			}
		}
		return $out;
	}

	/**
	 * Dashboard counts.
	 *
	 * @return array
	 */
	public static function counts() {
		$analyzed = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND excluded = 0 AND analyzed_at IS NOT NULL" );
		$by_lang  = array();
		foreach ( Db::rows( "SELECT locale, COUNT(*) AS n FROM {documents} WHERE status = 'publish' AND excluded = 0 GROUP BY locale" ) as $r ) {
			$by_lang[ '' === (string) $r['locale'] ? '?' : (string) $r['locale'] ] = (int) $r['n'];
		}
		$kinds = array(
			'verified'  => 0,
			'heuristic' => 0,
			'editorial' => 0,
		);
		foreach ( (array) FindingRepository::summary() as $type => $labels ) {
			if ( 'classification' === $type ) {
				continue;
			}
			foreach ( (array) $labels as $label => $n ) {
				if ( 'info' === $label ) {
					continue;
				}
				$kinds[ PriorityQueue::finding_kind( (string) $label ) ] += (int) $n;
			}
		}
		return array(
			'inventoried'      => (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND excluded = 0" ),
			'analyzed'         => $analyzed,
			'by_language'      => $by_lang,
			'priorities'       => PriorityQueue::counts(),
			'kinds'            => $kinds,
			'orphans'          => PriorityQueue::pages_with( 'qa', array( 'orphan' ) ),
			'few_links'        => PriorityQueue::pages_with( 'qa', array( 'few_cluster_links' ) ),
			'link_suggestions' => (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open' AND type = 'link'" ),
			'overlap_pages'    => PriorityQueue::pages_with( 'cannibalization', array( 'exact_duplicate', 'near_duplicate', 'potential_competition' ), true ),
			'overlap_pairs'    => (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open' AND type = 'cannibalization' AND check_id IN ('exact_duplicate','near_duplicate','potential_competition')" ),
			'quality_pages'    => PriorityQueue::pages_with( 'qa', array( 'thin_for_intent', 'no_subheadings', 'heading_skip', 'h1_in_content', 'images_missing_alt', 'no_external_sources', 'repetitive_phrasing' ) ),
			'technical_errors' => (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open' AND label = 'verified_error'" ),
		);
	}

	/**
	 * What the plugin may and may not do, for the current user and settings.
	 *
	 * @return array{allowed:string[],not_allowed:string[]}
	 */
	public static function permissions() {
		$allowed = array();
		if ( current_user_can( Capabilities::READ ) ) {
			$allowed[] = __( 'Read your posts and SEO data to analyze them', 'semantic-seo-engine-vi-en' );
			$allowed[] = __( 'Show reports and export them (CSV / XLSX / JSON)', 'semantic-seo-engine-vi-en' );
		}
		if ( current_user_can( Capabilities::ANALYZE ) ) {
			$allowed[] = __( 'Run, pause, resume and cancel scans', 'semantic-seo-engine-vi-en' );
		}
		if ( current_user_can( Capabilities::REVIEW ) ) {
			$allowed[] = __( 'Create a preview of a proposed change and approve or reject it (people only, never AI agents)', 'semantic-seo-engine-vi-en' );
		}
		if ( current_user_can( Capabilities::APPLY ) ) {
			$allowed[] = __( 'Apply a change you approved after seeing its exact diff, and undo it', 'semantic-seo-engine-vi-en' );
		}
		$not = array(
			__( 'Edit published posts automatically', 'semantic-seo-engine-vi-en' ),
			__( 'Publish or unpublish posts', 'semantic-seo-engine-vi-en' ),
			__( 'Change titles, H1 or meta descriptions in bulk', 'semantic-seo-engine-vi-en' ),
			__( 'Change slugs or permalinks', 'semantic-seo-engine-vi-en' ),
			__( 'Create 301 redirects', 'semantic-seo-engine-vi-en' ),
			__( 'Delete or merge posts', 'semantic-seo-engine-vi-en' ),
			__( 'Change robots, canonical or hreflang', 'semantic-seo-engine-vi-en' ),
			__( 'Change Yoast SEO data without approval', 'semantic-seo-engine-vi-en' ),
		);
		if ( ! Settings::get( 'agents_may_apply', false ) ) {
			$not[] = __( 'Let AI agents apply changes (off; even when allowed, a person must approve each change)', 'semantic-seo-engine-vi-en' );
		}
		return array(
			'allowed'     => $allowed,
			'not_allowed' => $not,
		);
	}
}
