<?php
/**
 * Verified rollback of changes made by this plugin.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Rollback;

use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Approval\ChangeExecutor;
use SemanticSEO\Approval\Operations;
use SemanticSEO\Approval\ProposalService;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Security\Mutex;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Exact restore when the object is unchanged since apply; otherwise a verified surgical revert
 * (unwrap the inserted link / remove the added term) or an explicit manual-recovery report.
 * Agents need a separate, human-issued rollback approval; humans confirm in WP Admin.
 */
final class RollbackService {

	/**
	 * Rolls back a change.
	 *
	 * @param int $change_id   Change ID.
	 * @param int $approval_id Rollback approval (required for non-interactive callers).
	 * @return array|\WP_Error
	 */
	public static function rollback( $change_id, $approval_id = 0 ) {
		if ( ! current_user_can( Capabilities::APPLY ) ) {
			AuditLog::record( 'denied.rollback', 'change', (int) $change_id, 'denied' );
			return new \WP_Error( 'sseo_forbidden', __( 'You do not have permission to roll back changes.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		$c = Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $change_id ) );
		if ( null === $c ) {
			return new \WP_Error( 'sseo_not_found', __( 'Change not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		if ( 'applying' === $c['status'] ) {
			ChangeExecutor::reconcile( (int) $c['id'] );
			$c = Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $change_id ) );
		}
		if ( 'applied' !== $c['status'] ) {
			return new \WP_Error( 'sseo_invalid_state', __( 'Only applied changes can be rolled back.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		if ( ! AuthContext::is_interactive_human() ) {
			if ( $approval_id <= 0 ) {
				return new \WP_Error( 'sseo_rollback_authorization', __( 'A separate rollback approval issued by a human in WP Admin is required.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
			}
			$a = ApprovalService::get( $approval_id );
			if ( null === $a || (int) $a['change_id'] !== (int) $c['id'] ) {
				return new \WP_Error( 'sseo_approval_invalid', __( 'The rollback approval does not belong to this change.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
			}
			$consumed = ApprovalService::consume( $approval_id, 'rollback', Util::sha256( $c['before_hash'] . '|' . $c['after_hash'] ) );
			if ( is_wp_error( $consumed ) ) {
				return $consumed;
			}
		}
		$p = ProposalService::get( (int) $c['proposal_id'] );
		if ( null === $p ) {
			return new \WP_Error( 'sseo_not_found', __( 'Original proposal missing; manual recovery required.', 'semantic-seo-engine-vi-en' ), array( 'status' => 500 ) );
		}
		$mutex = Mutex::acquire( 'object_' . $c['object_type'] . '_' . (int) $c['object_id'], 120 );
		if ( false === $mutex ) {
			return new \WP_Error( 'sseo_busy', __( 'Another change to this object is in progress.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		try {
			// Another rollback of this change may have completed while this request waited.
			$c = Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $c['id'] ) );
			if ( 'applied' !== $c['status'] ) {
				return new \WP_Error( 'sseo_invalid_state', __( 'Only applied changes can be rolled back.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
			}
			$current = ChangeExecutor::current_hash( $p );
			$mode    = 'exact';
			if ( $current === $c['after_hash'] ) {
				$target_value = (string) $c['before_value'];
				$expect       = $c['before_hash'];
			} else {
				$mode = 'surgical';
				if ( 'insert_internal_link' === $p['type'] ) {
					$content      = (string) get_post_field( 'post_content', (int) $c['object_id'], 'raw' );
					$target_value = Operations::remove_link( $content, (string) $p['payload']['href'], (string) $p['payload']['anchor'] );
					if ( is_wp_error( $target_value ) ) {
						self::mark( $c, 'applied', 'manual recovery required: ' . $target_value->get_error_message() );
						AuditLog::record( 'rollback.manual_required', $c['object_type'], (int) $c['object_id'], 'error', array( 'change_id' => (int) $c['id'] ) );
						return $target_value;
					}
					$expect = Util::sha256( $target_value );
				} else {
					$ids = wp_get_object_terms( (int) $c['object_id'], (string) $p['payload']['taxonomy'], array( 'fields' => 'ids' ) );
					$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
					$ids = array_values( array_diff( $ids, array( (int) $p['payload']['term_id'] ) ) );
					sort( $ids );
					$target_value = Util::json( $ids );
					$expect       = Util::sha256( $p['payload']['taxonomy'] . ':' . implode( ',', $ids ) );
				}
			}
			// Content: compare-and-set from the value just read, so an edit saved meanwhile is never
			// overwritten. Terms: only this change's term is removed.
			$res = ChangeExecutor::write( $p, $target_value, $current, true );
			if ( ! $res['written'] ) {
				self::mark( $c, 'applied', 'rollback not performed: ' . $res['error']->get_error_message() );
				AuditLog::record( 'rollback.failed', $c['object_type'], (int) $c['object_id'], 'error', array( 'change_id' => (int) $c['id'] ) );
				if ( 'sseo_concurrent_edit' === $res['error']->get_error_code() ) {
					return new \WP_Error( 'sseo_busy', __( 'The post was edited at the same moment; nothing was changed. Try the rollback again.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
				}
				return new \WP_Error( 'sseo_rollback_failed', __( 'The rollback failed before anything was changed; the change is still applied (see change log).', 'semantic-seo-engine-vi-en' ), array( 'status' => 500 ) );
			}
			if ( null !== $res['hook_error'] || ( 'insert_internal_link' === $p['type'] && $res['readback_hash'] !== $expect ) ) {
				self::mark( $c, 'rollback_failed', null !== $res['hook_error'] ? 'the restored value was written (readback ' . ( $res['readback_hash'] === $expect ? 'verified' : 'mismatch' ) . ') but ' . $res['hook_error']->get_error_message() : 'readback mismatch after rollback' );
				AuditLog::record( 'rollback.failed', $c['object_type'], (int) $c['object_id'], 'error', array( 'change_id' => (int) $c['id'] ) );
				return new \WP_Error( 'sseo_rollback_failed', __( 'Rollback could not be verified; manual recovery is needed (the before snapshot is in the change log).', 'semantic-seo-engine-vi-en' ), array( 'status' => 500 ) );
			}
			$read = ChangeExecutor::current_hash( $p );
			Db::update(
				'changes',
				array(
					'status'         => 'rolled_back',
					'rolled_back_at' => Util::now(),
					'rollback_by'    => get_current_user_id(),
					'verify'         => Util::json(
						array_merge(
							Util::json_array( $c['verify'] ),
							array(
								'rollback_mode'          => $mode,
								'rollback_readback_hash' => $res['readback_hash'],
								'rollback_verified'      => true,
								'changed_after_rollback' => $read !== $expect,
							)
						)
					),
				),
				array( 'id' => (int) $c['id'] )
			);
			Db::update(
				'proposals',
				array(
					'status'     => 'rolled_back',
					'updated_at' => Util::now(),
				),
				array( 'id' => (int) $p['id'] )
			);
			DocumentRepository::mark_dirty( 'post', (int) $c['object_id'] );
			AuditLog::record(
				'change.rolled_back',
				$c['object_type'],
				(int) $c['object_id'],
				'ok',
				array(
					'change_id' => (int) $c['id'],
					'mode'      => $mode,
				)
			);
			return ChangeExecutor::present( Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $c['id'] ) ) );
		} finally {
			Mutex::release( 'object_' . $c['object_type'] . '_' . (int) $c['object_id'], $mutex );
		}
	}

	/**
	 * Updates a change status/error.
	 *
	 * @param array  $c      Change.
	 * @param string $status Status.
	 * @param string $error  Error.
	 * @return void
	 */
	private static function mark( array $c, $status, $error ) {
		Db::update(
			'changes',
			array(
				'status' => $status,
				'error'  => Util::truncate( $error, 1000 ),
			),
			array( 'id' => (int) $c['id'] )
		);
	}
}
