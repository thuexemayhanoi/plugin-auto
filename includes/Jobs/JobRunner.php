<?php
/**
 * Executes jobs in short, bounded, resumable slices.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Database\Db;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron only schedules short ticks; admin pages and WP-CLI can also drive ticks. Each tick
 * claims a lease, runs batches until the time budget is spent and records a heartbeat per batch.
 * A crashed tick simply lets the lease expire; the next tick resumes from the stored cursor.
 */
final class JobRunner {

	const HOOK      = 'sseo_run_jobs';
	const LEASE_TTL = 90;

	/**
	 * Handler classes by job type.
	 */
	const HANDLERS = array(
		'inventory'    => InventoryJob::class,
		'analyze'      => AnalyzeJob::class,
		'embed'        => EmbedJob::class,
		'incremental'  => IncrementalJob::class,
		'verify_links' => VerifyLinksJob::class,
	);

	/**
	 * Registers the cron hook.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'run_cron' ) );
	}

	/**
	 * Cron entry point.
	 *
	 * @return void
	 */
	public static function run_cron() {
		self::tick( (int) Settings::get( 'time_budget', 20 ) );
		self::schedule();
	}

	/**
	 * Schedules a near-future tick when runnable work exists.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( JobRepository::next_runnable() > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Runs one tick.
	 *
	 * @param int      $budget Seconds.
	 * @param int|null $job_id Specific job (admin "advance" button) or null for FIFO.
	 * @return array{job_id:int,status:string,batches:int,message:string}
	 */
	public static function tick( $budget, $job_id = null ) {
		$job_id = null === $job_id ? JobRepository::next_runnable() : (int) $job_id;
		if ( $job_id <= 0 ) {
			return array(
				'job_id'  => 0,
				'status'  => 'idle',
				'batches' => 0,
				'message' => 'no runnable job',
			);
		}
		$owner = Util::token( 16 );
		if ( ! JobRepository::claim( $job_id, $owner, self::LEASE_TTL ) ) {
			$job = JobRepository::get( $job_id );
			return array(
				'job_id'  => $job_id,
				'status'  => null === $job ? 'missing' : (string) $job['status'],
				'batches' => 0,
				'message' => 'not claimable (leased by another worker, paused, finished or backing off)',
			);
		}
		$job = JobRepository::get( $job_id );
		/**
		 * Filters job handler classes (type => class with static init/step/finished).
		 *
		 * @param array<string,string> $handlers Handlers.
		 */
		$handlers = (array) apply_filters( 'sseo_job_handlers', self::HANDLERS );
		$class    = isset( $handlers[ $job['type'] ] ) && class_exists( $handlers[ $job['type'] ] ) ? $handlers[ $job['type'] ] : null;
		$start    = microtime( true );
		$budget   = max( 1, (int) $budget );
		$batches  = 0;
		$state    = $job['state'];
		$done     = (int) $job['progress_done'];
		$total    = (int) $job['progress_total'];
		if ( null === $class ) {
			JobRepository::fail( $job_id, $owner, 'Unknown job type' );
			return array(
				'job_id'  => $job_id,
				'status'  => 'failed',
				'batches' => 0,
				'message' => 'unknown type',
			);
		}
		$batch = (int) Settings::get( 'batch_size', 25 );
		$delay = 0;
		Db::take_errors();
		try {
			if ( empty( $state['initialized'] ) ) {
				$init = call_user_func( array( $class, 'init' ), $job );
				self::assert_no_db_error( $job );
				$state                = array_merge( $state, $init['state'] );
				$state['initialized'] = true;
				$total                = (int) $init['total'];
			}
			while ( true ) {
				$job['state'] = $state;
				$res          = call_user_func( array( $class, 'step' ), $job, $batch );
				// A batch whose writes failed is not progress: fail it (retry with backoff) instead of
				// saving its state, so a lost write is redone rather than reported as done.
				self::assert_no_db_error( $job );
				++$batches;
				$state = $res['state'];
				$done += (int) $res['done'];
				if ( isset( $res['total'] ) ) {
					$total = (int) $res['total'];
				}
				if ( ! empty( $res['errors'] ) ) {
					$state['errors'] = array_slice( array_merge( isset( $state['errors'] ) ? (array) $state['errors'] : array(), $res['errors'] ), -50 );
				}
				if ( ! empty( $res['finished'] ) ) {
					$state['phase'] = 'done';
					JobRepository::heartbeat( $job_id, $owner, $state, max( $done, $total ), max( $done, $total ), self::LEASE_TTL );
					JobRepository::complete( $job_id, $owner, $state );
					call_user_func( array( $class, 'finished' ), JobRepository::get( $job_id ) );
					AuditLog::record( 'job.completed', 'job', $job_id, 'ok', array( 'type' => $job['type'] ) );
					return array(
						'job_id'  => $job_id,
						'status'  => 'completed',
						'batches' => $batches,
						'message' => '',
					);
				}
				if ( ! JobRepository::heartbeat( $job_id, $owner, $state, $done, max( $total, $done ), self::LEASE_TTL ) ) {
					// Cancelled or paused while we worked: stop without overwriting.
					$fresh = JobRepository::get( $job_id );
					return array(
						'job_id'  => $job_id,
						'status'  => null === $fresh ? 'missing' : (string) $fresh['status'],
						'batches' => $batches,
						'message' => 'lease lost',
					);
				}
				if ( ! empty( $res['yield'] ) ) {
					$delay = isset( $res['delay'] ) ? (int) $res['delay'] : 0;
					break;
				}
				if ( ( microtime( true ) - $start ) >= $budget ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$status = JobRepository::fail( $job_id, $owner, $e->getMessage() );
			AuditLog::record( 'job.failed', 'job', $job_id, 'error', array( 'error' => Util::truncate( $e->getMessage(), 300 ) ) );
			return array(
				'job_id'  => $job_id,
				'status'  => $status,
				'batches' => $batches,
				'message' => Util::truncate( $e->getMessage(), 300 ),
			);
		}
		JobRepository::release( $job_id, $owner, $delay );
		return array(
			'job_id'  => $job_id,
			'status'  => 'running',
			'batches' => $batches,
			'message' => $delay > 0 ? 'waiting for external workers' : 'time budget reached; will resume',
		);
	}

	/**
	 * Throws when a database error occurred since the last check.
	 *
	 * @param array $job Job.
	 * @return void
	 * @throws \RuntimeException On a database error.
	 */
	private static function assert_no_db_error( array $job ) {
		$errors = Db::take_errors();
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored as the job's last_error, escaped where displayed.
			throw new \RuntimeException( sprintf( 'Database error during the %s job (%d in this batch): %s', $job['type'], count( $errors ), end( $errors ) ) );
		}
	}

	/**
	 * Runs a job to completion (WP-CLI / tests). Bounded by a maximum number of ticks.
	 *
	 * @param int $job_id    Job ID.
	 * @param int $max_ticks Safety bound.
	 * @return array Last tick result.
	 */
	public static function run_to_completion( $job_id, $max_ticks = 10000 ) {
		$last = array();
		for ( $i = 0; $i < $max_ticks; $i++ ) {
			$last = self::tick( 30, $job_id );
			if ( in_array( $last['status'], array( 'completed', 'failed', 'cancelled', 'paused', 'missing' ), true ) ) {
				break;
			}
			if ( 'queued' === $last['status'] ) {
				break; // Backing off after an error.
			}
			// One long process (WP-CLI) would otherwise keep every post, term and meta row it read in
			// WordPress' in-memory object cache: peak 329 MB for 6,000 synthetic posts.
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		return $last;
	}
}
