<?php
/**
 * Page 10 — Agent Connections / Permissions.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Abilities\AbilitiesRegistrar;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Dedicated agent identities, Application Password guidance, ability exposure (REST/MCP opt-in).
 */
final class AgentsPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Agent Connections / Permissions', 'semantic-seo-engine-vi-en' ), __( 'Agents use the same rules as humans, with less power: they can read, analyze and propose, but never approve. Installing this plugin does not connect any AI service.', 'semantic-seo-engine-vi-en' ) );

		// Roles.
		echo '<section class="sseo-panel"><h2>' . esc_html__( '1. Dedicated agent identity', 'semantic-seo-engine-vi-en' ) . '</h2><ul>';
		foreach ( Capabilities::AGENT_ROLES as $role => $caps ) {
			echo '<li><strong>' . esc_html( Capabilities::role_label( $role ) ) . '</strong> <code>' . esc_html( $role ) . '</code> — ' . esc_html( implode( ', ', $caps ) ) . ( null === get_role( $role ) ? ' <em>(' . esc_html__( 'not created yet', 'semantic-seo-engine-vi-en' ) . ')</em>' : '' ) . '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'Agent accounts can never review/approve, manage settings, edit users or change roles — enforced by the capability filter for every transport. Any Application-Password session (even an administrator’s) is treated as an agent.', 'semantic-seo-engine-vi-en' ) . '</p>';
		Ui::out( Ui::action_button( 'agent_roles', array(), __( 'Create / update agent roles', 'semantic-seo-engine-vi-en' ) ) );

		$users   = get_users(
			array(
				'role__not_in' => array( 'administrator' ),
				'number'       => 200,
				'fields'       => array( 'ID', 'user_login', 'display_name' ),
			)
		);
		$options = array();
		foreach ( $users as $u ) {
			$options[ $u->ID ] = $u->user_login . ' (' . $u->display_name . ')';
		}
		if ( array() !== $options ) {
			Ui::form_open( 'assign_agent_role' );
			Ui::out( Ui::select( 'user_id', $options, '', __( 'User', 'semantic-seo-engine-vi-en' ) ) . ' ' );
			$roles = array( 'none' => __( '(remove agent role)', 'semantic-seo-engine-vi-en' ) );
			foreach ( array_keys( Capabilities::AGENT_ROLES ) as $r ) {
				$roles[ $r ] = Capabilities::role_label( $r );
			}
			Ui::out( Ui::select( 'role', $roles, 'sseo_agent_reader', __( 'Agent role', 'semantic-seo-engine-vi-en' ) ) );
			echo ' <button class="button">' . esc_html__( 'Assign', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		} else {
			echo '<p>' . esc_html__( 'Create a dedicated, non-administrator user for each agent first (Users → Add New).', 'semantic-seo-engine-vi-en' ) . '</p>';
		}
		$agents = get_users(
			array(
				'role__in' => array_keys( Capabilities::AGENT_ROLES ),
				'number'   => 100,
			)
		);
		$rows   = array();
		foreach ( $agents as $a ) {
			$rows[] = array(
				'user' => esc_html( $a->user_login ),
				'role' => esc_html( implode( ', ', array_map( array( Capabilities::class, 'role_label' ), (array) $a->roles ) ) ),
				'pw'   => '<a href="' . esc_url( get_edit_user_link( $a->ID ) . '#application-passwords-section' ) . '">' . esc_html__( 'Application Passwords', 'semantic-seo-engine-vi-en' ) . '</a>',
			);
		}
		Ui::table(
			__( 'Agent accounts', 'semantic-seo-engine-vi-en' ),
			array(
				'user' => __( 'User', 'semantic-seo-engine-vi-en' ),
				'role' => __( 'Role', 'semantic-seo-engine-vi-en' ),
				'pw'   => __( 'Credentials', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No agent accounts yet.', 'semantic-seo-engine-vi-en' )
		);
		echo '</section>';

		// Credentials guidance.
		$ap = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
		echo '<section class="sseo-panel"><h2>' . esc_html__( '2. Credentials (Application Passwords)', 'semantic-seo-engine-vi-en' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'Open the agent user’s profile → Application Passwords, name it after the agent, create it and copy it once. WordPress stores only a hash; it is never shown here.', 'semantic-seo-engine-vi-en' ) . '</li>';
		echo '<li>' . esc_html__( 'Use HTTPS. Give the password only to that agent; revoke it on the same screen at any time (each use is logged there with last-used IP and date).', 'semantic-seo-engine-vi-en' ) . '</li>';
		echo '<li>' . esc_html__( 'Test the connection (replace the placeholders):', 'semantic-seo-engine-vi-en' ) . '<br><code class="sseo-code">curl -u AGENT_USER:APP_PASSWORD ' . esc_html( rest_url( 'semantic-seo/v1/status' ) ) . '</code></li></ol>';
		if ( ! $ap ) {
			Ui::notice( 'warning', __( 'Application Passwords are unavailable on this site (usually because it is not served over HTTPS or they were disabled).', 'semantic-seo-engine-vi-en' ) );
		}
		echo '</section>';

		// Exposure.
		$abilities = Environment::abilities_available();
		$mcp       = Environment::mcp_adapter_available();
		echo '<section class="sseo-panel"><h2>' . esc_html__( '3. Abilities & MCP exposure (opt-in)', 'semantic-seo-engine-vi-en' ) . '</h2>';
		echo '<p>' . ( $abilities ? esc_html__( 'WordPress Abilities API: available.', 'semantic-seo-engine-vi-en' ) : esc_html__( 'WordPress Abilities API: unavailable (needs WordPress 6.9+). Agents can still use the REST API.', 'semantic-seo-engine-vi-en' ) ) . ' ' . ( $mcp ? esc_html( sprintf( /* translators: %s: version */ __( 'Official MCP Adapter detected (version %s).', 'semantic-seo-engine-vi-en' ), Environment::mcp_adapter_version() ) ) : esc_html__( 'Official MCP Adapter not installed: no MCP client can connect.', 'semantic-seo-engine-vi-en' ) ) . '</p>';
		Ui::form_open( 'save_settings' );
		echo '<input type="hidden" name="form" value="agents">';
		$rest_on = (array) Settings::get( 'abilities_rest', array() );
		$mcp_on  = (array) Settings::get( 'mcp_abilities', array() );
		$rows    = array();
		foreach ( AbilitiesRegistrar::definitions() as $slug => $def ) {
			$name   = 'semantic-seo/' . $slug;
			$ro     = in_array( $name, Settings::READ_ONLY_ABILITIES, true );
			$rows[] = array(
				'ability' => '<code>' . esc_html( $name ) . '</code><br><small>' . esc_html( $def['label'] ) . '</small>',
				'access'  => esc_html( $def['access'] ),
				'rest'    => '<input type="checkbox" name="sseo[abilities_rest][]" value="' . esc_attr( $name ) . '"' . checked( in_array( $name, $rest_on, true ), true, false ) . ' aria-label="' . esc_attr( $name . ' REST' ) . '">',
				'mcp'     => $ro ? '<input type="checkbox" name="sseo[mcp_abilities][]" value="' . esc_attr( $name ) . '"' . checked( in_array( $name, $mcp_on, true ), true, false ) . ' aria-label="' . esc_attr( $name . ' MCP' ) . '">' : '<small>' . esc_html__( 'never (writes)', 'semantic-seo-engine-vi-en' ) . '</small>',
			);
		}
		Ui::table(
			__( 'Abilities', 'semantic-seo-engine-vi-en' ),
			array(
				'ability' => __( 'Ability', 'semantic-seo-engine-vi-en' ),
				'access'  => __( 'Access level', 'semantic-seo-engine-vi-en' ),
				'rest'    => __( 'Expose via Abilities REST', 'semantic-seo-engine-vi-en' ),
				'mcp'     => __( 'Expose via MCP', 'semantic-seo-engine-vi-en' ),
			),
			$rows
		);
		echo '<p><label><input type="checkbox" name="sseo[mcp_enabled]" value="1"' . checked( (bool) Settings::get( 'mcp_enabled', false ), true, false ) . '> ' . esc_html__( 'Enable MCP exposure for the selected read-only abilities', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="sseo[agents_may_apply]" value="1"' . checked( (bool) Settings::get( 'agents_may_apply', false ), true, false ) . '> ' . esc_html__( 'Allow agent accounts with the operator role to execute changes that a human already approved (still single-use, hash-bound and logged)', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		echo '<button class="button button-primary">' . esc_html__( 'Save exposure settings', 'semantic-seo-engine-vi-en' ) . '</button></form></section>';

		// Activity.
		$log  = AuditLog::query( array( 'limit' => 20 ) );
		$rows = array();
		foreach ( $log['items'] as $r ) {
			if ( ! in_array( $r['via'], array( 'rest_app_password', 'ability' ), true ) && 0 !== strpos( (string) $r['via'], 'agent' ) ) {
				continue;
			}
			$user   = get_userdata( (int) $r['user_id'] );
			$rows[] = array(
				'when'   => esc_html( $r['created_at'] ),
				'who'    => esc_html( $user ? $user->user_login : '#' . $r['user_id'] ),
				'action' => esc_html( $r['action'] ),
				'result' => Ui::badge( $r['result'], 'status' ),
			);
		}
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Recent agent activity', 'semantic-seo-engine-vi-en' ) . '</h2>';
		Ui::table(
			__( 'Recent agent activity', 'semantic-seo-engine-vi-en' ),
			array(
				'when'   => __( 'When (UTC)', 'semantic-seo-engine-vi-en' ),
				'who'    => __( 'Agent', 'semantic-seo-engine-vi-en' ),
				'action' => __( 'Action', 'semantic-seo-engine-vi-en' ),
				'result' => __( 'Result', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No agent activity recorded.', 'semantic-seo-engine-vi-en' )
		);
		echo '</section>';
		Ui::footer();
	}
}
