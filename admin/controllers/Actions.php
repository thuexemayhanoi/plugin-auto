<?php
/**
 * Handlers for admin-post.php (Human Mode). Every handler verifies a nonce, then calls the shared
 * service layer (which re-checks capabilities, locks and rate limits).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Approval\ProposalService;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Gsc\GscRepository;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Matrix\CsvReader;
use SemanticSEO\Matrix\MatrixExporter;
use SemanticSEO\Matrix\MatrixImporter;
use SemanticSEO\Services\Api;
use SemanticSEO\Services\Guard;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Form handlers.
 */
final class Actions {

	const ACTIONS = array( 'scan', 'repair_db', 'export_priorities', 'start_job', 'job_control', 'review_finding', 'create_preview', 'approve', 'reject', 'apply', 'rollback', 'rollback_approval', 'revoke_approval', 'lock', 'unlock', 'editorial', 'save_settings', 'agent_roles', 'assign_agent_role', 'export', 'import_preview', 'import_apply', 'gsc_import', 'gsc_delete', 'wizard', 'cluster_lock' );

	/**
	 * Registers handlers.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		foreach ( self::ACTIONS as $a ) {
			add_action( 'admin_post_sseo_' . $a, array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * Dispatches the current admin-post action.
	 *
	 * @return void
	 */
	public static function dispatch() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$name   = substr( $action, 5 );
		if ( 0 !== strpos( $action, 'sseo_' ) || ! in_array( $name, self::ACTIONS, true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'semantic-seo-engine-vi-en' ), 400 );
		}
		check_admin_referer( $action );
		if ( ! current_user_can( Capabilities::READ ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'semantic-seo-engine-vi-en' ), 403 );
		}
		$result = call_user_func( array( __CLASS__, 'do_' . $name ) );
		if ( is_wp_error( $result ) ) {
			Ui::queue_notice( 'error', $result->get_error_message() );
		} elseif ( is_string( $result ) && '' !== $result ) {
			Ui::queue_notice( 'success', $result );
		}
		self::back();
	}

	/**
	 * POST value helper.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function p( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in dispatch().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Redirects back to the originating admin page.
	 *
	 * @param array $args Extra query args.
	 * @return void
	 */
	private static function back( array $args = array() ) {
		$back = self::p( '_sseo_back' );
		$url  = '' !== $back ? wp_validate_redirect( $back, Ui::url( 'sseo' ) ) : Ui::url( 'sseo' );
		if ( 0 !== strpos( $url, admin_url() ) && 0 !== strpos( $url, '/wp-admin/' ) ) {
			$url = Ui::url( 'sseo' );
		}
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Starts a job.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_start_job() {
		$args = array(
			'type'         => self::p( 'type' ),
			'then_analyze' => '1' === self::p( 'then_analyze' ),
		);
		$ids  = preg_split( '/[\s,]+/', self::p( 'post_ids' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( array() !== $ids ) {
			$args['post_ids'] = array_map( 'intval', $ids );
		}
		$res = Api::start_analysis( $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		/* translators: 1: job id, 2: job type */
		return sprintf( __( 'Job #%1$d (%2$s) queued. Progress is shown under Jobs.', 'semantic-seo-engine-vi-en' ), $res['id'], Ui::term( $res['type'] ) );
	}

	/**
	 * Dashboard scans: the 20-page trial or the whole site (inventory, then analysis; read-only).
	 *
	 * @return string|\WP_Error
	 */
	private static function do_scan() {
		if ( 'pilot' === self::p( 'scope' ) ) {
			$ids = OverviewPage::pilot_ids();
			if ( array() === $ids ) {
				return new \WP_Error( 'sseo_empty', __( 'No published posts in scope.', 'semantic-seo-engine-vi-en' ) );
			}
			$res = Api::start_analysis(
				array(
					'type'         => 'inventory',
					'post_ids'     => $ids,
					'then_analyze' => true,
					'pilot'        => true,
				)
			);
			/* translators: %d: number of posts */
			return is_wp_error( $res ) ? $res : sprintf( __( 'Trial started on the %d most recently updated posts. Nothing on the site is changed.', 'semantic-seo-engine-vi-en' ), count( $ids ) );
		}
		$res = Api::start_analysis(
			array(
				'type'         => 'inventory',
				'then_analyze' => true,
			)
		);
		return is_wp_error( $res ) ? $res : __( 'Analysis of the whole site started. It runs in the background; you can pause or cancel it on this page.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Repairs the plugin database (creates missing tables and columns; never drops anything).
	 *
	 * @return string|\WP_Error
	 */
	private static function do_repair_db() {
		$g = Guard::check( Capabilities::MANAGE, 'write', 'repair_db' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$res = \SemanticSEO\Database\Migrator::migrate();
		AuditLog::record( 'db.repair', 'database', 0, '' === $res['error'] ? 'ok' : 'error', array( 'error' => $res['error'] ) );
		if ( '' !== $res['error'] ) {
			$sql = array();
			foreach ( isset( $res['failures'] ) ? (array) $res['failures'] : array() as $table => $e ) {
				$sql[] = $table . ': #' . (int) $e['errno'] . ' SQLSTATE ' . $e['sqlstate'] . ' ' . $e['message'];
			}
			/* translators: 1: error, 2: SQL errors */
			return new \WP_Error( 'sseo_db_repair', sprintf( __( 'The database could not be completed: %1$s %2$s', 'semantic-seo-engine-vi-en' ), $res['error'], implode( ' | ', $sql ) ) );
		}
		/* translators: %d: schema version */
		return sprintf( __( 'Database ready (schema %d). No data was deleted.', 'semantic-seo-engine-vi-en' ), (int) $res['to'] );
	}

	/**
	 * Streams the priority queue as CSV.
	 *
	 * @return \WP_Error|void
	 */
	private static function do_export_priorities() {
		$g = Guard::check( Capabilities::READ, 'read', 'export_priorities' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$filters = array(
			'locale'   => sanitize_key( self::p( 'locale' ) ),
			'category' => sanitize_key( self::p( 'category' ) ),
			'priority' => (int) self::p( 'priority' ),
			'search'   => self::p( 'search' ),
		);
		AuditLog::record( 'priorities.exported', 'report', 0, 'ok', $filters );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="seo-priorities-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		\SemanticSEO\Analysis\PriorityQueue::csv( $out, $filters );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Job control.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_job_control() {
		$op  = self::p( 'op' );
		$res = Api::job_control( (int) self::p( 'job_id' ), in_array( $op, array( 'cancel', 'pause', 'resume', 'tick' ), true ) ? $op : 'tick' );
		return is_wp_error( $res ) ? $res : __( 'Job updated.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Review a finding.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_review_finding() {
		$res = Api::review_finding( (int) self::p( 'finding_id' ), self::p( 'status' ) );
		return is_wp_error( $res ) ? $res : __( 'Review saved.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Creates a change preview.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_create_preview() {
		$res = Api::create_change_preview( array( 'finding_ids' => array( (int) self::p( 'finding_id' ) ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$first = $res['previews'][0];
		if ( isset( $first['error'] ) ) {
			return new \WP_Error( $first['error'], $first['message'] );
		}
		return __( 'Preview created. Review the exact diff under Audit / Undo / Rollback → Proposals before approving.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Approves a proposal (human only, explicit confirmation).
	 *
	 * @return string|\WP_Error
	 */
	private static function do_approve() {
		if ( '1' !== self::p( 'confirm' ) ) {
			return new \WP_Error( 'sseo_confirm', __( 'Tick the confirmation box after reviewing the exact diff.', 'semantic-seo-engine-vi-en' ) );
		}
		$res = ApprovalService::approve( (int) self::p( 'proposal_id' ), self::p( 'note' ) );
		return is_wp_error( $res ) ? $res : __( 'Approved. The change can now be applied (by you, or by an agent if you allowed it).', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Rejects a proposal.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_reject() {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'reject' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'reject' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		return ProposalService::reject( (int) self::p( 'proposal_id' ) ) ? __( 'Proposal rejected.', 'semantic-seo-engine-vi-en' ) : new \WP_Error( 'sseo_invalid', __( 'Proposal cannot be rejected in its current state.', 'semantic-seo-engine-vi-en' ) );
	}

	/**
	 * Applies an approved proposal.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_apply() {
		$res = Api::apply_approved_change(
			array(
				'proposal_id'     => (int) self::p( 'proposal_id' ),
				'approval_id'     => (int) self::p( 'approval_id' ),
				'idempotency_key' => self::p( 'idempotency_key' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		/* translators: %d: change id */
		return sprintf( __( 'Change #%d applied and verified by readback.', 'semantic-seo-engine-vi-en' ), $res['change_id'] );
	}

	/**
	 * Rolls back a change (interactive human confirmation).
	 *
	 * @return string|\WP_Error
	 */
	private static function do_rollback() {
		if ( '1' !== self::p( 'confirm' ) ) {
			return new \WP_Error( 'sseo_confirm', __( 'Confirm the rollback first.', 'semantic-seo-engine-vi-en' ) );
		}
		$res = Api::rollback_change( array( 'change_id' => (int) self::p( 'change_id' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		/* translators: %s: exact|surgical */
		return sprintf( __( 'Rolled back (%s) and verified.', 'semantic-seo-engine-vi-en' ), $res['verify']['rollback_mode'] );
	}

	/**
	 * Issues a rollback approval for agents.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_rollback_approval() {
		$res = ApprovalService::approve_rollback( (int) self::p( 'change_id' ) );
		/* translators: %d: approval id */
		return is_wp_error( $res ) ? $res : sprintf( __( 'Rollback approval #%d issued (single use, expiring).', 'semantic-seo-engine-vi-en' ), $res['id'] );
	}

	/**
	 * Revokes an approval.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_revoke_approval() {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'revoke' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'revoke' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		return ApprovalService::revoke( (int) self::p( 'approval_id' ) ) ? __( 'Approval revoked.', 'semantic-seo-engine-vi-en' ) : new \WP_Error( 'sseo_invalid', __( 'Approval is not active.', 'semantic-seo-engine-vi-en' ) );
	}

	/**
	 * Adds a lock/exclusion.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_lock() {
		$res = Api::add_lock( self::p( 'scope' ), self::p( 'value' ), self::p( 'lock_type' ), self::p( 'reason' ) );
		return is_wp_error( $res ) ? $res : __( 'Lock saved. It applies to WP Admin and agents alike.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Removes a lock.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_unlock() {
		$res = Api::remove_lock( (int) self::p( 'lock_id' ) );
		return is_wp_error( $res ) ? $res : __( 'Lock removed.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Locks/unlocks a cluster.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_cluster_lock() {
		$cid = (int) self::p( 'cluster_id' );
		if ( '1' === self::p( 'lock' ) ) {
			return self::do_lock_value( 'cluster', (string) $cid );
		}
		$id  = (int) Db::value( "SELECT id FROM {locks} WHERE scope_type = 'cluster' AND scope_value = %s AND lock_type = 'lock'", array( (string) $cid ) );
		$res = Api::remove_lock( $id );
		return is_wp_error( $res ) ? $res : __( 'Cluster unlocked.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Adds a lock with explicit values.
	 *
	 * @param string $scope Scope.
	 * @param string $value Value.
	 * @return string|\WP_Error
	 */
	private static function do_lock_value( $scope, $value ) {
		$res = Api::add_lock( $scope, $value, 'lock', self::p( 'reason' ) );
		return is_wp_error( $res ) ? $res : __( 'Locked.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Editorial overrides.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_editorial() {
		$fields = array();
		foreach ( array( 'editorial_intent', 'review_state', 'cluster_role', 'cluster_id' ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $k ] ) ) {
				$fields[ $k ] = self::p( $k );
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['cluster_locked_present'] ) ) {
			$fields['cluster_locked'] = '1' === self::p( 'cluster_locked' );
		}
		$res = Api::set_editorial( (int) self::p( 'doc_id' ), $fields );
		return is_wp_error( $res ) ? $res : __( 'Editorial settings saved (plugin data only; WordPress content unchanged).', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Saves settings (managers, interactive).
	 *
	 * @return string|\WP_Error
	 */
	private static function do_save_settings() {
		$g = Guard::check( Capabilities::MANAGE, 'write', 'save_settings' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'save_settings' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Settings::sanitize whitelist.
		$raw  = isset( $_POST['sseo'] ) && is_array( $_POST['sseo'] ) ? wp_unslash( $_POST['sseo'] ) : array();
		$form = self::p( 'form' );
		if ( in_array( $form, array( 'settings', 'related', 'agents' ), true ) ) {
			$scoped = array(
				'settings' => array( 'include_drafts', 'auto_incremental', 'allow_proposals', 'verify_http' ),
				'related'  => array( 'related_enabled', 'related_css' ),
				'agents'   => array( 'agents_may_apply', 'mcp_enabled' ),
			);
			foreach ( $scoped[ $form ] as $b ) {
				$raw[ $b ] = ! empty( $raw[ $b ] );
			}
			if ( 'agents' === $form ) {
				$raw['abilities_rest'] = isset( $raw['abilities_rest'] ) ? (array) $raw['abilities_rest'] : array();
				$raw['mcp_abilities']  = isset( $raw['mcp_abilities'] ) ? (array) $raw['mcp_abilities'] : array();
			}
		}
		foreach ( array( 'post_types', 'taxonomies' ) as $list_key ) {
			if ( isset( $raw[ $list_key ] ) ) {
				$joined           = is_array( $raw[ $list_key ] ) ? implode( ',', $raw[ $list_key ] ) : (string) $raw[ $list_key ];
				$raw[ $list_key ] = array_values( array_filter( array_map( 'trim', explode( ',', $joined ) ) ) );
			}
		}
		if ( isset( $raw['uninstall_policy'] ) && 'delete' === $raw['uninstall_policy'] && 'DELETE' !== self::p( 'confirm_delete' ) ) {
			return new \WP_Error( 'sseo_confirm', __( 'To choose "delete all data on uninstall", type DELETE in the confirmation field.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( isset( $raw['url_prefix_locales'] ) && is_string( $raw['url_prefix_locales'] ) ) {
			$map = array();
			foreach ( preg_split( '/[\r\n]+/', $raw['url_prefix_locales'] ) as $line ) {
				$parts = array_map( 'trim', explode( '=', $line, 2 ) );
				if ( 2 === count( $parts ) ) {
					$map[ $parts[0] ] = $parts[1];
				}
			}
			$raw['url_prefix_locales'] = $map;
		}
		$before = Settings::all();
		$after  = Settings::update( $raw );
		$diff   = array();
		foreach ( $after as $k => $v ) {
			if ( $before[ $k ] !== $v ) {
				$diff[ $k ] = array(
					'from' => $before[ $k ],
					'to'   => $v,
				);
			}
		}
		AuditLog::record( 'settings.updated', 'settings', 0, 'ok', array( 'changed' => $diff ) );
		return __( 'Settings saved.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Creates the agent roles.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_agent_roles() {
		$g = Guard::check( Capabilities::MANAGE, 'write', 'agent_roles' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'agent_roles' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$roles = Capabilities::ensure_agent_roles();
		AuditLog::record( 'agents.roles_created', '', 0, 'ok', array( 'roles' => $roles ) );
		return __( 'Agent roles are available.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Assigns or removes an agent role on a dedicated user account.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_assign_agent_role() {
		$g = Guard::check( Capabilities::MANAGE, 'write', 'assign_agent_role' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'assign_agent_role' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$user = get_userdata( (int) self::p( 'user_id' ) );
		$role = self::p( 'role' );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'sseo_invalid', __( 'User not found.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( get_current_user_id() === (int) $user->ID || user_can( $user, 'manage_options' ) || is_super_admin( $user->ID ) ) {
			return new \WP_Error( 'sseo_invalid', __( 'Use a dedicated, low-privilege account for agents — administrators and your own account cannot become agent accounts.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( 'none' === $role ) {
			$user->set_role( 'subscriber' );
			delete_user_meta( $user->ID, 'sseo_agent_account' );
			AuditLog::record( 'agents.role_removed', 'user', (int) $user->ID );
			return __( 'Agent role removed (user is now a subscriber).', 'semantic-seo-engine-vi-en' );
		}
		if ( ! isset( Capabilities::AGENT_ROLES[ $role ] ) || null === get_role( $role ) ) {
			return new \WP_Error( 'sseo_invalid', __( 'Create the agent roles first.', 'semantic-seo-engine-vi-en' ) );
		}
		$user->set_role( $role );
		update_user_meta( $user->ID, 'sseo_agent_account', 1 );
		AuditLog::record( 'agents.role_assigned', 'user', (int) $user->ID, 'ok', array( 'role' => $role ) );
		return __( 'Agent role assigned. Create an Application Password on that user’s profile and give it only to the agent.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Streams a matrix export.
	 *
	 * @return void|\WP_Error
	 */
	private static function do_export() {
		$g = Guard::check( Capabilities::READ, 'read', 'export' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$filters = array();
		foreach ( array( 'locale', 'subtype', 'status', 'intent', 'role', 'search' ) as $k ) {
			$filters[ $k ] = self::p( $k );
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$filters['status'] = 'publish';
		}
		$format = 'xlsx' === self::p( 'format' ) ? 'xlsx' : 'csv';
		AuditLog::record( 'matrix.exported', 'matrix', 0, 'ok', array( 'format' => $format ) );
		$name = 'seo-matrix-' . gmdate( 'Ymd-His' ) . '.' . $format;
		if ( 'xlsx' === $format ) {
			$tmp = wp_tempnam( 'sseo-xlsx' );
			$res = MatrixExporter::xlsx( $filters, $tmp );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			nocache_headers();
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $name . '"' );
			header( 'Content-Length: ' . filesize( $tmp ) );
			readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			wp_delete_file( $tmp );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		MatrixExporter::csv( $out, $filters );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Validates an uploaded CSV file.
	 *
	 * @param string $field Field name.
	 * @return string|\WP_Error Temp path.
	 */
	private static function uploaded_csv( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- validated below; never moved into uploads.
		$f = isset( $_FILES[ $field ] ) ? $_FILES[ $field ] : null;
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new \WP_Error( 'sseo_upload', __( 'Upload failed.', 'semantic-seo-engine-vi-en' ) );
		}
		$check = wp_check_filetype( sanitize_file_name( $f['name'] ), array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $check['ext'] ) {
			return new \WP_Error( 'sseo_upload', __( 'Only .csv files are accepted.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( (int) $f['size'] > CsvReader::MAX_BYTES ) {
			return new \WP_Error( 'sseo_upload', __( 'The file is larger than 5 MB.', 'semantic-seo-engine-vi-en' ) );
		}
		return (string) $f['tmp_name'];
	}

	/**
	 * Import preview (nothing is written).
	 *
	 * @return string|\WP_Error
	 */
	private static function do_import_preview() {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'import_preview' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'import_preview' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$path = self::uploaded_csv( 'file' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$csv = CsvReader::read( $path );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		$map = MatrixImporter::auto_map( $csv['header'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( wp_unslash( $_POST['map'] ) as $field => $col ) {
				if ( '' !== (string) $col ) {
					$map[ sanitize_key( $field ) ] = (int) $col;
				}
			}
		}
		$overwrite = '1' === self::p( 'overwrite' );
		$preview   = MatrixImporter::preview( $csv, $map, $overwrite );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$preview['header'] = $csv['header'];
		$preview['map']    = $map;
		set_transient( 'sseo_import_view_' . get_current_user_id(), $preview, 30 * MINUTE_IN_SECONDS );
		return __( 'Preview ready below. Nothing has been written yet.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Applies a previewed import.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_import_apply() {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'import_apply' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'import_apply' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$res = MatrixImporter::apply( self::p( 'token' ) );
		delete_transient( 'sseo_import_view_' . get_current_user_id() );
		/* translators: %d: number of rows */
		return is_wp_error( $res ) ? $res : sprintf( __( 'Import applied to %d documents (plugin fields only).', 'semantic-seo-engine-vi-en' ), $res['applied'] );
	}

	/**
	 * Imports genuine Search Console data.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_gsc_import() {
		$g = Guard::check( Capabilities::MANAGE, 'write', 'gsc_import' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'gsc_import' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$path = self::uploaded_csv( 'file' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$dry = '1' !== self::p( 'commit' );
		$res = GscRepository::import( $path, self::p( 'period_start' ), self::p( 'period_end' ), self::p( 'source' ), $dry );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		AuditLog::record( 'gsc.' . ( $dry ? 'dry_run' : 'imported' ), 'gsc', 0, 'ok', $res );
		/* translators: 1: valid rows, 2: total rows, 3: matched rows */
		$msg = sprintf( __( '%1$d of %2$d rows valid, %3$d matched to inventoried URLs.', 'semantic-seo-engine-vi-en' ), $res['rows_valid'], $res['rows_total'], $res['matched_to_inventory'] );
		return $dry ? __( 'Dry run only:', 'semantic-seo-engine-vi-en' ) . ' ' . $msg : __( 'Imported:', 'semantic-seo-engine-vi-en' ) . ' ' . $msg;
	}

	/**
	 * Deletes a GSC import.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_gsc_delete() {
		$g = Guard::check( Capabilities::MANAGE, 'write', 'gsc_delete' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		GscRepository::delete_import( self::p( 'import_id' ) );
		AuditLog::record( 'gsc.deleted', 'gsc', 0, 'ok', array( 'import_id' => self::p( 'import_id' ) ) );
		return __( 'Import deleted.', 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Setup wizard steps.
	 *
	 * @return string|\WP_Error
	 */
	private static function do_wizard() {
		$step = self::p( 'step' );
		if ( 'scope' === $step ) {
			$res = self::do_save_settings();
			return is_wp_error( $res ) ? $res : __( 'Scope saved.', 'semantic-seo-engine-vi-en' );
		}
		if ( 'pilot' === $step ) {
			$n   = max( 5, min( 50, (int) self::p( 'pilot_size' ) ) );
			$ids = get_posts(
				array(
					'post_type'      => Settings::get( 'post_types', array( 'post', 'page' ) ),
					'post_status'    => 'publish',
					'posts_per_page' => $n,
					'fields'         => 'ids',
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			if ( array() === $ids ) {
				return new \WP_Error( 'sseo_empty', __( 'No published posts in scope.', 'semantic-seo-engine-vi-en' ) );
			}
			$res = Api::start_analysis(
				array(
					'type'         => 'inventory',
					'post_ids'     => array_map( 'intval', $ids ),
					'then_analyze' => true,
					'pilot'        => true,
				)
			);
			/* translators: %d: number of posts */
			return is_wp_error( $res ) ? $res : sprintf( __( 'Read-only pilot started on the %d most recently updated posts.', 'semantic-seo-engine-vi-en' ), count( $ids ) );
		}
		if ( 'finish' === $step ) {
			$g = Guard::check( Capabilities::MANAGE, 'write', 'wizard' );
			if ( is_wp_error( $g ) ) {
				return $g;
			}
			Settings::update( array( 'wizard_completed' => true ) );
			return __( 'Setup finished. Read-only mode stays on; nothing changes on the site without your approval.', 'semantic-seo-engine-vi-en' );
		}
		return new \WP_Error( 'sseo_invalid', __( 'Unknown step.', 'semantic-seo-engine-vi-en' ) );
	}
}
