<?php
/**
 * Database errors during a background job: the job must not report success when its results were
 * not stored; a transient error is retried and the job then completes with complete results.
 *
 * Failures are produced by MariaDB itself: WordPress' `query` filter redirects selected writes to a
 * table that does not exist, as a lock timeout, lost connection or full disk would make them fail.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;

/**
 * @group jobs
 */
final class DatabaseFailureTest extends TestCase {

	/**
	 * Remaining finding writes to break (-1: all).
	 *
	 * @var int
	 */
	private $break_writes = 0;

	/**
	 * Breaks INSERT statements into the findings table.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function break_finding_writes( $sql ) {
		if ( 0 !== $this->break_writes && 0 === stripos( ltrim( $sql ), 'INSERT INTO' ) && false !== strpos( $sql, 'sseo_findings' ) ) {
			if ( $this->break_writes > 0 ) {
				--$this->break_writes;
			}
			return 'INSERT INTO sseo_table_that_does_not_exist (id) VALUES (1)';
		}
		return $sql;
	}

	public function test_analysis_with_failing_writes_does_not_report_success() {
		global $wpdb;
		$this->load_corpus();
		$this->run_inventory();
		$state_before = get_option( 'sseo_state' );

		$job                = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		$this->break_writes = -1;
		add_filter( 'query', array( $this, 'break_finding_writes' ) );
		$suppress = $wpdb->suppress_errors( true );
		for ( $i = 0; $i < 10; $i++ ) {
			JobRunner::run_to_completion( (int) $job['id'], 50 );
			Db::query( 'UPDATE {jobs} SET next_run_at = NULL WHERE id = %d', array( (int) $job['id'] ) ); // Skip the backoff wait.
			if ( in_array( JobRepository::get( (int) $job['id'] )['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', array( $this, 'break_finding_writes' ) );

		$j = JobRepository::get( (int) $job['id'] );
		$this->assertSame( 'failed', $j['status'], 'a job whose findings could not be stored is not "completed"' );
		$this->assertStringContainsString( 'sseo_table_that_does_not_exist', (string) $j['last_error'], 'the database error is reported' );
		$this->assertSame( (int) $j['max_attempts'], (int) $j['attempts'], 'retried with backoff before giving up' );
		$state = get_option( 'sseo_state' );
		$this->assertSame( isset( $state_before['last_analysis_at'] ) ? $state_before['last_analysis_at'] : null, isset( $state['last_analysis_at'] ) ? $state['last_analysis_at'] : null, 'no "last analysis" date for a failed analysis' );
	}

	public function test_transient_write_failure_is_retried_and_results_are_complete() {
		global $wpdb;
		$this->load_corpus();
		$this->run_inventory();

		// Reference: the same analysis without failures.
		$job = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		JobRunner::run_to_completion( (int) $job['id'] );
		$expected = (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open'" );
		$this->assertGreaterThan( 0, $expected );
		Db::query( 'DELETE FROM {findings}' );

		$job                = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		$this->break_writes = 3;
		add_filter( 'query', array( $this, 'break_finding_writes' ) );
		$suppress = $wpdb->suppress_errors( true );
		for ( $i = 0; $i < 10; $i++ ) {
			JobRunner::run_to_completion( (int) $job['id'], 50 );
			Db::query( 'UPDATE {jobs} SET next_run_at = NULL WHERE id = %d', array( (int) $job['id'] ) );
			if ( in_array( JobRepository::get( (int) $job['id'] )['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}
		}
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', array( $this, 'break_finding_writes' ) );

		$j = JobRepository::get( (int) $job['id'] );
		$this->assertSame( 'completed', $j['status'] );
		$this->assertGreaterThan( 0, (int) $j['attempts'], 'the failed batch was retried' );
		$this->assertSame( $expected, (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open'" ), 'nothing lost after the retry' );
	}

	/**
	 * Runs the inventory to completion.
	 *
	 * @return void
	 */
	private function run_inventory() {
		$job = JobRepository::create( 'inventory', array(), $this->admin, 'test' );
		$res = JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'] );
	}
}
