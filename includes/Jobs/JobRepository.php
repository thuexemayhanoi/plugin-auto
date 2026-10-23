<?php
/**
 * Job persistence with leases, heartbeats, retries and idempotency.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Database\Db;
use SemanticSEO\Security\Mutex;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Status machine: queued → running ⇄ paused → completed | failed | cancelled.
 */
final class JobRepository {

	const TYPES          = array( 'inventory', 'analyze', 'embed', 'incremental', 'verify_links' );
	const PENDING_OPTION = 'sseo_pending_analysis';
	const ACTIVE         = array( 'queued', 'running', 'paused' );
	const FINISHED       = array( 'completed', 'failed', 'cancelled' );
	const CRASHED        = 'The previous run stopped without finishing its batch (PHP fatal error, timeout, memory limit or killed worker).';

	/**
	 * Job previously created by the same owner with the same idempotency key, if any.
	 *
	 * @param string $type            Job type.
	 * @param int    $owner_id        Owner user ID.
	 * @param string $idempotency_key Client key.
	 * @return array|null Job row with "reused" flag.
	 */
	public static function by_idempotency_key( $type, $owner_id, $idempotency_key ) {
		if ( '' === (string) $idempotency_key ) {
			return null;
		}
		$prior = Db::row( 'SELECT * FROM {jobs} WHERE idempotency_key = %s', array( self::idempotency_hash( $type, $owner_id, $idempotency_key ) ) );
		if ( null === $prior ) {
			return null;
		}
		$prior['reused'] = true;
		return self::decode( $prior );
	}

	/**
	 * Stored form of an idempotency key (scoped to job type and owner).
	 *
	 * @param string $type            Job type.
	 * @param int    $owner_id        Owner.
	 * @param string $idempotency_key Client key.
	 * @return string
	 */
	private static function idempotency_hash( $type, $owner_id, $idempotency_key ) {
		return Util::sha256( $type . '|' . (int) $owner_id . '|' . $idempotency_key );
	}

