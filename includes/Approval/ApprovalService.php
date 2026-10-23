<?php
/**
 * Human approvals: interactive WP Admin only, single-use, expiring, bound to the exact diff.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Approval;

use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * No REST route, Ability or CLI command can create approvals. Agents (dedicated roles or any
 * Application Password session) never hold sseo_review, so they cannot approve their own changes.
 */
final class ApprovalService {

	/**
	 * Approves a proposal for application.
	 *
	 * @param int    $proposal_id Proposal ID.
	 * @param string $note        Optional note.
	 * @return array|\WP_Error Approval row.
	 */
	public static function approve( $proposal_id, $note = '' ) {
		$check = self::preconditions( 'approve' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$p = ProposalService::get( $proposal_id );
		if ( null === $p ) {
			return new \WP_Error( 'sseo_not_found', __( 'Proposal not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		if ( 'previewed' !== $p['status'] ) {
			return new \WP_Error( 'sseo_invalid_state', __( 'Only previewed proposals can be approved.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$current = ChangeExecutor::current_hash( $p );
		if ( $current !== $p['base_hash'] ) {
			Db::update(
				'proposals',
				array(
					'status'     => 'stale',
					'updated_at' => Util::now(),
				),
				array( 'id' => (int) $p['id'] )
			);
			AuditLog::record( 'approval.rejected_stale', $p['object_type'], (int) $p['object_id'], 'denied', array( 'proposal_id' => (int) $p['id'] ) );
			return new \WP_Error( 'sseo_stale', __( 'The content changed after this preview was created. Create a new preview.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$id = Db::insert(
			'approvals',
			array(
				'proposal_id'     => (int) $p['id'],
				'action'          => 'apply',
				'approver_id'     => get_current_user_id(),
				'approver_via'    => AuthContext::via(),
				'scope'           => Util::json(
					array(
						'object_type' => $p['object_type'],
						'object_ids'  => array( (int) $p['object_id'] ),
					)
				),
				'diff_hash'       => $p['diff_hash'],
				'action_category' => $p['type'],
				'status'          => 'active',
				'expires_at'      => Util::now( HOUR_IN_SECONDS * (int) Settings::get( 'approval_ttl_hours', 24 ) ),
				'created_at'      => Util::now(),
				'note'            => Util::truncate( sanitize_text_field( (string) $note ), 500 ),
			)
		);
		Db::update(
			'proposals',
			array(
				'status'     => 'approved',
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $p['id'] )
		);
		AuditLog::record(
			'approval.created',
			$p['object_type'],
			(int) $p['object_id'],
			'ok',
			array(
				'proposal_id' => (int) $p['id'],
				'approval_id' => $id,
				'diff_hash'   => $p['diff_hash'],
			)
		);
		return self::get( $id );
	}

	/**
	 * Issues a separate rollback authorization for a change (needed when an agent executes it).
	 *
	 * @param int $change_id Change ID.
	 * @return array|\WP_Error
	 */
	public static function approve_rollback( $change_id ) {
		$check = self::preconditions( 'approve_rollback' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$c = Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $change_id ) );
		if ( null === $c || 'applied' !== $c['status'] ) {
			return new \WP_Error( 'sseo_invalid_state', __( 'Only applied changes can be rolled back.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$id = Db::insert(
			'approvals',
			array(
				'proposal_id'     => (int) $c['proposal_id'],
				'change_id'       => (int) $c['id'],
				'action'          => 'rollback',
				'approver_id'     => get_current_user_id(),
				'approver_via'    => AuthContext::via(),
				'scope'           => Util::json(
					array(
						'object_type' => $c['object_type'],
						'object_ids'  => array( (int) $c['object_id'] ),
					)
				),
				'diff_hash'       => Util::sha256( $c['before_hash'] . '|' . $c['after_hash'] ),
				'action_category' => 'rollback',
				'status'          => 'active',
				'expires_at'      => Util::now( HOUR_IN_SECONDS * (int) Settings::get( 'approval_ttl_hours', 24 ) ),
				'created_at'      => Util::now(),
			)
		);
		AuditLog::record(
			'approval.rollback_created',
			$c['object_type'],
			(int) $c['object_id'],
			'ok',
			array(
				'change_id'   => (int) $c['id'],
				'approval_id' => $id,
			)
		);
		return self::get( $id );
	}

	/**
	 * Revokes an active approval.
	 *
	 * @param int $id Approval ID.
	 * @return bool
	 */
	public static function revoke( $id ) {
		$n = Db::query( "UPDATE {approvals} SET status = 'revoked' WHERE id = %d AND status = 'active'", array( (int) $id ) );
		if ( $n > 0 ) {
			$a = self::get( $id );
			Db::query( "UPDATE {proposals} SET status = 'previewed', updated_at = %s WHERE id = %d AND status = 'approved'", array( Util::now(), (int) $a['proposal_id'] ) );
			AuditLog::record( 'approval.revoked', '', 0, 'ok', array( 'approval_id' => (int) $id ) );
		}
		return $n > 0;
	}

	/**
	 * Atomically consumes an approval (replay-safe: only one caller can succeed).
	 *
	 * @param int    $id        Approval ID.
	 * @param string $action    apply|rollback.
	 * @param string $diff_hash Expected diff hash.
	 * @return true|\WP_Error
	 */
	public static function consume( $id, $action, $diff_hash ) {
		$a = self::get( $id );
		if ( null === $a ) {
			return new \WP_Error( 'sseo_approval_invalid', __( 'Approval not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		if ( $a['action'] !== $action || ! hash_equals( (string) $a['diff_hash'], (string) $diff_hash ) ) {
			AuditLog::record( 'approval.mismatch', '', 0, 'denied', array( 'approval_id' => (int) $id ) );
			return new \WP_Error( 'sseo_approval_invalid', __( 'The approval does not match this change.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		if ( 'active' !== $a['status'] ) {
			return new \WP_Error( 'sseo_approval_used', __( 'The approval was already used, revoked or invalidated.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		if ( strtotime( $a['expires_at'] . ' UTC' ) <= time() ) {
			Db::query( "UPDATE {approvals} SET status = 'expired' WHERE id = %d AND status = 'active'", array( (int) $id ) );
			return new \WP_Error( 'sseo_approval_expired', __( 'The approval has expired; approve again.', 'semantic-seo-engine-vi-en' ), array( 'status' => 410 ) );
		}
		$n = Db::query(
			"UPDATE {approvals} SET status = 'consumed', consumed_at = %s, consumed_by = %d WHERE id = %d AND status = 'active' AND expires_at > %s",
			array( Util::now(), get_current_user_id(), (int) $id, Util::now() )
		);
		if ( 1 !== $n ) {
			return new \WP_Error( 'sseo_approval_used', __( 'The approval was already used.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Fetches an approval.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = Db::row( 'SELECT * FROM {approvals} WHERE id = %d', array( (int) $id ) );
		if ( null !== $row ) {
			$row['scope'] = Util::json_array( $row['scope'] );
			$row['id']    = (int) $row['id'];
		}
		return $row;
	}

	/**
	 * Approvals list.
	 *
	 * @param array $f status, proposal_id, limit.
	 * @return array
	 */
	public static function query( array $f ) {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $f['status'];
		}
		if ( ! empty( $f['proposal_id'] ) ) {
			$where[] = 'proposal_id = %d';
			$args[]  = (int) $f['proposal_id'];
		}
		$args[] = Util::clamp_int( isset( $f['limit'] ) ? $f['limit'] : 50, 1, 100 );
		return Db::rows( 'SELECT * FROM {approvals} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args );
	}

	/**
	 * Common preconditions: interactive human with sseo_review.
	 *
	 * @param string $action Action name.
	 * @return true|\WP_Error
	 */
	private static function preconditions( $action ) {
		if ( ! AuthContext::is_interactive_human() ) {
			AuditLog::record(
				'denied.' . $action,
				'',
				0,
				'denied',
				array(
					'reason' => 'not an interactive human session',
					'via'    => AuthContext::via(),
				)
			);
			return new \WP_Error( 'sseo_human_required', __( 'Approvals can only be given by a human administrator in WP Admin.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			AuditLog::record( 'denied.' . $action, '', 0, 'denied', array( 'capability' => Capabilities::REVIEW ) );
			return new \WP_Error( 'sseo_forbidden', __( 'You do not have permission to approve changes.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
