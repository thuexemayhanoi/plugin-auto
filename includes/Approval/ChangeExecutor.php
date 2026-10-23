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
 * re-validation (stale, source, target) → snapshot → compare-and-set write → readback verify →
 * core post hooks → field check (undo of this plugin's own write only) → log.
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
			if ( 'applying' === $prior['status'] ) {
				self::reconcile( (int) $prior['id'] );
				$prior = Db::row( 'SELECT * FROM {changes} WHERE id = %d', array( (int) $prior['id'] ) );
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
			$prepared = self::prepare( $p );
			if ( is_wp_error( $prepared ) ) {
				self::reject( $p, (int) $approval['id'], $prepared );
				return $prepared;
			}
			return self::execute( $p, (int) $approval['id'], $key_hash, $prepared );
		} finally {
			Mutex::release( 'object_' . $p['object_type'] . '_' . (int) $p['object_id'], $mutex );
		}
	}

	/**
	 * Checks, under the object lock, that the approved change still applies exactly as previewed:
	 * same stored value, source still published, link target still published at the approved URL,
	 * term still existing, permalink structure still free of the taxonomy.
	 *
	 * @param array $p Proposal.
	 * @return array{field:string,before:string,after:string}|\WP_Error
	 */
	private static function prepare( array $p ) {
		$payload = $p['payload'];
		$post_id = (int) $payload['post_id'];
		if ( self::current_hash( $p ) !== $p['base_hash'] ) {
			return new \WP_Error( 'sseo_stale', __( 'The content changed after approval; nothing was written. Create a new preview.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'sseo_invalid_source', __( 'The post is no longer published; nothing was written.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		if ( 'insert_internal_link' === $p['type'] ) {
			if ( ! self::link_target_valid( $payload ) ) {
				return new \WP_Error( 'sseo_invalid_target', __( 'The linked page is no longer published at the approved URL; nothing was written. Create a new preview.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
			}
			$before = (string) get_post_field( 'post_content', $post_id, 'raw' );
			$after  = Operations::insert_link( $before, (int) $payload['offset'], (int) $payload['length'], (string) $payload['anchor'], (string) $payload['href'] );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			if ( Util::sha256( $after ) !== $p['expected_hash'] ) {
				return new \WP_Error( 'sseo_diff_mismatch', __( 'The computed result does not match the approved diff; nothing was written.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
			}
			return array(
				'field'  => 'post_content',
				'before' => $before,
				'after'  => $after,
			);
		}
		if ( ProposalService::taxonomy_in_permalink( (string) $payload['taxonomy'] ) ) {
			return new \WP_Error( 'sseo_permalink_risk', __( 'The permalink structure now contains this taxonomy: applying could change the post URL; nothing was written.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		if ( ! get_term( (int) $payload['term_id'], (string) $payload['taxonomy'] ) instanceof \WP_Term ) {
			return new \WP_Error( 'sseo_invalid_term', __( 'The term no longer exists; nothing was written.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		return array(
			'field'  => 'terms:' . $payload['taxonomy'],
			'before' => Util::json( $payload['before'] ),
			'after'  => Util::json( $payload['after'] ),
		);
	}

	/**
	 * Whether the link target is still a published page at the approved URL.
	 *
	 * @param array $payload Link payload.
	 * @return bool
	 */
	private static function link_target_valid( array $payload ) {
		$doc = DocumentRepository::get( isset( $payload['target_document_id'] ) ? (int) $payload['target_document_id'] : 0 );
		if ( null === $doc ) {
			return false;
		}
		if ( 'post' === $doc['object_type'] ) {
			$id = (int) $doc['object_id'];
			clean_post_cache( $id );
			return 'publish' === get_post_status( $id ) && (string) get_permalink( $id ) === (string) $payload['href'];
		}
		return (string) $doc['url'] === (string) $payload['href'] && 'publish' === $doc['status'] && ! (int) $doc['excluded'];
	}

	/**
	 * Records an approved change that was not applied because it no longer applies as previewed.
	 * The approval cannot be reused and the proposal needs a new preview.
	 *
	 * @param array     $p           Proposal.
	 * @param int       $approval_id Approval ID.
	 * @param \WP_Error $error       Reason.
	 * @return void
	 */
	private static function reject( array $p, $approval_id, \WP_Error $error ) {
		Db::query( "UPDATE {approvals} SET status = 'invalidated', note = %s WHERE id = %d", array( Util::truncate( 'not applied: ' . $error->get_error_code(), 200 ), (int) $approval_id ) );
		Db::update(
			'proposals',
			array(
				'status'     => 'stale',
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $p['id'] )
		);
		AuditLog::record(
			'sseo_stale' === $error->get_error_code() ? 'change.rejected_stale' : 'change.rejected',
			$p['object_type'],
			(int) $p['object_id'],
			'denied',
			array(
				'proposal_id' => (int) $p['id'],
				'reason'      => $error->get_error_code(),
			)
		);
	}

	/**
	 * Performs the write and verification.
	 *
	 * @param array  $p           Proposal.
	 * @param int    $approval_id Approval ID.
	 * @param string $key_hash    Idempotency key hash.
	 * @param array  $prep        From prepare().
	 * @return array|\WP_Error
	 */
	private static function execute( array $p, $approval_id, $key_hash, array $prep ) {
		$post_id   = (int) $p['payload']['post_id'];
		$change_id = Db::insert(
			'changes',
			array(
				'proposal_id'     => (int) $p['id'],
				'approval_id'     => (int) $approval_id,
				'object_type'     => $p['object_type'],
				'object_id'       => $post_id,
				'field'           => $prep['field'],
				'before_value'    => $prep['before'],
				'after_value'     => $prep['after'],
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
		$fields_before = self::post_fields( $post_id );
		$write         = self::write( $p, $prep['after'], $p['base_hash'] );
		if ( ! $write['written'] ) {
			return self::fail_unwritten( $p, $change_id, $write['error'] );
		}
		$read    = self::current_hash( $p );
		$changed = self::changed_fields( $fields_before, self::post_fields( $post_id ) );
		if ( null !== $write['hook_error'] || array() !== $changed ) {
			return self::undo( $p, $change_id, $prep, $fields_before, $write, $read, $changed );
		}
		$now = Util::now();
		Db::update(
			'changes',
			array(
				'status'     => 'applied',
				'applied_at' => $now,
				'verify'     => Util::json(
					array(
						'readback_hash'       => $write['readback_hash'],
						'expected_hash'       => $p['expected_hash'],
						'readback'            => true,
						'other_fields'        => 'unchanged',
						// Another plugin's hook or a concurrent edit changed the value after the verified
						// write: kept as it is, never overwritten (rollback then works surgically).
						'changed_after_write' => $read !== $p['expected_hash'],
						'current_hash'        => $read,
						'public_url'          => (string) get_permalink( $post_id ),
						'public_check'        => 'not performed server-side (enable link verification to check HTTP status)',
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
	 * Records a change whose write did not happen (nothing was changed).
	 *
	 * @param array     $p         Proposal.
	 * @param int       $change_id Change ID.
	 * @param \WP_Error $error     Why.
	 * @return \WP_Error
	 */
	private static function fail_unwritten( array $p, $change_id, \WP_Error $error ) {
		$concurrent = 'sseo_concurrent_edit' === $error->get_error_code();
		Db::update(
			'changes',
			array(
				'status' => 'failed',
				'error'  => Util::truncate( $error->get_error_message(), 1000 ),
				'verify' => Util::json(
					array(
						'written'       => false,
						'current_hash'  => self::current_hash( $p ),
						'expected_hash' => $p['expected_hash'],
					)
				),
			),
			array( 'id' => (int) $change_id )
		);
		Db::update(
			'proposals',
			array(
				'status'     => $concurrent ? 'stale' : 'failed',
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $p['id'] )
		);
		AuditLog::record(
			'change.failed',
			$p['object_type'],
			(int) $p['object_id'],
			'error',
			array(
				'change_id' => (int) $change_id,
				'status'    => 'failed',
				'written'   => false,
				'reason'    => $error->get_error_code(),
			)
		);
		if ( $concurrent ) {
			return new \WP_Error(
				'sseo_stale',
				__( 'The post was edited at the same moment; nothing was written. Create a new preview.', 'semantic-seo-engine-vi-en' ),
				array(
					'status'    => 409,
					'change_id' => (int) $change_id,
				)
			);
		}
		return new \WP_Error(
			'sseo_apply_failed',
			__( 'The write failed before anything was changed (see change log).', 'semantic-seo-engine-vi-en' ),
			array(
				'status'    => 500,
				'change_id' => (int) $change_id,
			)
		);
	}

	/**
	 * Undoes a verified write after which another plugin's hook failed or another post field
	 * changed. Only this plugin's own write is undone: if the stored value is no longer the one it
	 * wrote, someone edited the post since, and that edit is kept (manual check reported).
	 *
	 * @param array  $p             Proposal.
	 * @param int    $change_id     Change ID.
	 * @param array  $prep          From prepare().
	 * @param array  $fields_before Post fields before the write.
	 * @param array  $write         Write result.
	 * @param string $read          Hash read after the write and its hooks.
	 * @param array  $changed       Post fields that changed.
	 * @return \WP_Error
	 */
	private static function undo( array $p, $change_id, array $prep, array $fields_before, array $write, $read, array $changed ) {
		$post_id = (int) $p['payload']['post_id'];
		if ( $read === $p['expected_hash'] ) {
			$revert = self::write( $p, $prep['before'], $p['expected_hash'], true );
		} else {
			$revert = array(
				'written'    => false,
				'error'      => new \WP_Error( 'sseo_concurrent_edit', 'the stored value changed after the write; not reverted so that the other edit is kept' ),
				'hook_error' => null,
			);
		}
		$reverted_to = self::current_hash( $p );
		$still       = self::changed_fields( $fields_before, self::post_fields( $post_id ) );
		$status      = $revert['written'] && $reverted_to === $p['base_hash'] && array() === $still ? 'failed' : 'rollback_failed';
		$error       = null !== $write['hook_error'] ? $write['hook_error']->get_error_message() : 'other post fields changed during the write (' . implode( ', ', $changed ) . ')';
		$revert_err  = $revert['written'] ? $revert['hook_error'] : $revert['error'];
		Db::update(
			'changes',
			array(
				'status' => $status,
				'error'  => Util::truncate( $error, 1000 ),
				'verify' => Util::json(
					array(
						'readback_hash'       => $write['readback_hash'],
						'expected_hash'       => $p['expected_hash'],
						'other_fields_change' => $changed,
						'reverted'            => 'failed' === $status,
						'revert_error'        => null === $revert_err ? '' : $revert_err->get_error_message(),
						'manual_recovery'     => 'rollback_failed' === $status ? 'Check the post against the "before" snapshot in the change log or a WordPress revision' . ( array() !== $still ? ' and check: ' . implode( ', ', $still ) : '' ) . '.' : '',
					)
				),
			),
			array( 'id' => (int) $change_id )
		);
		Db::update(
			'proposals',
			array(
				'status'     => 'failed',
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $p['id'] )
		);
		AuditLog::record(
			'change.failed',
			$p['object_type'],
			$post_id,
			'error',
			array(
				'change_id' => (int) $change_id,
				'status'    => $status,
			)
		);
		return new \WP_Error(
			'sseo_apply_failed',
			'failed' === $status ? __( 'The write could not be verified and was reverted automatically.', 'semantic-seo-engine-vi-en' ) : __( 'The write could not be verified and the automatic revert also failed: manual recovery is needed (see change log).', 'semantic-seo-engine-vi-en' ),
			array(
				'status'    => 500,
				'change_id' => (int) $change_id,
			)
		);
	}

	/**
	 * Settles changes left in "applying" by a process that stopped between the write and the
	 * status update, from the value actually stored. Objects whose change lock is held (an apply in
	 * progress) are skipped.
	 *
	 * @param int $change_id One change, or 0 for the interrupted changes (oldest 20).
	 * @return void
	 */
	public static function reconcile( $change_id = 0 ) {
		$rows = $change_id > 0
			? Db::rows( "SELECT id, object_type, object_id FROM {changes} WHERE id = %d AND status = 'applying'", array( (int) $change_id ) )
			: Db::rows( "SELECT id, object_type, object_id FROM {changes} WHERE status = 'applying' ORDER BY id LIMIT 20" );
		foreach ( $rows as $row ) {
			$lock  = 'object_' . $row['object_type'] . '_' . (int) $row['object_id'];
			$mutex = Mutex::acquire( $lock, 120 );
			if ( false === $mutex ) {
				continue;
			}
			try {
				self::reconcile_one( (int) $row['id'] );
			} finally {
				Mutex::release( $lock, $mutex );
			}
		}
	}

	/**
	 * Settles one interrupted change (caller holds the object lock).
	 *
	 * @param int $change_id Change ID.
	 * @return void
	 */
	private static function reconcile_one( $change_id ) {
		$c = Db::row( "SELECT * FROM {changes} WHERE id = %d AND status = 'applying'", array( (int) $change_id ) );
		$p = null === $c ? null : ProposalService::get( (int) $c['proposal_id'] );
		if ( null === $p ) {
			return;
		}
		$post_id = (int) $c['object_id'];
		$current = self::current_hash( $p );
		if ( $current === $c['after_hash'] ) {
			$written = true;
		} elseif ( $current === $c['before_hash'] ) {
			$written = false;
		} elseif ( 'insert_internal_link' === $p['type'] ) {
			$written = false !== strpos( (string) get_post_field( 'post_content', $post_id, 'raw' ), Operations::link_markup( (string) $p['payload']['href'], (string) $p['payload']['anchor'] ) );
		} else {
			$written = true === is_object_in_term( $post_id, (string) $p['payload']['taxonomy'], (int) $p['payload']['term_id'] );
		}
		$now  = Util::now();
		$data = array(
			'status' => $written ? 'applied' : 'failed',
			'error'  => $written ? '' : 'interrupted before the write; nothing was changed',
			'verify' => Util::json(
				array(
					'reconciled'    => true,
					'reconciled_at' => $now,
					'current_hash'  => $current,
					'expected_hash' => $c['after_hash'],
					'note'          => 'The applying process stopped before recording its result; the status was set from the stored value. Hooks of other plugins may not have run for this change.',
				)
			),
		);
		if ( $written ) {
			$data['applied_at'] = $now;
		}
		Db::update( 'changes', $data, array( 'id' => (int) $c['id'] ) );
		Db::update(
			'proposals',
			array(
				'status'     => $written ? 'applied' : 'stale',
				'updated_at' => $now,
			),
			array( 'id' => (int) $p['id'] )
		);
		if ( $written ) {
			if ( $p['finding_id'] ) {
				FindingRepository::set_status( (int) $p['finding_id'], 'accepted' );
			}
			DocumentRepository::mark_dirty( 'post', $post_id );
		}
		AuditLog::record(
			'change.reconciled',
			$c['object_type'],
			$post_id,
			$written ? 'ok' : 'error',
			array(
				'change_id' => (int) $c['id'],
				'status'    => $data['status'],
			)
		);
	}

	/**
	 * Post fields that a change must never alter (only post_content or the term list may change),
	 * and the public URL.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	private static function post_fields( $post_id ) {
		clean_post_cache( (int) $post_id );
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return array();
		}
		$out = array();
		foreach ( array( 'post_title', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'post_author', 'post_password', 'menu_order', 'post_type' ) as $f ) {
			$out[ $f ] = (string) $post->$f;
		}
		$out['permalink'] = (string) get_permalink( $post );
		return $out;
	}

	/**
	 * Names of fields that differ.
	 *
	 * @param array $before Fields before.
	 * @param array $after  Fields after.
	 * @return string[]
	 */
	private static function changed_fields( array $before, array $after ) {
		$out = array();
		foreach ( $before as $k => $v ) {
			if ( ! array_key_exists( $k, $after ) || $after[ $k ] !== $v ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/**
	 * Writes a proposal's value: the content of a link change, or adds (removes when reverting) the
	 * term of a category change.
	 *
	 * @param array  $p         Proposal.
	 * @param string $value     New content (link changes).
	 * @param string $from_hash Hash of the content $value was computed from (link changes).
	 * @param bool   $revert    Category changes: remove the term instead of adding it.
	 * @return array{written:bool,error:?\WP_Error,hook_error:?\WP_Error,readback_hash:string}
	 */
	public static function write( array $p, $value, $from_hash, $revert = false ) {
		$payload = $p['payload'];
		$post_id = (int) $payload['post_id'];
		if ( 'insert_internal_link' === $p['type'] ) {
			return self::write_content( $post_id, (string) $value, (string) $from_hash );
		}
		if ( 'assign_category' === $p['type'] ) {
			$res                  = self::write_term( $post_id, (string) $payload['taxonomy'], (int) $payload['term_id'], ! $revert );
			$res['readback_hash'] = self::current_hash( $p );
			return $res;
		}
		return array(
			'written'       => false,
			'error'         => new \WP_Error( 'sseo_unsupported', 'Unsupported operation.' ),
			'hook_error'    => null,
			'readback_hash' => '',
		);
	}

	/**
	 * Writes post_content with a compare-and-set on the stored value, verifies it, then announces
	 * the update through the hooks wp_insert_post() fires (caches, SEO plugins, revisions).
	 *
	 * Not wp_update_post(): it saves the whole post again through wp_insert_post(), which
	 * can change fields this change never touches (it regenerates a non-canonical or conflicting
	 * slug, unpublishes a post dated in the future, gives a category-less post the default
	 * category, re-resolves tags by name, rewrites invalid entities through content_save_pre) and it
	 * overwrites an edit committed after the stale check. Here only post_content and the modified
	 * dates change, only while the stored content is byte-identical to the value the change was
	 * computed from, and the stored bytes are read back before any hook runs.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $value     New content.
	 * @param string $from_hash SHA-256 of the stored content $value was computed from.
	 * @return array{written:bool,error:?\WP_Error,hook_error:?\WP_Error,readback_hash:string}
	 */
	public static function write_content( $post_id, $value, $from_hash ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$out     = array(
			'written'       => false,
			'error'         => null,
			'hook_error'    => null,
			'readback_hash' => '',
		);
		clean_post_cache( $post_id );
		$post_before = get_post( $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		if ( ! $post_before instanceof \WP_Post || ! is_array( $row ) ) {
			$out['error'] = new \WP_Error( 'sseo_not_found', 'The post no longer exists.' );
			return $out;
		}
		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );
		unset( $row['ID'] );
		try {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired as wp_insert_post() does before an update.
			do_action(
				'pre_post_update',
				$post_id,
				array_merge(
					$row,
					array(
						'post_content'      => $value,
						'post_modified'     => $now,
						'post_modified_gmt' => $now_gmt,
					)
				)
			);
			// phpcs:enable
		} catch ( \Throwable $e ) {
			$out['error'] = self::exception_error( $e );
			return $out;
		}
		// The stored content is hashed as the client receives it (in the connection charset), which is
		// how $from_hash was computed from get_post_field().
		$charset = preg_match( '/^[A-Za-z0-9_]+$/', (string) $wpdb->charset ) ? (string) $wpdb->charset : 'utf8mb4';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and validated charset identifier.
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = %s, post_modified = %s, post_modified_gmt = %s WHERE ID = %d AND SHA2(CONVERT(post_content USING {$charset}), 256) = %s", $value, $now, $now_gmt, $post_id, $from_hash ) );
		clean_post_cache( $post_id );
		if ( false === $rows ) {
			$out['error'] = new \WP_Error( 'sseo_write_failed', 'Database error: ' . Util::truncate( (string) $wpdb->last_error, 300 ) );
			return $out;
		}
		if ( 1 !== (int) $rows ) {
			$out['error'] = new \WP_Error( 'sseo_concurrent_edit', 'The stored content changed after the stale check (another edit was saved at the same moment); nothing was written.' );
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored               = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		$out['readback_hash'] = Util::sha256( $stored );
		$sent                 = Util::sha256( $value );
		if ( $sent !== $out['readback_hash'] ) {
			// The database did not store the bytes as sent (column charset or size): put the previous
			// row values back before any hook or reader sees them.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and validated charset identifier.
			$back = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = %s, post_modified = %s, post_modified_gmt = %s WHERE ID = %d AND SHA2(CONVERT(post_content USING {$charset}), 256) = %s", $row['post_content'], $row['post_modified'], $row['post_modified_gmt'], $post_id, $out['readback_hash'] ) );
			clean_post_cache( $post_id );
			$out['written'] = 1 !== (int) $back;
			$out['error']   = new \WP_Error( 'sseo_readback_mismatch', 'The database stored different bytes than were written' . ( 1 === (int) $back ? '; the previous content was put back.' : ' and the previous content could not be put back.' ) );
			if ( $out['written'] ) {
				$out['hook_error'] = $out['error'];
			}
			return $out;
		}
		$out['written']    = true;
		$out['hook_error'] = self::announce_update( $post_id, $post_before );
		return $out;
	}

	/**
	 * Fires the hooks wp_insert_post() fires after updating a post, in the same order.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_before Post before the update.
	 * @return \WP_Error|null Exception raised by a hook.
	 */
	private static function announce_update( $post_id, \WP_Post $post_before ) {
		try {
			$post = get_post( $post_id );
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hooks, fired as wp_insert_post() does after an update.
			do_action( 'edit_post', $post_id, $post );
			do_action( 'post_updated', $post_id, get_post( $post_id ), $post_before );
			do_action( "save_post_{$post->post_type}", $post_id, $post, true );
			do_action( 'save_post', $post_id, $post, true );
			do_action( 'wp_insert_post', $post_id, $post, true );
			// phpcs:enable
			wp_after_insert_post( $post, true, $post_before );
		} catch ( \Throwable $e ) {
			return self::exception_error( $e );
		} finally {
			clean_post_cache( $post_id );
		}
		return null;
	}

	/**
	 * Adds or removes one term. Never rewrites the rest of the term list, so a term another user
	 * changes at the same moment is kept.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @param bool   $add      Add (true) or remove (false).
	 * @return array{written:bool,error:?\WP_Error,hook_error:?\WP_Error}
	 */
	public static function write_term( $post_id, $taxonomy, $term_id, $add ) {
		$res = null;
		try {
			$res = $add ? wp_add_object_terms( (int) $post_id, (int) $term_id, $taxonomy ) : wp_remove_object_terms( (int) $post_id, (int) $term_id, $taxonomy );
		} catch ( \Throwable $e ) {
			$res = self::exception_error( $e );
		} finally {
			clean_object_term_cache( (int) $post_id, (string) get_post_type( (int) $post_id ) );
		}
		$has     = is_object_in_term( (int) $post_id, $taxonomy, (int) $term_id );
		$written = $add ? true === $has : false === $has;
		$error   = is_wp_error( $res ) ? $res : null;
		return array(
			'written'    => $written,
			'error'      => $written ? null : ( null !== $error ? $error : new \WP_Error( 'sseo_write_failed', 'The term list did not change.' ) ),
			'hook_error' => $written ? $error : null,
		);
	}

	/**
	 * An exception raised during a write, as an error (message truncated, no stack trace).
	 *
	 * @param \Throwable $e Exception.
	 * @return \WP_Error
	 */
	private static function exception_error( \Throwable $e ) {
		return new \WP_Error( 'sseo_write_exception', 'The write raised ' . get_class( $e ) . ': ' . Util::truncate( $e->getMessage(), 200 ) );
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
		self::reconcile();
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