	/**
	 * Creates a job unless an identical idempotent request exists or a job of the type is active.
	 *
	 * @param string $type            Job type.
	 * @param array  $params          Parameters.
	 * @param int    $owner_id        Owner.
	 * @param string $via             Transport.
	 * @param string $idempotency_key Optional client key.
	 * @return array|\WP_Error
	 */
	public static function create( $type, array $params, $owner_id, $via, $idempotency_key = '' ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'sseo_invalid_job_type', __( 'Unknown job type.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$key   = '' === $idempotency_key ? null : self::idempotency_hash( $type, $owner_id, $idempotency_key );
		$prior = null === $key ? null : self::by_idempotency_key( $type, $owner_id, $idempotency_key );
		if ( null !== $prior ) {
			return $prior;
		}
		$lock = Mutex::acquire( 'job_create', 30 );
		if ( false === $lock ) {
			return new \WP_Error( 'sseo_busy', __( 'Another job is being created; retry shortly.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		try {
			$active = Db::row( 'SELECT * FROM {jobs} WHERE type = %s AND status IN (' . Db::in( self::ACTIVE, '%s' ) . ') ORDER BY id ASC LIMIT 1', array_merge( array( $type ), self::ACTIVE ) );
			if ( null !== $active ) {
				return new \WP_Error(
					'sseo_job_exists',
					__( 'A job of this type is already queued, running or paused.', 'semantic-seo-engine-vi-en' ),
					array(
						'status' => 409,
						'job_id' => (int) $active['id'],
					)
				);
			}
			$now = Util::now();
			$id  = Db::insert(
				'jobs',
				array(
					'type'            => $type,
					'status'          => 'queued',
					'owner_id'        => (int) $owner_id,
					'via'             => substr( (string) $via, 0, 24 ),
					'idempotency_key' => $key,
					'params'          => Util::json( $params ),
					'state'           => Util::json( array() ),
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
		} finally {
			Mutex::release( 'job_create', $lock );
		}
		if ( $id <= 0 ) {
			return new \WP_Error( 'sseo_db_error', __( 'Could not create the job.', 'semantic-seo-engine-vi-en' ), array( 'status' => 500 ) );
		}
		return self::get( $id );
	}

	/**
	 * Fetches a job.
	 *
	 * @param int $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = Db::row( 'SELECT * FROM {jobs} WHERE id = %d', array( (int) $id ) );
		return null === $row ? null : self::decode( $row );
	}

	/**
	 * Lists jobs (newest first).
	 *
	 * @param array $filters status, type, limit, before_id.
	 * @return array
	 */
	public static function find( array $filters = array() ) {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array_merge( self::ACTIVE, self::FINISHED ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['type'] ) && in_array( $filters['type'], self::TYPES, true ) ) {
			$where[] = 'type = %s';
			$args[]  = $filters['type'];
		}
		if ( ! empty( $filters['before_id'] ) ) {
			$where[] = 'id < %d';
			$args[]  = (int) $filters['before_id'];
		}
		$limit  = Util::clamp_int( isset( $filters['limit'] ) ? $filters['limit'] : 20, 1, 100 );
		$args[] = $limit;
		return array_map( array( __CLASS__, 'decode' ), Db::rows( 'SELECT * FROM {jobs} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args ) );
	}

	/**
	 * Atomically claims a runnable job lease.
	 *
	 * Taking over an expired lease means the previous tick stopped without releasing it (PHP fatal
	 * error, timeout, memory limit, killed worker): that counts as a failed attempt, so a job that
	 * crashes every time ends as "failed" after max_attempts instead of blocking its type forever.
	 *
	 * @param int    $id    Job ID.
	 * @param string $owner Lease owner token.
	 * @param int    $ttl   Lease seconds.
	 * @return bool
	 */
	public static function claim( $id, $owner, $ttl ) {
		$now = Util::now();
		Db::query(
			"UPDATE {jobs} SET attempts = attempts + 1, last_error = %s, status = 'failed', lease_owner = '', lease_expires = NULL, finished_at = %s, updated_at = %s
			WHERE id = %d AND status = 'running' AND lease_owner <> '' AND lease_expires < %s AND attempts + 1 >= max_attempts",
			array( self::CRASHED, $now, $now, (int) $id, $now )
		);
		// attempts and last_error are assigned first: MySQL evaluates SET left to right, so they must
		// read lease_owner / lease_expires before those are overwritten.
		$n = Db::query(
			"UPDATE {jobs} SET attempts = attempts + IF(lease_owner <> '' AND lease_expires < %s, 1, 0), last_error = IF(lease_owner <> '' AND lease_expires < %s, %s, last_error),
			status = 'running', lease_owner = %s, lease_expires = %s, heartbeat_at = %s, started_at = COALESCE(started_at, %s), updated_at = %s
			WHERE id = %d AND status IN ('queued','running') AND (lease_expires IS NULL OR lease_expires < %s OR lease_owner = '') AND (next_run_at IS NULL OR next_run_at <= %s)",
			array( $now, $now, self::CRASHED, $owner, Util::now( $ttl ), $now, $now, $now, (int) $id, $now, $now )
		);
		return 1 === $n;
	}

	/**
	 * Next runnable job ID (FIFO).
	 *
	 * @return int
	 */
	public static function next_runnable() {
		$now = Util::now();
		return (int) Db::value(
			"SELECT id FROM {jobs} WHERE status IN ('queued','running') AND (lease_expires IS NULL OR lease_expires < %s OR lease_owner = '') AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY id ASC LIMIT 1",
			array( $now, $now )
		);
	}

	/**
	 * Saves progress and extends the lease (heartbeat).
	 *
	 * @param int    $id    Job ID.
	 * @param string $owner Lease owner.
	 * @param array  $state State.
	 * @param int    $done  Progress done.
	 * @param int    $total Progress total.
	 * @param int    $ttl   Lease seconds.
	 * @return bool False when the lease was lost (cancelled/paused/taken over).
	 */
	public static function heartbeat( $id, $owner, array $state, $done, $total, $ttl ) {
		$n = Db::query(
			"UPDATE {jobs} SET state = %s, progress_done = %d, progress_total = %d, heartbeat_at = %s, lease_expires = %s, updated_at = %s WHERE id = %d AND lease_owner = %s AND status = 'running'",
			array( Util::json( $state ), max( 0, (int) $done ), max( 0, (int) $total ), Util::now(), Util::now( $ttl ), Util::now(), (int) $id, $owner )
		);
		return 1 === $n;
	}

	/**
	 * Releases a lease without finishing (time budget reached).
	 *
	 * @param int    $id    Job ID.
	 * @param string $owner Owner.
	 * @param int    $delay Seconds before the job may run again (0 = immediately).
	 * @return void
	 */
	public static function release( $id, $owner, $delay = 0 ) {
		if ( $delay > 0 ) {
			Db::query( "UPDATE {jobs} SET lease_owner = '', lease_expires = NULL, next_run_at = %s, updated_at = %s WHERE id = %d AND lease_owner = %s", array( Util::now( (int) $delay ), Util::now(), (int) $id, $owner ) );
			return;
		}
		Db::query( "UPDATE {jobs} SET lease_owner = '', lease_expires = NULL, next_run_at = NULL, updated_at = %s WHERE id = %d AND lease_owner = %s", array( Util::now(), (int) $id, $owner ) );
	}

	/**
	 * Marks a job completed.
	 *
	 * @param int    $id    Job ID.
	 * @param string $owner Owner.
	 * @param array  $state Final state.
	 * @return bool Whether this call completed the job.
	 */
	public static function complete( $id, $owner, array $state ) {
		// Only the lease holder of a still-running job completes it: a cancel or pause that landed
		// during the last batch wins, and the caller must not run the completion hook.
		return 1 === Db::query(
			"UPDATE {jobs} SET status = 'completed', state = %s, progress_done = progress_total, lease_owner = '', lease_expires = NULL, finished_at = %s, updated_at = %s, last_error = NULL WHERE id = %d AND lease_owner = %s AND status = 'running'",
			array( Util::json( $state ), Util::now(), Util::now(), (int) $id, $owner )
		);
	}

	/**
	 * Marks a completed job as failed after the fact (its follow-up could not run), with the reason
	 * shown on the dashboard.
	 *
	 * @param int    $id      Job ID.
	 * @param string $message Reason.
	 * @return bool
	 */
	public static function fail_completed( $id, $message ) {
		return 1 === Db::query( "UPDATE {jobs} SET status = 'failed', last_error = %s, updated_at = %s WHERE id = %d AND status = 'completed'", array( Util::truncate( (string) $message, 1000 ), Util::now(), (int) $id ) );
	}

	/**
	 * Queues an analysis after an inventory. When another analysis is already active, a narrower
	 * one (a pilot) that is not running yet is replaced by a site-wide one, which covers its pages;
	 * otherwise the request is kept as pending and started when no analysis is active any more —
	 * never silently dropped.
	 *
	 * @param array $params   Analysis parameters (doc_ids empty = site-wide).
	 * @param int   $owner_id Owner.
	 * @return array|null The created job, or null when it was left pending.
	 */
	public static function chain_analysis( array $params, $owner_id ) {
		$job = self::create( 'analyze', $params, (int) $owner_id, 'chain' );
		if ( ! is_wp_error( $job ) ) {
			return $job;
		}
		$data     = (array) $job->get_error_data();
		$existing = 'sseo_job_exists' === $job->get_error_code() && isset( $data['job_id'] ) ? self::get( (int) $data['job_id'] ) : null;
		if ( null !== $existing && empty( $params['doc_ids'] ) && ! empty( $existing['params']['doc_ids'] ) && in_array( $existing['status'], array( 'queued', 'paused' ), true ) && self::cancel( (int) $existing['id'] ) ) {
			$job = self::create( 'analyze', $params, (int) $owner_id, 'chain' );
			if ( ! is_wp_error( $job ) ) {
				return $job;
			}
		}
		// Read-merge-write under the same lock as start_pending_analysis(), so a request stored
		// while the pending one is being started is never deleted with it.
		$lock    = self::pending_lock();
		$pending = self::pending();
		if ( null !== $pending ) {
			$old = (array) $pending['params'];
			if ( empty( $old['doc_ids'] ) ) {
				// The pending request is site-wide: it covers this one (and keeps its own inventory).
				$params   = $old;
				$owner_id = (int) $pending['owner_id'];
			} elseif ( ! empty( $params['doc_ids'] ) ) {
				$merged = array_values( array_unique( array_map( 'intval', array_merge( (array) $old['doc_ids'], (array) $params['doc_ids'] ) ) ) );
				// Two scopes: the read-only evidence of one inventory does not cover both.
				unset( $params['source_job'] );
				if ( empty( $old['pilot'] ) ) {
					unset( $params['pilot'] );
				}
				if ( count( $merged ) > \SemanticSEO\Analysis\AnalysisRunner::SCOPE_LIMIT ) {
					// More pages than one scoped analysis takes: analyse the whole site instead of
					// silently leaving some out.
					unset( $params['doc_ids'], $params['pilot'] );
				} else {
					$params['doc_ids'] = $merged;
				}
			}
			// Otherwise this request is site-wide and covers the pending one.
		}
		update_option(
			self::PENDING_OPTION,
			array(
				'params'   => $params,
				'owner_id' => (int) $owner_id,
				'at'       => Util::now(),
			),
			false
		);
		if ( false !== $lock ) {
			Mutex::release( 'pending_analysis', $lock );
		}
		return null;
	}

	/**
	 * Starts the pending analysis once no analysis is active (called at every cron run).
	 *
	 * @return int Created job ID (0 = nothing to do or still blocked).
	 */
	public static function start_pending_analysis() {
		if ( null === self::pending() ) {
			return 0;
		}
		$lock = self::pending_lock();
		if ( false === $lock ) {
			return 0;
		}
		try {
			$pending = self::pending();
			if ( null === $pending ) {
				return 0;
			}
			$job = self::create( 'analyze', (array) $pending['params'], (int) $pending['owner_id'], 'chain' );
			if ( is_wp_error( $job ) ) {
				return 0;
			}
			delete_option( self::PENDING_OPTION );
			return (int) $job['id'];
		} finally {
			Mutex::release( 'pending_analysis', $lock );
		}
	}

	/**
	 * The pending analysis request, read from the database (not the option cache: another process,
	 * such as WP-Cron next to an open dashboard, may have changed it during this request).
	 *
	 * @return array|null {params, owner_id, at}
	 */
	private static function pending() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PENDING_OPTION ) );
		$value = null === $raw ? null : maybe_unserialize( $raw );
		// Drop the cached copy (and a cached "does not exist") so update_option() compares with the
		// database and inserts or updates correctly.
		wp_cache_delete( self::PENDING_OPTION, 'options' );
		$none = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $none ) && isset( $none[ self::PENDING_OPTION ] ) ) {
			unset( $none[ self::PENDING_OPTION ] );
			wp_cache_set( 'notoptions', $none, 'options' );
		}
		return is_array( $value ) && isset( $value['params'] ) && is_array( $value['params'] ) ? $value : null;
	}

	/**
	 * Lock around the pending analysis request (waits up to about 2 seconds).
	 *
	 * @return string|false Lock token.
	 */
	private static function pending_lock() {
		for ( $i = 0; $i < 20; $i++ ) {
			$lock = Mutex::acquire( 'pending_analysis', 30 );
			if ( false !== $lock ) {
				return $lock;
			}
			usleep( 100000 );
		}
		return false;
	}

	/**
	 * Records a failure with exponential backoff; fails permanently after max attempts.
	 *
	 * @param int    $id    Job ID.
	 * @param string $owner Owner.
	 * @param string $error Error message.
	 * @return string New status.
	 */
	public static function fail( $id, $owner, $error ) {
		$job = self::get( $id );
		if ( null === $job ) {
			return 'missing';
		}
		$attempts = (int) $job['attempts'] + 1;
		$status   = $attempts >= (int) $job['max_attempts'] ? 'failed' : 'queued';
		$delay    = min( 3600, 30 * (int) pow( 2, $attempts - 1 ) );
		Db::query(
			"UPDATE {jobs} SET status = %s, attempts = %d, last_error = %s, lease_owner = '', lease_expires = NULL, next_run_at = %s, finished_at = CASE WHEN %s = 'failed' THEN %s ELSE NULL END, updated_at = %s WHERE id = %d AND lease_owner = %s",
			array( $status, $attempts, Util::truncate( $error, 1000 ), Util::now( $delay ), $status, Util::now(), Util::now(), (int) $id, $owner )
		);
		return $status;
	}

	/**
	 * Requests cancellation (the runner stops at the next batch boundary).
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function cancel( $id ) {
		return Db::query(
			"UPDATE {jobs} SET status = 'cancelled', lease_owner = '', lease_expires = NULL, finished_at = %s, updated_at = %s WHERE id = %d AND status IN ('queued','running','paused')",
			array( Util::now(), Util::now(), (int) $id )
		) > 0;
	}

	/**
	 * Pauses a job.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function pause( $id ) {
		return Db::query( "UPDATE {jobs} SET status = 'paused', lease_owner = '', lease_expires = NULL, updated_at = %s WHERE id = %d AND status IN ('queued','running')", array( Util::now(), (int) $id ) ) > 0;
	}

	/**
	 * Resumes a paused job.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function resume( $id ) {
		return Db::query( "UPDATE {jobs} SET status = 'queued', next_run_at = NULL, updated_at = %s WHERE id = %d AND status = 'paused'", array( Util::now(), (int) $id ) ) > 0;
	}

	/**
	 * Counts active jobs of a user (quota).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function active_count_for( $user_id ) {
		return (int) Db::value( 'SELECT COUNT(*) FROM {jobs} WHERE owner_id = %d AND status IN (' . Db::in( self::ACTIVE, '%s' ) . ')', array_merge( array( (int) $user_id ), self::ACTIVE ) );
	}

	/**
	 * Public representation (no internal lease tokens).
	 *
	 * @param array $job Job row.
	 * @return array
	 */
	public static function to_public( array $job ) {
		$total = (int) $job['progress_total'];
		$done  = (int) $job['progress_done'];
		$state = isset( $job['state'] ) && is_array( $job['state'] ) ? $job['state'] : array();
		return array(
			'id'             => (int) $job['id'],
			'type'           => (string) $job['type'],
			'status'         => (string) $job['status'],
			'phase'          => isset( $state['phase'] ) ? (string) $state['phase'] : '',
			'progress_done'  => $done,
			'progress_total' => $total,
			'progress_pct'   => $total > 0 ? round( min( 100, 100 * $done / $total ), 1 ) : null,
			'attempts'       => (int) $job['attempts'],
			'owner_id'       => (int) $job['owner_id'],
			'via'            => (string) $job['via'],
			'params'         => isset( $job['params'] ) && is_array( $job['params'] ) ? $job['params'] : array(),
			'errors'         => isset( $state['errors'] ) ? array_slice( (array) $state['errors'], -10 ) : array(),
			'summary'        => isset( $state['summary'] ) ? $state['summary'] : null,
			'last_error'     => (string) $job['last_error'],
			'heartbeat_at'   => $job['heartbeat_at'],
			'created_at'     => $job['created_at'],
			'started_at'     => $job['started_at'],
			'finished_at'    => $job['finished_at'],
			'next_run_at'    => $job['next_run_at'],
			'reused'         => ! empty( $job['reused'] ),
		);
	}

	/**
	 * Decodes JSON columns.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode( array $row ) {
		$row['params'] = Util::json_array( isset( $row['params'] ) ? $row['params'] : '' );
		$row['state']  = Util::json_array( isset( $row['state'] ) ? $row['state'] : '' );
		return $row;
	}
}
