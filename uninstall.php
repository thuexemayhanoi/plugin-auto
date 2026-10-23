<?php
/**
 * Uninstall: preserves all data unless the administrator explicitly chose the "delete" policy.
 *
 * @package SemanticSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$sseo_settings = get_option( 'sseo_settings', array() );
$sseo_policy   = is_array( $sseo_settings ) && isset( $sseo_settings['uninstall_policy'] ) ? $sseo_settings['uninstall_policy'] : 'preserve';

wp_clear_scheduled_hook( 'sseo_run_jobs' );
wp_clear_scheduled_hook( 'sseo_daily_maintenance' );

if ( 'delete' !== $sseo_policy ) {
	// Default: keep tables, settings and the change log so a reinstall can continue and rollback
	// snapshots remain available.
	return;
}

require_once __DIR__ . '/includes/Autoloader.php';
\SemanticSEO\Autoloader::register();
\SemanticSEO\Database\Migrator::drop_everything();
\SemanticSEO\Capabilities::remove_agent_roles();
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'sseo_mutex_' ) . '%', $wpdb->esc_like( '_transient_sseo_' ) . '%', $wpdb->esc_like( '_transient_timeout_sseo_' ) . '%' ) );
delete_metadata( 'user', 0, 'sseo_agent_account', '', true );
