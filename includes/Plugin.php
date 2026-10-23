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
		$domain  = 'semantic-seo-engine-vi-en';
		self::$mo = null;
		remove_filter( 'gettext_' . $domain, array( __CLASS__, 'translate_text' ), 10 );
		remove_filter( 'gettext_with_context_' . $domain, array( __CLASS__, 'translate_with_context' ), 10 );
		remove_filter( 'ngettext_' . $domain, array( __CLASS__, 'translate_plural' ), 10 );
		$current = determine_locale();
		$locale  = apply_filters( 'plugin_locale', $current, $domain ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		// Site-installed translations (wp-content/languages/plugins) win over the bundled ones.
		$site = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
		$file = is_readable( $site ) ? $site : SSEO_DIR . 'languages/' . $domain . '-' . $locale . '.mo';
		if ( ! is_readable( $file ) ) {
			return;
		}
		if ( $locale === $current ) {
			load_textdomain( $domain, $file, $locale );
			return;
		}
		// The plugin UI language differs from the site/user locale (e.g. Vietnamese plugin screens on
		// an English site). Since WordPress 6.5, load_textdomain() with another locale switches the
		// global translation locale, which would affect other plugins; translate only this domain.
		$mo = new \MO();
		if ( ! $mo->import_from_file( $file ) ) {
			return;
		}
		self::$mo = $mo;
		add_filter( 'gettext_' . $domain, array( __CLASS__, 'translate_text' ), 10, 2 );
		add_filter( 'gettext_with_context_' . $domain, array( __CLASS__, 'translate_with_context' ), 10, 3 );
		add_filter( 'ngettext_' . $domain, array( __CLASS__, 'translate_plural' ), 10, 4 );
	}

	/**
	 * Domain-only translation table used when the plugin UI language differs from the site locale.
	 *
	 * @var \MO|null
	 */
	private static $mo = null;

	/**
	 * Gettext filter for this domain.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original.
	 * @return string
	 */
	public static function translate_text( $translation, $text ) {
		return null === self::$mo ? $translation : self::$mo->translate( $text );
	}

	/**
	 * Gettext-with-context filter for this domain.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original.
	 * @param string $context     Context.
	 * @return string
	 */
	public static function translate_with_context( $translation, $text, $context ) {
		return null === self::$mo ? $translation : self::$mo->translate( $text, $context );
	}

	/**
	 * Plural filter for this domain.
	 *
	 * @param string $translation Current translation.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @return string
	 */
	public static function translate_plural( $translation, $single, $plural, $number ) {
		return null === self::$mo ? $translation : self::$mo->translate_plural( $single, $plural, $number );
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
