<?php
/**
 * Page 12 — Audit / Undo / Rollback: proposals (review & approve), changes (rollback), approvals, log.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Capabilities;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Human approval screen. Approving requires reviewing the exact diff and ticking a confirmation.
 */
final class AuditPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Audit / Undo / Rollback', 'semantic-seo-engine-vi-en' ), __( 'Every change is an exact, hash-bound diff: preview → human approval → apply with readback verification → rollback if needed.', 'semantic-seo-engine-vi-en' ) );
		$tab  = Ui::get( 'tab', 'proposals' );
		$tabs = array(
			'proposals' => __( 'Proposals', 'semantic-seo-engine-vi-en' ),
			'changes'   => __( 'Applied changes', 'semantic-seo-engine-vi-en' ),
			'approvals' => __( 'Approvals', 'semantic-seo-engine-vi-en' ),
			'log'       => __( 'Audit log', 'semantic-seo-engine-vi-en' ),
		);
		echo '<nav class="nav-tab-wrapper sseo-tabs" aria-label="' . esc_attr__( 'Sections', 'semantic-seo-engine-vi-en' ) . '">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( 'sseo-audit', array( 'tab' => $k ) ) ) . '"' . ( $tab === $k ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		switch ( $tab ) {
			case 'changes':
				self::changes();
				break;
			case 'approvals':
				self::approvals();
				break;
			case 'log':
				self::log();
				break;
			default:
				self::proposals();
		}
		Ui::footer();
	}

	/**
	 * Proposals awaiting review / application.
	 *
	 * @return void
	 */
	private static function proposals() {
		$status = Ui::get( 'status', 'previewed' );
		echo '<form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-audit"><input type="hidden" name="tab" value="proposals">' . Ui::select( 'status', array( 'previewed' => Ui::term( 'previewed' ), 'approved' => Ui::term( 'approved' ), 'applied' => Ui::term( 'applied' ), 'rolled_back' => Ui::term( 'rolled_back' ), 'stale' => Ui::term( 'stale' ), 'rejected' => Ui::term( 'rejected' ), 'failed' => Ui::term( 'failed' ) ), $status, __( 'Status', 'semantic-seo-engine-vi-en' ) ) . ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$res = Api::proposals(
			array(
				'status' => $status,
				'limit'  => 20,
			)
		);
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			return;
		}
		if ( array() === $res['items'] ) {
			Ui::empty_state( __( 'No proposals in this state. Create previews from link or classification suggestions.', 'semantic-seo-engine-vi-en' ) );
			return;
		}
		foreach ( $res['items'] as $p ) {
			$post = get_post( (int) $p['object_id'] );
			echo '<article class="sseo-panel sseo-proposal"><h3>' . esc_html( sprintf( /* translators: 1: id, 2: type */ __( 'Proposal #%1$d — %2$s', 'semantic-seo-engine-vi-en' ), $p['id'], $p['type'] ) ) . ' ' . Ui::badge( $p['status'], 'status' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p>' . esc_html__( 'Object:', 'semantic-seo-engine-vi-en' ) . ' <a href="' . esc_url( (string) get_edit_post_link( (int) $p['object_id'] ) ) . '">' . esc_html( $post ? $post->post_title : '#' . $p['object_id'] ) . '</a> · ' . esc_html__( 'created via', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( $p['created_via'] ) . ' · ' . esc_html( $p['created_at'] ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Why:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html( (string) $p['rationale'] ) . '</p>';
			$d = $p['diff'];
			if ( 'insert_internal_link' === $p['type'] ) {
				echo Ui::diff( isset( $d['before'] ) ? $d['before'] : '', isset( $d['after'] ) ? $d['after'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			} else {
				echo '<p>' . esc_html__( 'Before:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( implode( ', ', (array) $d['before'] ) ) . '<br>' . esc_html__( 'After:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( implode( ', ', (array) $d['after'] ) ) . '</p>';
			}
			echo '<p class="description">' . esc_html__( 'Unchanged:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( implode( ', ', (array) $d['unchanged'] ) ) . '</p>';
			echo '<p class="sseo-hashes"><small>' . esc_html( 'diff_hash ' . $p['diff_hash'] ) . '<br>' . esc_html( 'base_hash ' . $p['base_hash'] ) . '</small></p>';

			if ( 'previewed' === $p['status'] && current_user_can( Capabilities::REVIEW ) ) {
				Ui::form_open( 'approve', 'sseo-approve' );
				echo '<input type="hidden" name="proposal_id" value="' . esc_attr( (string) $p['id'] ) . '">';
				echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I reviewed this exact change and approve it for this single object.', 'semantic-seo-engine-vi-en' ) . '</label></p>';
				echo '<p><label for="sseo-note-' . esc_attr( (string) $p['id'] ) . '">' . esc_html__( 'Note (optional)', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-note-' . esc_attr( (string) $p['id'] ) . '" type="text" name="note" maxlength="200"></p>';
				echo '<button class="button button-primary">' . esc_html__( 'Approve', 'semantic-seo-engine-vi-en' ) . '</button></form> ';
				echo Ui::action_button( 'reject', array( 'proposal_id' => $p['id'] ), __( 'Reject', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'approved' === $p['status'] ) {
				$approvals = ApprovalService::query(
					array(
						'proposal_id' => $p['id'],
						'status'      => 'active',
					)
				);
				foreach ( $approvals as $a ) {
					echo '<p>' . esc_html( sprintf( /* translators: 1: id, 2: expiry */ __( 'Approval #%1$d active until %2$s UTC.', 'semantic-seo-engine-vi-en' ), $a['id'], $a['expires_at'] ) ) . '</p>';
					if ( current_user_can( Capabilities::APPLY ) ) {
						echo Ui::action_button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							'apply',
							array(
								'proposal_id'     => $p['id'],
								'approval_id'     => $a['id'],
								'idempotency_key' => 'ui-' . wp_generate_uuid4(),
							),
							__( 'Apply now', 'semantic-seo-engine-vi-en' ),
							'button button-primary'
						);
					}
					if ( current_user_can( Capabilities::REVIEW ) ) {
						echo ' ' . Ui::action_button( 'revoke_approval', array( 'approval_id' => $a['id'] ), __( 'Revoke approval', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				}
			}
			echo '</article>';
		}
	}

	/**
	 * Applied changes with rollback.
	 *
	 * @return void
	 */
	private static function changes() {
		$res = Api::changes( array( 'limit' => 50 ) );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			return;
		}
		$rows = array();
		foreach ( $res['items'] as $c ) {
			$post    = get_post( (int) $c['object_id'] );
			$actions = '';
			if ( $c['reversible'] && current_user_can( Capabilities::APPLY ) ) {
				ob_start();
				Ui::form_open( 'rollback', 'sseo-inline-form' );
				echo '<input type="hidden" name="change_id" value="' . esc_attr( (string) $c['change_id'] ) . '"><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Confirm', 'semantic-seo-engine-vi-en' ) . '</label> <button class="button">' . esc_html__( 'Roll back', 'semantic-seo-engine-vi-en' ) . '</button></form>';
				$actions = (string) ob_get_clean();
				if ( current_user_can( Capabilities::REVIEW ) ) {
					$actions .= Ui::action_button( 'rollback_approval', array( 'change_id' => $c['change_id'] ), __( 'Authorize an agent rollback', 'semantic-seo-engine-vi-en' ) );
				}
			}
			$verify = (array) $c['verify'];
			$rows[] = array(
				'change'  => esc_html( '#' . $c['change_id'] . ' (' . $c['field'] . ')' ) . '<br><small>' . esc_html( (string) $c['applied_at'] ) . '</small>',
				'object'  => esc_html( $post ? $post->post_title : '#' . $c['object_id'] ),
				'status'  => Ui::badge( $c['status'], 'status' ) . ( '' !== $c['error'] ? '<br><small class="sseo-error">' . esc_html( $c['error'] ) . '</small>' : '' ),
				'verify'  => esc_html( isset( $verify['readback'] ) && $verify['readback'] ? __( 'readback verified', 'semantic-seo-engine-vi-en' ) : '' ) . ( isset( $verify['rollback_mode'] ) ? '<br>' . esc_html( __( 'rollback:', 'semantic-seo-engine-vi-en' ) . ' ' . $verify['rollback_mode'] ) : '' ) . ( ! empty( $verify['manual_recovery'] ) ? '<br><strong>' . esc_html( $verify['manual_recovery'] ) . '</strong>' : '' ),
				'actor'   => esc_html( $c['actor_via'] ),
				'actions' => $actions,
			);
		}
		Ui::table(
			__( 'Applied changes', 'semantic-seo-engine-vi-en' ),
			array(
				'change'  => __( 'Change', 'semantic-seo-engine-vi-en' ),
				'object'  => __( 'Object', 'semantic-seo-engine-vi-en' ),
				'status'  => __( 'Status', 'semantic-seo-engine-vi-en' ),
				'verify'  => __( 'Verification', 'semantic-seo-engine-vi-en' ),
				'actor'   => __( 'Applied via', 'semantic-seo-engine-vi-en' ),
				'actions' => __( 'Undo', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No changes have been applied by this plugin.', 'semantic-seo-engine-vi-en' )
		);
	}

	/**
	 * Approvals list.
	 *
	 * @return void
	 */
	private static function approvals() {
		$rows = array();
		foreach ( ApprovalService::query( array( 'limit' => 50 ) ) as $a ) {
			$u      = get_userdata( (int) $a['approver_id'] );
			$rows[] = array(
				'id'      => esc_html( '#' . $a['id'] . ' ' . $a['action'] ),
				'target'  => esc_html( (int) $a['proposal_id'] > 0 ? sprintf( /* translators: %d: id */ __( 'proposal #%d', 'semantic-seo-engine-vi-en' ), $a['proposal_id'] ) : '' ) . ( (int) $a['change_id'] > 0 ? esc_html( ' · change #' . $a['change_id'] ) : '' ),
				'by'      => esc_html( ( $u ? $u->user_login : '#' . $a['approver_id'] ) . ' · ' . $a['approver_via'] ),
				'status'  => Ui::badge( $a['status'], 'status' ),
				'expires' => esc_html( $a['expires_at'] ),
			);
		}
		Ui::table(
			__( 'Approvals', 'semantic-seo-engine-vi-en' ),
			array(
				'id'      => __( 'Approval', 'semantic-seo-engine-vi-en' ),
				'target'  => __( 'Target', 'semantic-seo-engine-vi-en' ),
				'by'      => __( 'Approved by', 'semantic-seo-engine-vi-en' ),
				'status'  => __( 'Status', 'semantic-seo-engine-vi-en' ),
				'expires' => __( 'Expires (UTC)', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No approvals yet.', 'semantic-seo-engine-vi-en' )
		);
	}

	/**
	 * Audit log.
	 *
	 * @return void
	 */
	private static function log() {
		$args = array(
			'action'    => Ui::get( 'action_filter' ),
			'result'    => Ui::get( 'result' ),
			'before_id' => (int) Ui::get( 'before', '0' ),
			'limit'     => 50,
		);
		echo '<form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-audit"><input type="hidden" name="tab" value="log"><label for="sseo-af">' . esc_html__( 'Action starts with', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-af" type="text" name="action_filter" value="' . esc_attr( $args['action'] ) . '"> ' . Ui::select( 'result', array( '' => __( 'All', 'semantic-seo-engine-vi-en' ), 'ok' => 'ok', 'denied' => 'denied', 'error' => 'error' ), $args['result'], __( 'Result', 'semantic-seo-engine-vi-en' ) ) . ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$res = Api::audit_log( $args );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			return;
		}
		$rows = array();
		foreach ( $res['items'] as $r ) {
			$u      = get_userdata( (int) $r['user_id'] );
			$rows[] = array(
				'when'    => esc_html( $r['created_at'] ),
				'who'     => esc_html( ( $u ? $u->user_login : ( (int) $r['user_id'] > 0 ? '#' . $r['user_id'] : 'system' ) ) . ' · ' . $r['via'] ),
				'action'  => '<code>' . esc_html( $r['action'] ) . '</code>',
				'object'  => esc_html( '' !== $r['object_type'] ? $r['object_type'] . ' #' . $r['object_id'] : '' ),
				'result'  => Ui::badge( $r['result'], 'status' ),
				'details' => '<code class="sseo-evidence-json">' . esc_html( (string) wp_json_encode( $r['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code>',
			);
		}
		Ui::table(
			__( 'Audit log', 'semantic-seo-engine-vi-en' ),
			array(
				'when'    => __( 'When (UTC)', 'semantic-seo-engine-vi-en' ),
				'who'     => __( 'Who / via', 'semantic-seo-engine-vi-en' ),
				'action'  => __( 'Action', 'semantic-seo-engine-vi-en' ),
				'object'  => __( 'Object', 'semantic-seo-engine-vi-en' ),
				'result'  => __( 'Result', 'semantic-seo-engine-vi-en' ),
				'details' => __( 'Details (redacted)', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'The audit log is empty.', 'semantic-seo-engine-vi-en' )
		);
		if ( $res['next_before_id'] > 0 ) {
			echo '<p><a class="button" href="' . esc_url( Ui::url( 'sseo-audit', array( 'tab' => 'log', 'before' => $res['next_before_id'], 'action_filter' => $args['action'], 'result' => $args['result'] ) ) ) . '">' . esc_html__( 'Older ›', 'semantic-seo-engine-vi-en' ) . '</a></p>';
		}
	}
}
