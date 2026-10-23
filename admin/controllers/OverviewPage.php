<?php
/**
 * Page 1 — SEO dashboard: health, three-step quick start (system check, 20-page trial, whole
 * site), counts, priority work, comparison with the previous scan, AI model state and permissions.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Analysis\PriorityQueue;
use SemanticSEO\Capabilities;
use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Services\Api;
use SemanticSEO\Services\HealthReport;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Written for site owners who do not want to learn the technical modules: install, check, try on
 * 20 pages, scan the site, read the work list. Every number is real; there is no SEO score.
 */
final class OverviewPage {

	const PILOT_SIZE = 20;

	/**
	 * Overall state label (kept for the agent status and older screens).
	 *
	 * @param array $status Status from Api::status().
	 * @return array{0:string,1:string} [state key, explanation]
	 */
	public static function state( array $status ) {
		$o = HealthReport::overview();
		if ( ! $o['database']['ready'] ) {
			return array( 'failed', $o['message'] );
		}
		if ( array() !== $o['jobs']['active'] ) {
			return array( 'processing', $o['message'] );
		}
		if ( null !== $o['jobs']['last_failed'] ) {
			return array( 'failed', $o['message'] );
		}
		if ( 0 === (int) $status['index']['total'] ) {
			return array( 'not_configured', $o['message'] );
		}
		return array( 'ready', $o['message'] );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'SEO Dashboard', 'semantic-seo-engine-vi-en' ), __( 'Read-only by default: the plugin analyzes and proposes; nothing on your site changes without an exact diff that a human approves.', 'semantic-seo-engine-vi-en' ) );
		if ( ! current_user_can( Capabilities::READ ) ) {
			Ui::footer();
			return;
		}
		$o      = HealthReport::overview();
		$checks = HealthReport::system_check();
		self::banner( $o );
		$limit = $o['database']['ready'] ? Api::inventory_limit() : array( 'reached' => false );
		if ( ! empty( $limit['reached'] ) ) {
			Ui::notice(
				'warning',
				sprintf(
					/* translators: 1: limit, 2: number of objects */
					__( 'The inventory reached the Max documents limit (%1$s): %2$s objects in scope are not inventoried or analyzed. Raise the limit in Settings to include them.', 'semantic-seo-engine-vi-en' ),
					number_format_i18n( (int) $limit['max_documents'] ),
					number_format_i18n( (int) $limit['not_inventoried'] )
				)
			);
		}
		self::quick_start( $o, $checks );
		if ( $o['database']['ready'] && null !== $o['counts'] && $o['counts']['inventoried'] > 0 ) {
			self::cards( $o );
			self::priorities();
			self::diff( $o );
		}
		echo '<div class="sseo-cards">';
		self::qwen( $o );
		self::permissions( $o );
		echo '</div>';
		if ( current_user_can( Capabilities::MANAGE ) ) {
			self::scope_form();
		}
		Ui::footer();
	}

	/**
	 * Health banner (and database repair).
	 *
	 * @param array $o Overview.
	 * @return void
	 */
	private static function banner( array $o ) {
		$titles = array(
			'ok'      => __( 'Plugin working normally', 'semantic-seo-engine-vi-en' ),
			'warning' => __( 'Needs attention', 'semantic-seo-engine-vi-en' ),
			'error'   => __( 'Error: action needed', 'semantic-seo-engine-vi-en' ),
		);
		$h      = (string) $o['health'];
		echo '<section class="sseo-health sseo-health--' . esc_attr( $h ) . '" role="status" aria-live="polite"><h2>' . esc_html( $titles[ $h ] ) . '</h2><p>' . esc_html( $o['message'] ) . '</p>';
		if ( ! $o['database']['ready'] ) {
			$db = $o['database'];
			/* translators: 1: tables present, 2: tables expected, 3: missing objects */
			echo '<p>' . esc_html( sprintf( __( 'Database: %1$d of %2$d tables. Missing: %3$s.', 'semantic-seo-engine-vi-en' ), (int) $db['tables_present'], (int) $db['tables_expected'], implode( ', ', array_merge( $db['missing'], $db['missing_columns'] ) ) ) ) . '</p>';
			foreach ( (array) $db['sql_errors'] as $table => $e ) {
				echo '<p class="sseo-mono">' . esc_html( $table . ': #' . ( isset( $e['errno'] ) ? (int) $e['errno'] : 0 ) . ' SQLSTATE ' . ( isset( $e['sqlstate'] ) ? $e['sqlstate'] : '' ) . ' — ' . ( isset( $e['message'] ) ? $e['message'] : '' ) ) . '</p>';
			}
			if ( current_user_can( Capabilities::MANAGE ) ) {
				Ui::out( Ui::action_button( 'repair_db', array(), __( 'Repair the database', 'semantic-seo-engine-vi-en' ), 'button button-primary' ) );
				echo '<p class="description">' . esc_html__( 'Safe: only creates the missing tables and columns; existing data is kept and nothing is deleted.', 'semantic-seo-engine-vi-en' ) . '</p>';
			}
		}
		if ( null !== $o['jobs']['last_failed'] ) {
			$f = $o['jobs']['last_failed'];
			/* translators: 1: job ID, 2: job type, 3: error */
			echo '<p>' . esc_html( sprintf( __( 'Job #%1$d (%2$s) failed: %3$s', 'semantic-seo-engine-vi-en' ), (int) $f['id'], Ui::term( $f['type'] ), (string) $f['last_error'] ) ) . '</p>';
		}
		echo '<p class="description">' . esc_html( $o['score_notice'] ) . '</p></section>';
	}

	/**
	 * The three steps.
	 *
	 * @param array   $o      Overview.
	 * @param array[] $checks System check.
	 * @return void
	 */
	private static function quick_start( array $o, array $checks ) {
		$ok     = count(
			array_filter(
				$checks,
				static function ( $c ) {
					return 'ok' === $c['level'];
				}
			)
		);
		$errors = count(
			array_filter(
				$checks,
				static function ( $c ) {
					return 'error' === $c['level'];
				}
			)
		);
		$pilot  = $o['last_pilot'];
		$site   = null !== $o['last_scan'] && 'site' === $o['last_scan']['scope'] ? $o['last_scan'] : null;
		$jobs   = $o['jobs']['active'];
		$can    = current_user_can( Capabilities::ANALYZE ) && $o['database']['ready'];

		echo '<section class="sseo-wizard sseo-quick" aria-labelledby="sseo-quick-title"><h2 id="sseo-quick-title">' . esc_html__( 'Quick start in 3 steps', 'semantic-seo-engine-vi-en' ) . '</h2><ol class="sseo-steps">';

		// Step 1.
		echo '<li class="sseo-step sseo-step--' . ( 0 === $errors ? 'done' : 'current' ) . '"><h3>' . esc_html__( '1. Check the system', 'semantic-seo-engine-vi-en' ) . '</h3>';
		/* translators: 1: checks passed, 2: checks */
		echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d checks passed.', 'semantic-seo-engine-vi-en' ), $ok, count( $checks ) ) ) . ' ' . esc_html__( 'Database, permissions, SEO plugin, languages, cache, server resources and the AI model.', 'semantic-seo-engine-vi-en' ) . '</p>';
		echo '<details' . ( 0 !== $errors || '1' === Ui::get( 'check' ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Show the system check', 'semantic-seo-engine-vi-en' ) . '</summary><ul class="sseo-checks">';
		foreach ( $checks as $c ) {
			$icon = array(
				'ok'      => '✓',
				'warning' => '!',
				'error'   => '✕',
			);
			echo '<li class="sseo-' . ( 'ok' === $c['level'] ? 'ok' : ( 'error' === $c['level'] ? 'error' : 'warn' ) ) . '"><span aria-hidden="true">' . esc_html( $icon[ $c['level'] ] ) . '</span> <strong>' . esc_html( $c['label'] ) . '</strong><br><span class="description">' . esc_html( $c['detail'] ) . '</span></li>';
		}
		echo '</ul></details><p><a class="button" href="' . esc_url( Ui::url( 'sseo', array( 'check' => '1' ) ) ) . '">' . esc_html__( 'Check the system again', 'semantic-seo-engine-vi-en' ) . '</a></p></li>';

		// Step 2.
		echo '<li class="sseo-step sseo-step--' . ( null !== $pilot || null !== $site ? 'done' : 'current' ) . '"><h3>' . esc_html__( '2. Try on 20 pages', 'semantic-seo-engine-vi-en' ) . '</h3><p>' . esc_html__( 'Analyzes the 20 most recently updated posts, read-only: no post is changed. Shows real results and measures the speed of your server.', 'semantic-seo-engine-vi-en' ) . '</p>';
		self::jobs_widget( $jobs, true );
		if ( $can && array() === $jobs ) {
			Ui::out( Ui::action_button( 'scan', array( 'scope' => 'pilot' ), __( 'Analyze 20 pages (trial)', 'semantic-seo-engine-vi-en' ), 'button button-primary' ) );
		}
		if ( null !== $pilot ) {
			self::scan_result( $pilot );
		}
		echo '</li>';

		// Step 3.
		$total = (int) $o['scope'];
		echo '<li class="sseo-step sseo-step--' . ( null !== $site ? 'done' : ( null !== $pilot ? 'current' : 'todo' ) ) . '"><h3>' . esc_html__( '3. Analyze the whole site', 'semantic-seo-engine-vi-en' ) . '</h3>';
		/* translators: %s: number of pages */
		echo '<p>' . esc_html( sprintf( __( '%s posts, pages and category archives are in scope (counted in the database now).', 'semantic-seo-engine-vi-en' ), number_format_i18n( $total ) ) ) . '</p>';
		self::estimate( $o['estimate'] );
		self::jobs_widget( $jobs, false );
		if ( $can && array() === $jobs ) {
			Ui::out( Ui::action_button( 'scan', array( 'scope' => 'site' ), __( 'Analyze the whole site', 'semantic-seo-engine-vi-en' ), null !== $pilot ? 'button button-primary' : 'button', __( 'Start the read-only analysis of the whole site? It runs in the background in small batches; you can pause or cancel it.', 'semantic-seo-engine-vi-en' ) ) );
		}
		if ( null !== $site ) {
			self::scan_result( $site );
		}
		echo '<p class="description">' . esc_html__( 'The AI model is not needed for this analysis: it works on any WordPress hosting.', 'semantic-seo-engine-vi-en' ) . '</p></li>';
		echo '</ol></section>';
	}

	/**
	 * Progress and controls of the running scan jobs.
	 *
	 * @param array[] $jobs  Public jobs.
	 * @param bool    $pilot Show pilot jobs (true) or site-wide jobs (false).
	 * @return void
	 */
	private static function jobs_widget( array $jobs, $pilot ) {
		foreach ( $jobs as $j ) {
			$is_pilot = ! empty( $j['params']['pilot'] );
			if ( $is_pilot !== $pilot ) {
				continue;
			}
			$pct    = null === $j['progress_pct'] ? 0 : (float) $j['progress_pct'];
			$labels = array(
				'inventory'   => __( 'Step 1 of 2: reading the pages', 'semantic-seo-engine-vi-en' ),
				'analyze'     => __( 'Step 2 of 2: analyzing', 'semantic-seo-engine-vi-en' ),
				'incremental' => __( 'Updating changed pages', 'semantic-seo-engine-vi-en' ),
			);
			echo '<div class="sseo-jobbox"><p><strong>' . esc_html( isset( $labels[ $j['type'] ] ) ? $labels[ $j['type'] ] : Ui::term( $j['type'] ) ) . '</strong> — <span data-sseo-job="' . esc_attr( (string) $j['id'] ) . '" data-sseo-status="' . esc_attr( $j['status'] ) . '" data-sseo-reload="1">' . esc_html( Ui::term( $j['status'] ) ) . '</span></p>';
			echo '<progress class="sseo-progress sseo-progress--wide" max="100" value="' . esc_attr( (string) $pct ) . '" aria-label="' . esc_attr__( 'Progress', 'semantic-seo-engine-vi-en' ) . '"></progress> <span data-sseo-progress="' . esc_attr( (string) $j['id'] ) . '">' . esc_html( $j['progress_done'] . ' / ' . $j['progress_total'] ) . '</span>';
			if ( '' !== (string) $j['last_error'] ) {
				echo '<p class="sseo-warn">' . esc_html( (string) $j['last_error'] ) . '</p>';
			}
			if ( current_user_can( Capabilities::ANALYZE ) ) {
				echo '<p class="sseo-actions">';
				if ( 'paused' === $j['status'] ) {
					Ui::out( Ui::action_button( 'job_control', array( 'job_id' => (string) $j['id'], 'op' => 'resume' ), __( 'Resume', 'semantic-seo-engine-vi-en' ), 'button button-primary' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				} else {
					Ui::out( Ui::action_button( 'job_control', array( 'job_id' => (string) $j['id'], 'op' => 'pause' ), __( 'Pause', 'semantic-seo-engine-vi-en' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}
				Ui::out( Ui::action_button( 'job_control', array( 'job_id' => (string) $j['id'], 'op' => 'cancel' ), __( 'Cancel', 'semantic-seo-engine-vi-en' ), 'button', __( 'Cancel this scan? Results already saved are kept.', 'semantic-seo-engine-vi-en' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				echo '</p>';
			}
			echo '<p class="description">' . esc_html__( 'Keep working: the scan continues in the background. This page updates by itself.', 'semantic-seo-engine-vi-en' ) . '</p></div>';
		}
	}

	/**
	 * Measured estimate for the whole site.
	 *
	 * @param array|null $e Estimate.
	 * @return void
	 */
	private static function estimate( $e ) {
		if ( null === $e ) {
			echo '<p class="description">' . esc_html__( 'Run the 20-page trial first: the time and memory of the whole scan are then estimated from a real measurement on your server.', 'semantic-seo-engine-vi-en' ) . '</p>';
			return;
		}
		/* translators: 1: estimated duration, 2: seconds per page, 3: pages measured */
		$line = sprintf( __( 'Estimated processing time: about %1$s (measured %2$s s per page on %3$d pages).', 'semantic-seo-engine-vi-en' ), self::duration( (int) $e['estimated_seconds'] ), number_format_i18n( (float) $e['seconds_per_document'], 3 ), (int) $e['basis_documents'] );
		echo '<p><strong>' . esc_html( $line ) . '</strong>';
		if ( $e['lower_bound'] ) {
			echo ' ' . esc_html__( 'This is a minimum: on a larger site each page is compared with more pages, so the real time is longer. Waits between background batches are not included.', 'semantic-seo-engine-vi-en' );
		}
		echo '</p>';
		if ( $e['peak_memory'] > 0 ) {
			$limit = (int) $e['memory_limit_bytes'];
			/* translators: 1: measured peak memory, 2: PHP memory limit */
			$mem = sprintf( __( 'Peak memory measured: %1$s (PHP limit %2$s).', 'semantic-seo-engine-vi-en' ), size_format( (int) $e['peak_memory'] ), 0 === $limit ? '∞' : (string) $e['memory_limit'] );
			echo '<p class="' . ( 0 !== $limit && $e['peak_memory'] > 0.6 * $limit ? 'sseo-warn' : 'description' ) . '">' . esc_html( $mem ) . '</p>';
		}
	}

	/**
	 * Result of a finished scan.
	 *
	 * @param array $s History entry.
	 * @return void
	 */
	private static function scan_result( array $s ) {
		$p = (array) $s['priorities'];
		/* translators: 1: date, 2: pages, 3: duration */
		echo '<div class="sseo-result"><p><strong>' . esc_html( sprintf( __( 'Finished %1$s: %2$d pages in %3$s of processing.', 'semantic-seo-engine-vi-en' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( (string) $s['finished_at'] ) ), (int) $s['documents'], self::duration( (int) round( (float) $s['work_seconds'] ) ) ) ) . '</strong></p>';
		echo '<ul class="sseo-counts">';
		foreach ( array( 1, 2, 3, 4 ) as $n ) {
			echo '<li><span class="sseo-prio sseo-prio--' . (int) $n . '">' . esc_html( PriorityQueue::priority_label( $n ) ) . '</span> ' . esc_html( number_format_i18n( isset( $p[ $n ] ) ? (int) $p[ $n ] : 0 ) ) . '</li>';
		}
		echo '</ul>';
		if ( is_array( $s['readonly'] ) ) {
			if ( $s['readonly']['unchanged'] ) {
				/* translators: %d: number of posts */
				echo '<p class="sseo-ok">✓ ' . esc_html( sprintf( __( 'Read-only verified: the %d posts in scope, their meta and their categories are exactly as before the scan.', 'semantic-seo-engine-vi-en' ), (int) $s['readonly']['posts'] ) ) . '</p>';
			} else {
				echo '<p class="sseo-warn">! ' . esc_html__( 'Some posts changed while the scan ran (someone edited them, or another plugin or a scheduled post did). The scan itself writes only to its own tables.', 'semantic-seo-engine-vi-en' ) . ( array() !== $s['readonly']['changed_post_ids'] ? ' ' . esc_html( 'ID: ' . implode( ', ', array_map( 'intval', $s['readonly']['changed_post_ids'] ) ) ) : '' ) . '</p>';
			}
		}
		echo '<p><a href="' . esc_url( Ui::url( 'sseo-priorities' ) ) . '">' . esc_html__( 'See what to do first', 'semantic-seo-engine-vi-en' ) . '</a></p></div>';
	}

	/**
	 * Human duration.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( _n( '%d second', '%d seconds', max( 1, $seconds ), 'semantic-seo-engine-vi-en' ), max( 1, $seconds ) );
		}
		if ( $seconds < 3600 ) {
			$m = (int) ceil( $seconds / 60 );
			/* translators: %d: minutes */
			return sprintf( _n( '%d minute', '%d minutes', $m, 'semantic-seo-engine-vi-en' ), $m );
		}
		/* translators: %s: hours */
		return sprintf( __( '%s hours', 'semantic-seo-engine-vi-en' ), number_format_i18n( $seconds / 3600, 1 ) );
	}

	/**
	 * Count cards.
	 *
	 * @param array $o Overview.
	 * @return void
	 */
	private static function cards( array $o ) {
		$c = $o['counts'];
		echo '<h2 class="sseo-section-title">' . esc_html__( 'SEO health', 'semantic-seo-engine-vi-en' ) . '</h2><div class="sseo-cards">';

		$langs = array();
		foreach ( (array) $c['by_language'] as $l => $n ) {
			$langs[] = strtoupper( (string) $l ) . ': ' . number_format_i18n( (int) $n );
		}
		self::card(
			__( 'Pages analyzed', 'semantic-seo-engine-vi-en' ),
			number_format_i18n( (int) $c['analyzed'] ) . ' / ' . number_format_i18n( (int) $c['inventoried'] ),
			implode( ' · ', $langs ),
			__( 'Published pages read by the last scan, by language. Vietnamese and English pages are analyzed separately and never compared as competitors.', 'semantic-seo-engine-vi-en' ),
			Ui::url( 'sseo-matrix' )
		);

		$p     = (array) $c['priorities'];
		$parts = array();
		foreach ( array( 1, 2, 3, 4 ) as $n ) {
			$parts[] = PriorityQueue::priority_label( $n ) . ': ' . number_format_i18n( isset( $p[ $n ] ) ? (int) $p[ $n ] : 0 );
		}
		/* translators: 1: verified errors, 2: heuristic warnings, 3: editorial suggestions */
		$kinds = sprintf( __( '%1$d verified errors · %2$d warnings to check · %3$d suggestions', 'semantic-seo-engine-vi-en' ), (int) $c['kinds']['verified'], (int) $c['kinds']['heuristic'], (int) $c['kinds']['editorial'] );
		self::card(
			__( 'Issues by priority', 'semantic-seo-engine-vi-en' ),
			number_format_i18n( array_sum( $p ) ),
			implode( ' · ', $parts ) . '. ' . $kinds,
			__( 'Verified errors are facts (e.g. a link to a deleted post). Warnings are signals a person should check. Suggestions are editorial ideas.', 'semantic-seo-engine-vi-en' ),
			Ui::url( 'sseo-priorities' )
		);

		/* translators: 1: pages with few links in their topic group, 2: link suggestions */
		$sub = sprintf( __( '%1$d pages with few links inside their topic group · %2$d link suggestions', 'semantic-seo-engine-vi-en' ), (int) $c['few_links'], (int) $c['link_suggestions'] );
		self::card(
			__( 'Pages without internal links pointing to them', 'semantic-seo-engine-vi-en' ),
			number_format_i18n( (int) $c['orphans'] ),
			$sub,
			__( 'Counted from links inside post content and category descriptions only; links in menus, widgets and theme templates are not seen. Links are never added automatically.', 'semantic-seo-engine-vi-en' ),
			Ui::url( 'sseo-priorities', array( 'category' => 'links' ) )
		);

		/* translators: %d: pairs of pages */
		$pairs = sprintf( __( '%d pairs of pages to compare', 'semantic-seo-engine-vi-en' ), (int) $c['overlap_pairs'] );
		self::card(
			__( 'Pages that may compete for the same keyword or intent', 'semantic-seo-engine-vi-en' ),
			number_format_i18n( (int) $c['overlap_pages'] ),
			$pairs,
			__( 'A risk hypothesis from the content, not a confirmed problem: check Search Console before acting. The plugin never merges, deletes, redirects or changes slugs because pages look similar.', 'semantic-seo-engine-vi-en' ),
			Ui::url( 'sseo-priorities', array( 'category' => 'overlap' ) )
		);

		self::card(
			__( 'Pages whose content could be improved', 'semantic-seo-engine-vi-en' ),
			number_format_i18n( (int) $c['quality_pages'] ),
			__( 'Short for the search intent, no subheadings, images without alt text, no sources…', 'semantic-seo-engine-vi-en' ),
			__( 'Editorial hints: length or structure alone does not make a page good or bad.', 'semantic-seo-engine-vi-en' ),
			Ui::url( 'sseo-priorities', array( 'category' => 'content' ) )
		);

		self::card(
			__( 'Verified technical errors', 'semantic-seo-engine-vi-en' ),
			number_format_i18n( (int) $c['technical_errors'] ),
			__( 'Broken internal links, missing titles, conflicting canonicals…', 'semantic-seo-engine-vi-en' ),
			__( 'Facts checked in your database: fix these first.', 'semantic-seo-engine-vi-en' ),
			Ui::url( 'sseo-priorities', array( 'priority' => '1' ) )
		);
		echo '</div>';
	}

	/**
	 * One card.
	 *
	 * @param string $title Title.
	 * @param string $big   Main number.
	 * @param string $sub   Line under the number.
	 * @param string $help  Short explanation.
	 * @param string $link  Detail URL.
	 * @return void
	 */
	private static function card( $title, $big, $sub, $help, $link ) {
		echo '<section class="sseo-card"><h3>' . esc_html( $title ) . '</h3><p class="sseo-big">' . esc_html( $big ) . '</p><p>' . esc_html( $sub ) . '</p><p class="sseo-help">' . esc_html( $help ) . '</p><p><a href="' . esc_url( $link ) . '">' . esc_html__( 'See details', 'semantic-seo-engine-vi-en' ) . '</a></p></section>';
	}

	/**
	 * Top of the priority queue.
	 *
	 * @return void
	 */
	private static function priorities() {
		$res = PriorityQueue::items(
			array(
				'limit'       => 10,
				'public_only' => ! Api::can_see_private(),
			)
		);
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'What to do first', 'semantic-seo-engine-vi-en' ) . '</h2><p class="description">' . esc_html__( 'Ordered by verified errors first, then likely problems, linking work and editorial improvements. A work plan, not a ranking prediction.', 'semantic-seo-engine-vi-en' ) . '</p>';
		PrioritiesPage::table( $res['items'] );
		/* translators: %s: number of items */
		echo '<p><a class="button" href="' . esc_url( Ui::url( 'sseo-priorities' ) ) . '">' . esc_html( sprintf( __( 'See all %s items, filter and export', 'semantic-seo-engine-vi-en' ), number_format_i18n( (int) $res['total'] ) ) ) . '</a></p></section>';
	}

	/**
	 * Comparison with the previous site-wide scan.
	 *
	 * @param array $o Overview.
	 * @return void
	 */
	private static function diff( array $o ) {
		$d = $o['diff'];
		if ( null === $d ) {
			return;
		}
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Compared with the previous scan', 'semantic-seo-engine-vi-en' ) . '</h2>';
		if ( null === $d['previous'] ) {
			echo '<p>' . esc_html__( 'This is the first full scan: the next one will be compared with it.', 'semantic-seo-engine-vi-en' ) . '</p></section>';
			return;
		}
		/* translators: 1: new findings, 2: resolved findings */
		echo '<p>' . esc_html( sprintf( __( '%1$d new findings, %2$d resolved since the previous scan.', 'semantic-seo-engine-vi-en' ), (int) $d['new_count'], (int) $d['resolved_count'] ) ) . '</p><ul class="sseo-counts">';
		foreach ( (array) $d['priorities'] as $p => $v ) {
			echo '<li><span class="sseo-prio sseo-prio--' . (int) $p . '">' . esc_html( PriorityQueue::priority_label( (int) $p ) ) . '</span> ' . esc_html( (int) $v['before'] . ' → ' . (int) $v['after'] ) . '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * AI model card with a device check that downloads nothing.
	 *
	 * @param array $o Overview.
	 * @return void
	 */
	private static function qwen( array $o ) {
		$q = $o['qwen'];
		echo '<section class="sseo-card sseo-card--wide"><h3>' . esc_html__( 'AI model Qwen3-Embedding-0.6B (optional)', 'semantic-seo-engine-vi-en' ) . '</h3><p class="sseo-big sseo-big--text">' . esc_html( $q['label'] ) . '</p><p>' . esc_html( $q['detail'] ) . '</p>';
		echo '<p><button type="button" class="button" id="sseo-device-check">' . esc_html__( 'Check this device', 'semantic-seo-engine-vi-en' ) . '</button> <span id="sseo-device-result" aria-live="polite"></span></p>';
		echo '<p class="sseo-help">' . esc_html__( 'The check reads your browser capabilities only (WebGPU, WebAssembly, memory); nothing is downloaded. No paid AI service or API key is used.', 'semantic-seo-engine-vi-en' ) . '</p>';
		echo '<p><a href="' . esc_url( Ui::url( 'sseo-jobs' ) ) . '">' . esc_html__( 'Start the model on this device', 'semantic-seo-engine-vi-en' ) . '</a></p></section>';
	}

	/**
	 * What is and is not allowed.
	 *
	 * @param array $o Overview.
	 * @return void
	 */
	private static function permissions( array $o ) {
		echo '<section class="sseo-card sseo-card--wide"><h3>' . esc_html__( 'What the plugin may do', 'semantic-seo-engine-vi-en' ) . '</h3><ul class="sseo-allowed">';
		foreach ( $o['permissions']['allowed'] as $a ) {
			echo '<li class="sseo-ok">✓ ' . esc_html( $a ) . '</li>';
		}
		echo '</ul><h3>' . esc_html__( 'What it never does on its own', 'semantic-seo-engine-vi-en' ) . '</h3><ul class="sseo-not-allowed">';
		foreach ( $o['permissions']['not_allowed'] as $a ) {
			echo '<li>✕ ' . esc_html( $a ) . '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Scope settings (advanced).
	 *
	 * @return void
	 */
	private static function scope_form() {
		$scope = InventoryScanner::scope_counts();
		echo '<details class="sseo-panel"><summary>' . esc_html__( 'Scope of the analysis (advanced)', 'semantic-seo-engine-vi-en' ) . '</summary>';
		Ui::form_open( 'wizard' );
		echo '<input type="hidden" name="step" value="scope"><input type="hidden" name="form" value="scope">';
		echo '<fieldset><legend>' . esc_html__( 'Content types', 'semantic-seo-engine-vi-en' ) . '</legend>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $t ) {
			if ( 'attachment' === $t->name ) {
				continue;
			}
			echo '<label><input type="checkbox" name="sseo[post_types][]" value="' . esc_attr( $t->name ) . '"' . checked( in_array( $t->name, (array) Settings::get( 'post_types' ), true ), true, false ) . '> ' . esc_html( $t->labels->name ) . '</label> ';
		}
		echo '</fieldset><p><label for="sseo-ex">' . esc_html__( 'Exclude URL path fragments (one per line)', 'semantic-seo-engine-vi-en' ) . '</label><br><textarea id="sseo-ex" name="sseo[exclude_url_patterns]" rows="3" cols="50">' . esc_textarea( implode( "\n", (array) Settings::get( 'exclude_url_patterns' ) ) ) . '</textarea></p>';
		echo '<p><label for="sseo-bs">' . esc_html__( 'Batch size', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-bs" type="number" min="1" max="200" name="sseo[batch_size]" value="' . esc_attr( (string) Settings::get( 'batch_size' ) ) . '"> <label for="sseo-mx">' . esc_html__( 'Max documents', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-mx" type="number" min="10" name="sseo[max_documents]" value="' . esc_attr( (string) Settings::get( 'max_documents' ) ) . '"></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: posts, 2: terms */ __( 'In scope now: %1$d posts/pages and %2$d category archives (real database counts).', 'semantic-seo-engine-vi-en' ), $scope['posts'], $scope['terms'] ) ) . '</p>';
		echo '<button class="button">' . esc_html__( 'Save scope', 'semantic-seo-engine-vi-en' ) . '</button></form></details>';
	}

	/**
	 * Post IDs of the trial: the most recently updated published posts in scope.
	 *
	 * @return int[]
	 */
	public static function pilot_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Settings::get( 'post_types', array( 'post', 'page' ) ),
					'post_status'    => 'publish',
					'posts_per_page' => self::PILOT_SIZE,
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			)
		);
	}
}
