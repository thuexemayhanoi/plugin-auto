<?php
/**
 * Determines who is calling and through which transport.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Security;

use SemanticSEO\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Request context used by the service layer for permission and audit decisions.
 */
final class AuthContext {

	/**
	 * Transport override set while an Ability callback runs.
	 *
	 * @var string|null
	 */
	private static $via_override = null;

	/**
	 * Marks the start/end of an Ability execution.
	 *
	 * @param string|null $via Transport name or null to reset.
	 * @return void
	 */
	public static function set_via( $via ) {
		self::$via_override = null === $via ? null : (string) $via;
	}

	/**
	 * Transport of the current request.
	 *
	 * @return string admin|rest_cookie|rest_app_password|ability|cli|cron|ajax|other
	 */
	public static function via() {
		if ( null !== self::$via_override ) {
			return self::$via_override;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( self::is_rest_request() ) {
			return self::is_app_password_request() ? 'rest_app_password' : 'rest_cookie';
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		return 'other';
	}

	/**
	 * Whether this is a REST request.
	 *
	 * @return bool
	 */
	public static function is_rest_request() {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Whether the current request authenticated with an Application Password.
	 *
	 * @return bool
	 */
	public static function is_app_password_request() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return true;
		}
		return (bool) did_action( 'application_password_did_authenticate' );
	}

	/**
	 * Whether a user is a dedicated agent identity.
	 *
	 * @param int           $user_id User ID.
	 * @param \WP_User|null $user    Optional user object.
	 * @return bool
	 */
	public static function is_agent_user( $user_id, $user = null ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! $user instanceof \WP_User ) {
			$user = get_userdata( $user_id );
		}
		if ( $user instanceof \WP_User ) {
			foreach ( (array) $user->roles as $role ) {
				if ( isset( Capabilities::AGENT_ROLES[ $role ] ) ) {
					return true;
				}
			}
		}
		return (bool) get_user_meta( $user_id, 'sseo_agent_account', true );
	}

	/**
	 * Whether the current call comes from an agent/automation context.
	 *
	 * @return bool
	 */
	public static function is_agent_context() {
		return self::is_app_password_request() || self::is_agent_user( get_current_user_id() );
	}

	/**
	 * Whether the current request is an interactive human session in WP Admin
	 * (cookie-authenticated, not an Application Password, not CLI/cron, not an agent account).
	 *
	 * @return bool
	 */
	public static function is_interactive_human() {
		$via = self::via();
		if ( ! in_array( $via, array( 'admin', 'ajax' ), true ) ) {
			return false;
		}
		if ( self::is_agent_context() ) {
			return false;
		}
		return is_user_logged_in();
	}
}
