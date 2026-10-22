<?php
/**
 * Permission + rate-limit gate used by every service method.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Services;

use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes checks so Admin, REST, Abilities and WP-CLI behave identically.
 */
final class Guard {

	/**
	 * Checks a capability and consumes a rate-limit unit.
	 *
	 * @param string $cap    Capability.
	 * @param string $bucket Rate bucket (read|analyze|write|embed) or '' to skip.
	 * @param string $action Action name for audit on denial.
	 * @return true|\WP_Error
	 */
	public static function check( $cap, $bucket = 'read', $action = '' ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'sseo_unauthenticated', __( 'Authentication required.', 'semantic-seo-engine-vi-en' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( $cap ) ) {
			AuditLog::record(
				'denied.' . ( '' !== $action ? $action : $cap ),
				'',
				0,
				'denied',
				array(
					'capability' => $cap,
					'agent'      => AuthContext::is_agent_context(),
				)
			);
			return new \WP_Error( 'sseo_forbidden', __( 'You do not have permission for this action.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		if ( '' !== $bucket && 'cli' !== AuthContext::via() ) {
			$wait = RateLimiter::hit( $bucket, get_current_user_id() );
			if ( $wait > 0 ) {
				return new \WP_Error(
					'sseo_rate_limited',
					__( 'Too many requests; slow down.', 'semantic-seo-engine-vi-en' ),
					array(
						'status'      => 429,
						'retry_after' => $wait,
					)
				);
			}
		}
		return true;
	}

	/**
	 * Requires an interactive human WP Admin session (approvals, settings, imports).
	 *
	 * @param string $action Action for audit.
	 * @return true|\WP_Error
	 */
	public static function human_only( $action ) {
		if ( ! AuthContext::is_interactive_human() ) {
			AuditLog::record( 'denied.' . $action, '', 0, 'denied', array( 'reason' => 'requires interactive human WP Admin session' ) );
			return new \WP_Error( 'sseo_human_required', __( 'This action can only be performed by a human administrator in WP Admin (not via Application Passwords, agents or the API).', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
