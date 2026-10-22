<?php
/**
 * Bootstrap: wires hooks for every transport; no work happens on activation beyond schema setup.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO;

use SemanticSEO\Abilities\AbilitiesRegistrar;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Database\Migrator;
use SemanticSEO\Inventory\Invalidation;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Recommendations\Frontend;
use SemanticSEO\REST\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	const MAINTENANCE_HOOK = 'sseo_daily_maintenance';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the instance, booting once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	private function boot() {
		if ( Migrator::needs_upgrade() ) {
			Migrator::maybe_upgrade();
		}
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_locale' ), 10, 2 );
		Capabilities::register_hooks();
		JobRunner::register_hooks();
		Invalidation::register_hooks();
		Frontend::register_hooks();
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );
		AbilitiesRegistrar::register_hooks();
		add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'maintenance' ) );
		if ( is_admin() ) {
			Admin\Menu::register_hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Command::register();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'semantic-seo-engine-vi-en', false, dirname( plugin_basename( SSEO_FILE ) ) . '/languages' );
	}

	/**
	 * Applies the "Admin UI language" setting (Vietnamese first) to this plugin's text domain only.
	 *
	 * @param string $locale Locale.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function filter_locale( $locale, $domain ) {
		if ( 'semantic-seo-engine-vi-en' !== $domain || ! is_admin() ) {
			return $locale;
		}
		$ui = Settings::get( 'ui_language', 'vi' );
		if ( 'vi' === $ui ) {
			return 'vi';
		}
		if ( 'en' === $ui ) {
			return 'en_US';
		}
		return $locale;
	}

	/**
	 * Activation: create/upgrade the plugin's own tables and default settings. Read-only with
	 * respect to WordPress content, SEO data and URLs; no scan is started.
	 *
	 * @return void
	 */
	public static function activate() {
		Migrator::migrate();
		Settings::install_defaults();
	}

	/**
	 * Deactivation keeps all data; only scheduled events are removed.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( JobRunner::HOOK );
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
	}

	/**
	 * Daily maintenance (audit retention). Scheduled after the first job, never on activation.
	 *
	 * @return void
	 */
	public static function maintenance() {
		AuditLog::prune();
	}

	/**
	 * Ensures maintenance is scheduled (called when the plugin starts doing work).
	 *
	 * @return void
	 */
	public static function ensure_maintenance() {
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK );
		}
	}
}
