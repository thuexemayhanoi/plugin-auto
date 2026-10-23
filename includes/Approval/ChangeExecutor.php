<?php
/**
 * Applies approved changes with mutex, stale checks, snapshots, readback verification,
 * automatic revert on failure and idempotency.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Approval;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Security\Mutex;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Sequence: idempotency → approval match → lock check → object mutex → consume approval →
 * stale check → snapshot → write → readback verify (auto-revert on mismatch) → log.
 */
final class ChangeExecutor {

	/**
	 * Current hash of the proposal's target value.
	 *
	 * @param array $p Proposal.
	 * @return string
	 */
	public static function current_hash( array $p ) {
		$payload = $p['payload'];
		if ( 'insert_internal_link' === $p['type'] ) {
			clean_post_cache( (int) $payload['post_id'] );
			return Util::sha256( (string) get_post_field( 'post_content', (int) $payload['post_id'], 'raw' ) );
		}
		if ( 'assign_category' === $p['type'] ) {
			$ids = wp_get_object_terms( (int) $payload['post_id'], $payload['taxonomy'], array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );
			return Util::sha256( $payload['taxonomy'] . ':' . implode( ',', $ids ) );
		}
		return '';
	}

	/**
	 * Applies an approved proposal.
	 *
	 * @param int    $proposal_id     Proposal ID.
	 * @param int    $approval_id     Approval ID.
	 * @param string $idempotency_key Client key (8–64 chars).
	 * @return array|\WP_Error Change record.
	 */
	public static function apply( $proposal_id, $approval_id, $idempotency_key ) {
		if ( ! current_user_can( Capabilities::APPLY ) ) {
			AuditLog::record( 'denied.apply', 'proposal', (int) $proposal_id, 'denied', array( 'agent' => AuthContext::is_agent_context() ) );
			return new \WP_Error( 'sseo_forbidden', __( 'You do not have permission to apply changes.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		$idempotency_key = (string) $idempotency_key;
		if ( ! preg_match( '/^[A-Za-z0-9_\-:.]{8,64}$/', $idempotency_key ) ) {
			return new \WP_Error( 'sseo_idempotency_key', __( 'An idempotency key (8–64 characters) is required.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$key_hash = Util::sha256( 'apply|' . $idempotency_key );
		$prior    = Db::row( 'SELECT * FROM {changes} WHERE idempotency_key = %s', array( $key_hash ) );
		if ( null !== $prior ) {
			if ( (int) $prior['proposal_id'] !== (int) $proposal_id ) {
				return new \WP_Error( 'sseo_idempotency_conflict', __( 'This idempotency key was already used for another change.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
			}
			$out           = self::present( $prior );
			$out['replay'] = true;
			return $out;
		}
		$p = ProposalService::get( $proposal_id );
		if ( null === $p ) {
			return new \WP_Error( 'sseo_not_found', __( 'Proposal not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		if ( 'approved' !== $p['status'] ) {
			return new \WP_Error( 'sseo_not_approved', __( 'This proposal has no active human approval.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$approval = ApprovalService::get( $approval_id );
		if ( null === $approval || (int) $approval['proposal_id'] !== (int) $p['id'] ) {
			AuditLog::record( 'denied.apply_forged_approval', $p['object_type'], (int) $p['object_id'], 'denied', array( 'approval_id' => (int) $approval_id ) );
			return new \WP_Error( 'sseo_approval_invalid', __( 'The approval does not belong to this proposal.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		$scope = isset( $approval['scope']['object_ids'] ) ? array_map( 'intval', (array) $approval['scope']['object_ids'] ) : array();
		if ( array( (int) $p['object_id'] ) !== $scope || $approval['action_category'] !== $p['type'] ) {
			return new \WP_Error( 'sseo_approval_invalid', __( 'The approval scope does not match.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		if ( LockService::is_locked( $p['object_type'], (int) $p['object_id'], (string) get_permalink( (int) $p['object_id'] ) ) ) {
			return new \WP_Error( 'sseo_locked', __( 'This URL is locked by an administrator.', 'semantic-seo-engine-vi-en' ), array( 'status' => 423 ) );
		}
		$mutex = Mutex::acquire( 'object_' . $p['object_type'] . '_' . (int) $p['object_id'], 120 );
		if ( false === $mutex ) {
			return new \WP_Error( 'sseo_busy', __( 'Another change to this object is in progress.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		try {
			$consumed = ApprovalService::consume( (int) $approval['id'], 'apply', $p['diff_hash'] );
			if ( is_wp_error( $consumed ) ) {
				return $consumed;
			}
			$current = self::current_hash( $p );
			if ( $current !== $p['base_hash'] ) {
				Db::query( "UPDATE {approvals} SET status = 'invalidated', note = %s WHERE id = %d", array( 'target changed before apply', (int) $approval['id'] ) );
				Db::update(
					'proposals',
					array(
						'status'     => 'stale',
						'updated_at' => Util::now(),
					),
					array( 'id' => (int) $p['id'] )
				);
				AuditLog::record( 'change.rejected_stale', $p['object_type'], (int) $p['object_id'], 'denied', array( 'proposal_id' => (int) $p['id'] ) );
				return new \WP_Error( 'sseo_stale', __( 'The content changed after approval; nothing was written. Create a new preview.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
			}
			return self::execute( $p, (int) $approval['id'], $key_hash );
		} finally {
			Mutex::release( 'object_' . $p['object_type'] . '_' . (int) $p['object_id'], $mutex );
		}
	}

	/**
	 * Performs the write and verification.
	 *
	 * @param array  $p           Proposal.
	 * @param int    $approval_id Approval ID.
	 * @param string $key_hash    Idempotency key hash.
	 * @return array|\WP_Error
	 */
	private static function execute( array $p, $approval_id, $key_hash ) {
		$payload = $p['payload'];
		$post_id = (int) $payload['post_id'];
		if ( 'insert_internal_link' === $p['type'] ) {
			$before = (string) get_post_field( 'post_content', $post_id, 'raw' );
			$after  = Operations::insert_link( $before, (int) $payload['offset'], (int) $payload['length'], (string) $payload['anchor'], (string) $payload['href'] );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			if ( Util::sha256( $after ) !== $p['expected_hash'] ) {
				return new \WP_Error( 'sseo_diff_mismatch', __( 'The computed result does not match the approved diff; nothing was written.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
			}
			$field = 'post_content';
		} else {
			$before = Util::json( $payload['before'] );
			$after  = Util::json( $payload['after'] );
			$field  = 'terms:' . $payload['taxonomy'];
		}
		$change_id = Db::insert(
			'changes',
			array(
				'proposal_id'     => (int) $p['id'],
				'approval_id'     => (int) $approval_id,
				'object_type'     => $p['object_type'],
				'object_id'       => $post_id,
				'field'           => $field,
				'before_value'    => $before,
				'after_value'     => $after,
				'before_hash'     => $p['base_hash'],
				'after_hash'      => $p['expected_hash'],
				'actor_id'        => get_current_user_id(),
				'actor_via'       => AuthContext::is_agent_context() ? 'agent:' . AuthContext::via() : AuthContext::via(),
				'idempotency_key' => $key_hash,
				'status'          => 'applying',
			)
		);
		if ( $change_id <= 0 ) {
			return new \WP_Error( 'sseo_conflict', __( 'A concurrent apply with the same idempotency key is in progress.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$write = self::write( $p, $after );
		$read  = self::current_hash( $p );
		$ok    = ! is_wp_error( $write ) && $read === $p['expected_hash'];
		$now   = Util::now();
		if ( ! $ok ) {
			// Automatic revert to the snapshot.
			$revert      = self::write( $p, $before, true );
			$reverted_to = self::current_hash( $p );
			$status      = $reverted_to === $p['base_hash'] ? 'failed' : 'rollback_failed';
			Db::update(
				'changes',
				array(
					'status' => $status,
					'error'  => is_wp_error( $write ) ? $write->get_error_message() : 'readback hash mismatch (another filter changed the saved value)',
					'verify' => Util::json(
						array(
							'readback_hash'   => $read,
							'expected_hash'   => $p['expected_hash'],
							'reverted'        => 'failed' === $status,
							'revert_error'    => is_wp_error( $revert ) ? $revert->get_error_message() : '',
							'manual_recovery' => 'rollback_failed' === $status ? 'Restore the "before" snapshot from the change log or a WordPress revision.' : '',
						)
					),
				),
				array( 'id' => $change_id )
			);
			Db::update(
				'proposals',
				array(
					'status'     => 'failed',
					'updated_at' => $now,
				),
				array( 'id' => (int) $p['id'] )
			);
			AuditLog::record(
				'change.failed',
				$p['object_type'],
				$post_id,
				'error',
				array(
					'change_id' => $change_id,
					'status'    => $status,
				)
			);
			return new \WP_Error(
				'sseo_apply_failed',
				'failed' === $status ? __( 'The write could not be verified and was reverted automatically.', 'semantic-seo-engine-vi-en' ) : __( 'The write could not be verified and the automatic revert also failed: manual recovery is needed (see change log).', 'semantic-seo-engine-vi-en' ),
				array(
					'status'    => 500,
					'change_id' => $change_id,
				)
			);
		}
		Db::update(
			'changes',
			array(
				'status'     => 'applied',
				'applied_at' => $now,
				'verify'     => Util::json(
					array(
						'readback_hash' => $read,
						'expected_hash' => $p['expected_hash'],
						'readback'      => true,
						'public_url'    => (string) get_permalink( $post_id ),
						'public_check'  => 'not performed server-side (enable link verification to check HTTP status)',
					)
				),
			),
			array( 'id' => $change_id )
		);
		Db::update(
			'proposals',
			array(
				'status'     => 'applied',
				'updated_at' => $now,
			),
			array( 'id' => (int) $p['id'] )
		);
		if ( $p['finding_id'] ) {
			FindingRepository::set_status( (int) $p['finding_id'], 'accepted' );
		}
		DocumentRepository::mark_dirty( 'post', $post_id );
		AuditLog::record(
			'change.applied',
			$p['object_type'],
			$post_id,
			'ok',
			array(
				'change_id'   => $change_id,
				'proposal_id' => (int) $p['id'],
				'approval_id' => (int) $approval_id,
				'diff_hash'   => $p['diff_hash'],
			)
		);
		return self::present( Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( $change_id ) ) );
	}

	/**
	 * Writes a value through WordPress APIs (revisions and hooks run). KSES is bypassed only for
	 * this verified write: the rest of the content is byte-identical to what is already stored,
	 * and the only new markup is an escaped internal link.
	 *
	 * @param array  $p      Proposal.
	 * @param string $value  New value (content or JSON term list).
	 * @param bool   $revert Whether this is a revert.
	 * @return true|\WP_Error
	 */
	public static function write( array $p, $value, $revert = false ) {
		unset( $revert );
		$payload = $p['payload'];
		$post_id = (int) $payload['post_id'];
		if ( 'insert_internal_link' === $p['type'] ) {
			$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			if ( $kses ) {
				kses_remove_filters();
			}
			$res = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $value ),
				),
				true
			);
			if ( $kses ) {
				kses_init_filters();
			}
			clean_post_cache( $post_id );
			return is_wp_error( $res ) ? $res : true;
		}
		if ( 'assign_category' === $p['type'] ) {
			$ids = array_map( 'intval', (array) json_decode( $value, true ) );
			$res = wp_set_object_terms( $post_id, $ids, (string) $payload['taxonomy'], false );
			clean_object_term_cache( $post_id, get_post_type( $post_id ) );
			return is_wp_error( $res ) ? $res : true;
		}
		return new \WP_Error( 'sseo_unsupported', 'Unsupported operation.' );
	}

	/**
	 * Public representation of a change row (snapshots summarized, not dumped).
	 *
	 * @param array|null $c Row.
	 * @return array
	 */
	public static function present( $c ) {
		if ( ! is_array( $c ) ) {
			return array();
		}
		return array(
			'change_id'      => (int) $c['id'],
			'proposal_id'    => (int) $c['proposal_id'],
			'approval_id'    => (int) $c['approval_id'],
			'object_type'    => $c['object_type'],
			'object_id'      => (int) $c['object_id'],
			'field'          => $c['field'],
			'status'         => $c['status'],
			'before_hash'    => $c['before_hash'],
			'after_hash'     => $c['after_hash'],
			'actor_id'       => (int) $c['actor_id'],
			'actor_via'      => $c['actor_via'],
			'applied_at'     => $c['applied_at'],
			'rolled_back_at' => $c['rolled_back_at'],
			'verify'         => Util::json_array( $c['verify'] ),
			'error'          => (string) $c['error'],
			'reversible'     => in_array( $c['status'], array( 'applied' ), true ),
		);
	}

	/**
	 * Change list.
	 *
	 * @param array $f status, object_id, limit, offset.
	 * @return array
	 */
	public static function query( array $f ) {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $f['status'];
		}
		if ( ! empty( $f['object_id'] ) ) {
			$where[] = 'object_id = %d';
			$args[]  = (int) $f['object_id'];
		}
		$args[] = Util::clamp_int( isset( $f['limit'] ) ? $f['limit'] : 50, 1, 100 );
		$args[] = max( 0, isset( $f['offset'] ) ? (int) $f['offset'] : 0 );
		return array_map( array( __CLASS__, 'present' ), Db::rows( 'SELECT * FROM {changes} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', $args ) );
	}
}
