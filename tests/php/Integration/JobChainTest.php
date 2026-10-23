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
}
