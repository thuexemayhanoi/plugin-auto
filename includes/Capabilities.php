<?php
/**
 * Custom capabilities, agent roles and the capability filter that keeps agents below humans.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO;

use SemanticSEO\Security\AuthContext;

defined( 'ABSPATH' ) || exit;

/**
 * Capability model.
 *
 * READ    sseo_read     view reports and inventory.
 * ANALYZE sseo_analyze  start/cancel bounded jobs, create previews.
 * EMBED   sseo_embed    claim/complete embedding queue items (browser or private worker).
 * REVIEW  sseo_review   approve proposals (humans in WP Admin only).
 * APPLY   sseo_apply    execute approved changes and rollbacks.
 * MANAGE  sseo_manage   settings, agent connections, audit log.
 */
final class Capabilities {

	const READ    = 'sseo_read';
	const ANALYZE = 'sseo_analyze';
	const EMBED   = 'sseo_embed';
	const REVIEW  = 'sseo_review';
	const APPLY   = 'sseo_apply';
	const MANAGE  = 'sseo_manage';

	const ALL = array( self::READ, self::ANALYZE, self::EMBED, self::REVIEW, self::APPLY, self::MANAGE );

	/**
	 * Agent roles (created on demand from the Agent Connections page, never on activation).
	 */
	const AGENT_ROLES = array(
		'sseo_agent_reader'   => array( 'read', self::READ ),
		'sseo_agent_analyst'  => array( 'read', self::READ, self::ANALYZE ),
		'sseo_agent_operator' => array( 'read', self::READ, self::ANALYZE, self::APPLY ),
		'sseo_worker'         => array( 'read', self::READ, self::EMBED ),
	);

	/**
	 * Registers the capability filter.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_caps' ), 20, 4 );
	}

	/**
	 * Grants plugin caps to site administrators and strips privileged caps from agent contexts.
	 *
	 * @param array    $allcaps All caps of the user.
	 * @param string[] $caps    Required primitive caps.
	 * @param array    $args    Arguments.
	 * @param \WP_User $user    User.
	 * @return array
	 */
	public static function filter_user_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::ALL as $cap ) {
				if ( ! isset( $allcaps[ $cap ] ) ) {
					$allcaps[ $cap ] = true;
				}
			}
		}
		$user_id  = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		$is_agent = AuthContext::is_agent_user( $user_id, $user );
		$app_pass = AuthContext::is_app_password_request() && get_current_user_id() === $user_id;
		if ( $is_agent || $app_pass ) {
			// Agents (and any Application Password session) can never review or manage.
			$allcaps[ self::REVIEW ] = false;
			$allcaps[ self::MANAGE ] = false;
			if ( ! Settings::get( 'agents_may_apply', false ) ) {
				$allcaps[ self::APPLY ] = false;
			}
		}
		if ( $is_agent ) {
			// Agent accounts must never be able to escalate through core capabilities either.
			foreach ( array( 'promote_users', 'edit_users', 'create_users', 'manage_options', 'activate_plugins', 'edit_plugins', 'install_plugins', 'unfiltered_html' ) as $core_cap ) {
				$allcaps[ $core_cap ] = false;
			}
		}
		return $allcaps;
	}

	/**
	 * Creates the agent roles (explicit administrator action).
	 *
	 * @return string[] Created or updated role slugs.
	 */
	public static function ensure_agent_roles() {
		$done = array();
		foreach ( self::AGENT_ROLES as $role => $caps ) {
			$map = array_fill_keys( $caps, true );
			$obj = get_role( $role );
			if ( null === $obj ) {
				add_role( $role, self::role_label( $role ), $map );
			} else {
				foreach ( $map as $cap => $grant ) {
					$obj->add_cap( $cap, $grant );
				}
			}
			$done[] = $role;
		}
		return $done;
	}

	/**
	 * Removes the agent roles (uninstall with delete policy).
	 *
	 * @return void
	 */
	public static function remove_agent_roles() {
		foreach ( array_keys( self::AGENT_ROLES ) as $role ) {
			remove_role( $role );
		}
	}

	/**
	 * Human label of an agent role.
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function role_label( $role ) {
		$labels = array(
			'sseo_agent_reader'   => __( 'SEO Agent (read-only)', 'semantic-seo-engine-vi-en' ),
			'sseo_agent_analyst'  => __( 'SEO Agent (read + analyze)', 'semantic-seo-engine-vi-en' ),
			'sseo_agent_operator' => __( 'SEO Agent (may execute human-approved changes)', 'semantic-seo-engine-vi-en' ),
			'sseo_worker'         => __( 'SEO Embedding Worker', 'semantic-seo-engine-vi-en' ),
		);
		return isset( $labels[ $role ] ) ? $labels[ $role ] : $role;
	}
}
