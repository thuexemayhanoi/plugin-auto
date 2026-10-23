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
	 * First batch of a job while its time per item is unknown.
	 */
	const FIRST_BATCH = 5;

	/**
	 * Lease held by this process ([job ID, owner]) while a tick runs, for the fatal-error handler.
	 *
	 * @var array|null
	 */
	private static $running = null;

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
		JobRepository::start_pending_analysis();
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
		// Batches are sized from the measured time per item (kept in the job state), so one batch
		// cannot run far past the budget on a large site, where the host's request time limit would
		// kill the tick before its progress is saved.
		$max_batch = max( 1, (int) Settings::get( 'batch_size', 25 ) );
		$batch     = isset( $state['seconds_per_item'] ) ? self::fit_batch( $max_batch, (float) $state['seconds_per_item'], $budget ) : min( $max_batch, self::FIRST_BATCH );
		$delay     = 0;
		Db::take_errors();
		self::watch_fatal( $job_id, $owner );
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
				$batch_start  = microtime( true );
				$res          = call_user_func( array( $class, 'step' ), $job, $batch );
				// A batch whose writes failed is not progress: fail it (retry with backoff) instead of
				// saving its state, so a lost write is redone rather than reported as done.
				self::assert_no_db_error( $job );
				++$batches;
				$speed = isset( $state['seconds_per_item'] ) ? (float) $state['seconds_per_item'] : null;
				$work  = isset( $state['work_seconds'] ) ? (float) $state['work_seconds'] : 0.0;
				$peak  = isset( $state['peak_memory'] ) ? (int) $state['peak_memory'] : 0;
				$state = $res['state'];
				// Measured cost (processing time without the waits between ticks, request peak memory):
				// the dashboard estimates a full scan from these real numbers.
				$state['work_seconds'] = round( $work + ( microtime( true ) - $batch_start ), 3 );
				$state['peak_memory']  = max( $peak, (int) memory_get_peak_usage() );
				if ( (int) $res['done'] > 0 ) {
					$measured = ( microtime( true ) - $batch_start ) / (int) $res['done'];
					$speed    = null === $speed ? $measured : ( $speed + $measured ) / 2;
				}
				if ( null !== $speed ) {
					$state['seconds_per_item'] = round( $speed, 4 );
				}
				$done += (int) $res['done'];
				if ( isset( $res['total'] ) ) {
					$total = (int) $res['total'];
				}
				if ( ! empty( $res['errors'] ) ) {
					$state['errors'] = array_slice( array_merge( isset( $state['errors'] ) ? (array) $state['errors'] : array(), $res['errors'] ), -50 );
				}
				if ( ! empty( $res['finished'] ) ) {
					$state['phase'] = 'done';
					// A cancel or pause during the last batch wins: no completion, no chained job.
					if ( ! JobRepository::heartbeat( $job_id, $owner, $state, max( $done, $total ), max( $done, $total ), self::LEASE_TTL ) || ! JobRepository::complete( $job_id, $owner, $state ) ) {
						$fresh = JobRepository::get( $job_id );
						return array(
							'job_id'  => $job_id,
							'status'  => null === $fresh ? 'missing' : (string) $fresh['status'],
							'batches' => $batches,
							'message' => 'lease lost',
						);
					}
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
				$left = $budget - ( microtime( true ) - $start );
				if ( $left <= 0 ) {
					break;
				}
				$batch = null === $speed ? $batch : self::fit_batch( $max_batch, $speed, $left );
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
		} finally {
			self::$running = null;
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
	 * Items that fit in a number of seconds at a measured speed (at least 1, at most the setting).
	 *
	 * @param int   $max     Batch size setting.
	 * @param float $speed   Seconds per item.
	 * @param float $seconds Seconds available.
	 * @return int
	 */
	private static function fit_batch( $max, $speed, $seconds ) {
		if ( $speed <= 0 ) {
			return $max;
		}
		return max( 1, min( $max, (int) floor( $seconds / $speed ) ) );
	}

	/**
	 * Records a PHP fatal error (memory limit, timeout, …) of the running tick as a failed attempt
	 * at shutdown, with its message, instead of leaving the lease to expire silently.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $owner  Lease owner.
	 * @return void
	 */
	private static function watch_fatal( $job_id, $owner ) {
		static $registered = false;
		self::$running     = array( (int) $job_id, (string) $owner );
		if ( $registered ) {
			return;
		}
		$registered = true;
		register_shutdown_function(
			static function () {
				$lease = self::$running;
				$error = error_get_last();
				if ( null === $lease || ! is_array( $error ) || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}
				try {
					JobRepository::fail( $lease[0], $lease[1], 'PHP fatal error: ' . Util::truncate( (string) $error['message'], 500 ) );
				} catch ( \Throwable $e ) {
					unset( $e ); // The lease expires and the next claim counts the attempt.
				}
			}
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
		$last    = array();
		$waiting = 0;
		for ( $i = 0; $i < $max_ticks; $i++ ) {
			$last = self::tick( 30, $job_id );
			if ( in_array( $last['status'], array( 'completed', 'failed', 'cancelled', 'paused', 'missing' ), true ) ) {
				break;
			}
			if ( 'queued' === $last['status'] ) {
				break; // Backing off after an error.
			}
			if ( 0 === $last['batches'] ) {
				$job = JobRepository::get( (int) $job_id );
				if ( null === $job || '' === (string) $job['lease_owner'] || strtotime( $job['lease_expires'] . ' UTC' ) < time() ) {
					break; // Scheduled to run later (waiting for workers or backing off).
				}
				// Another worker (WP-Cron) holds the lease: wait for it instead of spinning, and give
				// up once a whole lease period passed without this process getting a turn.
				if ( ++$waiting > self::LEASE_TTL ) {
					break;
				}
				sleep( 1 );
				continue;
			}
			$waiting = 0;
			// One long process (WP-CLI) would otherwise keep every post, term and meta row it read in
			// WordPress' in-memory object cache: peak 329 MB for 6,000 synthetic posts.
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		return $last;
	}
}
