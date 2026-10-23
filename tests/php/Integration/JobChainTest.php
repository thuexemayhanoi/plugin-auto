<?php
/**
 * Scan chaining regressions found by the beta.4 audit:
 * D1 a cancel or pause during a job's last batch was ignored and the analysis still chained;
 * D2 a full scan started while a pilot analysis was active silently dropped its site-wide analysis;
 * D3 a scan could start on an incomplete database and fail later.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\ScanHistory;
use SemanticSEO\Database\Db;
use SemanticSEO\Database\Migrator;
use SemanticSEO\Database\Schema;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Services\Api;

/**
 * @group jobs
 */
final class JobChainTest extends TestCase {

	/**
	 * Analyze jobs, oldest first.
	 *
	 * @return array[]
	 */
	private function analyze_jobs() {
		return array_reverse(
			JobRepository::find(
				array(
					'type'  => 'analyze',
					'limit' => 20,
				)
			)
		);
	}

	/**
	 * Twenty published post IDs of the corpus.
	 *
	 * @return int[]
	 */
	private function pilot_ids() {
		$this->load_corpus();
		return array_slice( array_map( 'intval', array_values( $this->posts ) ), 0, 20 );
	}

	/**
	 * Inventoried documents of some posts (drafts of the corpus are not inventoried).
	 *
	 * @param int[] $ids Post IDs.
	 * @return int
	 */
	private function inventoried( array $ids ) {
		return (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE object_type = 'post' AND object_id IN (" . Db::in( $ids ) . ')', $ids );
	}

	/**
	 * Runs a job until it stops being runnable in this process.
	 *
	 * @param int $id Job ID.
	 * @return array Last tick.
	 */
	private function run_job( $id ) {
		$last = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$last = JobRunner::tick( 30, $id );
			if ( 'running' !== $last['status'] || 0 === $last['batches'] ) {
				break;
			}
		}
		return $last;
	}

	/**
	 * Runs a control operation from inside the job's last batch (an admin clicking during a tick).
	 *
	 * @param string $op Operation.
	 * @return array{0:int,1:array,2:int[]} Job ID, the last tick and the pilot post IDs.
	 */
	private function control_during_last_batch( $op ) {
		$ids   = $this->pilot_ids();
		$job   = Api::start_analysis(
			array(
				'type'         => 'inventory',
				'post_ids'     => $ids,
				'then_analyze' => true,
				'pilot'        => true,
			)
		);
		$jid   = (int) $job['id'];
		$last  = end( $ids );
		$fired = false;
		$cb    = static function ( $link, $post ) use ( $last, $jid, $op, &$fired ) {
			$pid = is_object( $post ) ? (int) $post->ID : (int) $post;
			if ( ! $fired && $pid === $last ) {
				$fired = true;
				Api::job_control( $jid, $op );
			}
			return $link;
		};
		add_filter( 'post_link', $cb, 10, 2 );
		add_filter( 'page_link', $cb, 10, 2 );
		$tick = $this->run_job( $jid );
		remove_filter( 'post_link', $cb, 10 );
		remove_filter( 'page_link', $cb, 10 );
		$this->assertTrue( $fired, 'the control ran inside a batch' );
		return array( $jid, $tick, $ids );
	}

	public function test_d1_a_cancel_during_the_last_batch_wins_and_chains_nothing() {
		list( $jid, $tick ) = $this->control_during_last_batch( 'cancel' );
		$this->assertSame( 'cancelled', JobRepository::get( $jid )['status'] );
		$this->assertNotSame( 'completed', $tick['status'], 'the tick does not report a completion' );
		$this->assertSame( array(), $this->analyze_jobs(), 'no analysis chained after a cancel' );
		$this->assertSame( array(), ScanHistory::all() );
	}

	public function test_d1_a_pause_during_the_last_batch_chains_once_after_resume() {
		list( $jid, , $ids ) = $this->control_during_last_batch( 'pause' );
		$this->assertSame( 'paused', JobRepository::get( $jid )['status'] );
		$this->assertSame( array(), $this->analyze_jobs(), 'nothing chained while paused' );

		Api::job_control( $jid, 'resume' );
		$this->run_job( $jid );
		$this->assertSame( 'completed', JobRepository::get( $jid )['status'] );
		$analyses = $this->analyze_jobs();
		$this->assertCount( 1, $analyses, 'exactly one analysis for one pilot' );
		$this->assertCount( $this->inventoried( $ids ), $analyses[0]['params']['doc_ids'] );
		$this->assertTrue( $analyses[0]['params']['pilot'] );
		$this->assertSame( $jid, $analyses[0]['params']['source_job'] );
	}

	public function test_d2_a_full_scan_after_a_waiting_pilot_analysis_still_analyses_the_whole_site() {
		$ids   = $this->pilot_ids();
		$pilot = Api::start_analysis(
			array(
				'type'         => 'inventory',
				'post_ids'     => $ids,
				'then_analyze' => true,
				'pilot'        => true,
			)
		);
		$this->run_job( (int) $pilot['id'] );
		$pilot_analysis = $this->analyze_jobs()[0];
		Api::job_control( (int) $pilot_analysis['id'], 'pause' );

		$full = Api::start_analysis(
			array(
				'type'         => 'inventory',
				'then_analyze' => true,
			)
		);
		$this->assertIsArray( $full, wp_json_encode( $full ) );
		$this->run_job( (int) $full['id'] );

		$this->assertSame( 'cancelled', JobRepository::get( (int) $pilot_analysis['id'] )['status'], 'the waiting pilot analysis is superseded' );
		$analyses = $this->analyze_jobs();
		$site     = end( $analyses );
		$this->assertArrayNotHasKey( 'doc_ids', $site['params'], 'a site-wide analysis is queued' );
		$this->run_job( (int) $site['id'] );
		$this->assertSame( 'completed', JobRepository::get( (int) $site['id'] )['status'] );
		$this->assertSame( 0, (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND analyzed_at IS NULL" ), 'every published page analyzed' );
		$this->assertSame( 'site', ScanHistory::latest()['scope'] );
	}

	public function test_d2_an_analysis_blocked_by_an_active_site_wide_one_is_kept_pending_and_started_later() {
		$ids  = $this->pilot_ids();
		$site = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		Api::job_control( (int) $site['id'], 'pause' );

		$pilot = Api::start_analysis(
			array(
				'type'         => 'inventory',
				'post_ids'     => $ids,
				'then_analyze' => true,
				'pilot'        => true,
			)
		);
		$this->run_job( (int) $pilot['id'] );
		$pending = get_option( JobRepository::PENDING_OPTION );
		$this->assertIsArray( $pending, 'kept pending, not dropped' );
		$this->assertCount( $this->inventoried( $ids ), $pending['params']['doc_ids'] );

		// Cancelling the blocking analysis starts the pending one.
		Api::job_control( (int) $site['id'], 'cancel' );
		$this->assertFalse( get_option( JobRepository::PENDING_OPTION, false ) );
		$analyses = $this->analyze_jobs();
		$started  = end( $analyses );
		$this->assertSame( 'queued', $started['status'] );
		$this->assertSame( $pending['params']['doc_ids'], $started['params']['doc_ids'] );
	}

	public function test_d3_no_scan_starts_on_an_incomplete_database() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( 'embeddings' ) ); // phpcs:ignore WordPress.DB
		$res = Api::start_analysis(
			array(
				'type'         => 'inventory',
				'then_analyze' => true,
			)
		);
		Migrator::migrate();
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_db_not_ready', $res->get_error_code() );
		$this->assertSame( 503, $res->get_error_data()['status'] );
		$this->assertSame( array( 'embeddings' ), $res->get_error_data()['missing'] );
		$this->assertSame( array(), JobRepository::find( array( 'limit' => 5 ) ), 'no job created' );
	}

	public function test_pending_scopes_merged_beyond_one_analysis_become_site_wide() {
		// An analysis is active, so every chained request is kept pending.
		$this->assertIsArray( JobRepository::create( 'analyze', array( 'doc_ids' => array( 1 ) ), $this->admin, 'test' ) );
		$this->assertNull( JobRepository::chain_analysis( array( 'doc_ids' => range( 1, 300 ) ), $this->admin ) );
		$this->assertCount( 300, get_option( JobRepository::PENDING_OPTION )['params']['doc_ids'] );
		$this->assertNull( JobRepository::chain_analysis( array( 'doc_ids' => range( 301, 600 ) ), $this->admin ) );
		$params = get_option( JobRepository::PENDING_OPTION )['params'];
		$this->assertArrayNotHasKey( 'doc_ids', $params, '600 pages exceed one scoped analysis: the whole site is analysed, none left out' );

		// A pending site-wide request covers a later scoped one and keeps its own inventory.
		update_option(
			JobRepository::PENDING_OPTION,
			array(
				'params'   => array( 'source_job' => 77 ),
				'owner_id' => $this->admin,
				'at'       => '2026-01-01 00:00:00',
			),
			false
		);
		JobRepository::chain_analysis(
			array(
				'doc_ids'    => array( 5 ),
				'pilot'      => true,
				'source_job' => 78,
			),
			$this->admin
		);
		$this->assertSame( array( 'source_job' => 77 ), get_option( JobRepository::PENDING_OPTION )['params'] );
	}

	public function test_a_pending_request_stored_by_another_process_is_started() {
		global $wpdb;
		// This process cached "no pending request"; another process (WP-Cron next to an open
		// dashboard) then stores one.
		$this->assertFalse( get_option( JobRepository::PENDING_OPTION ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->options,
			array(
				'option_name'  => JobRepository::PENDING_OPTION,
				'option_value' => maybe_serialize(
					array(
						'params'   => array( 'doc_ids' => array( 3 ) ),
						'owner_id' => $this->admin,
						'at'       => '2026-01-01 00:00:00',
					)
				),
				'autoload'     => 'no',
			)
		);
		$this->assertGreaterThan( 0, JobRepository::start_pending_analysis(), 'read from the database, not the option cache' );
		$this->assertFalse( get_option( JobRepository::PENDING_OPTION ) );
		$this->assertSame( array( 3 ), $this->analyze_jobs()[0]['params']['doc_ids'] );
	}

	public function test_a_trial_whose_pages_are_not_inventoried_never_analyses_the_whole_site() {
		$this->load_corpus();
		$job = JobRepository::create(
			'inventory',
			array(
				'post_ids'     => array( 987654 ),
				'then_analyze' => true,
				'pilot'        => true,
			),
			$this->admin,
			'test'
		);
		JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( array(), $this->analyze_jobs(), 'no analysis (an empty scope used to mean the whole site)' );
		$this->assertFalse( get_option( JobRepository::PENDING_OPTION ) );
		$inv = JobRepository::get( (int) $job['id'] );
		$this->assertSame( 'failed', $inv['status'] );
		$this->assertStringContainsString( 'no analysis was started', $inv['last_error'] );
	}
}
