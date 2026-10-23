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

	const TYPES    = array( 'inventory', 'analyze', 'embed', 'incremental', 'verify_links' );
	const ACTIVE   = array( 'queued', 'running', 'paused' );
	const FINISHED = array( 'completed', 'failed', 'cancelled' );

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
					__( 'A job of this type is already queued or running.', 'semantic-seo-engine-vi-en' ),
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
	 * @param int    $id    Job ID.
	 * @param string $owner Lease owner token.
	 * @param int    $ttl   Lease seconds.
	 * @return bool
	 */
	public static function claim( $id, $owner, $ttl ) {
		$now = Util::now();
		$n   = Db::query(
			"UPDATE {jobs} SET status = 'running', lease_owner = %s, lease_expires = %s, heartbeat_at = %s, started_at = COALESCE(started_at, %s), updated_at = %s
			WHERE id = %d AND status IN ('queued','running') AND (lease_expires IS NULL OR lease_expires < %s OR lease_owner = '') AND (next_run_at IS NULL OR next_run_at <= %s)",
			array( $owner, Util::now( $ttl ), $now, $now, $now, (int) $id, $now, $now )
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
	 * @return void
	 */
	public static function complete( $id, $owner, array $state ) {
		Db::query(
			"UPDATE {jobs} SET status = 'completed', state = %s, progress_done = progress_total, lease_owner = '', lease_expires = NULL, finished_at = %s, updated_at = %s, last_error = NULL WHERE id = %d AND lease_owner = %s",
			array( Util::json( $state ), Util::now(), Util::now(), (int) $id, $owner )
		);
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
