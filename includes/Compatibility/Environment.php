<?php
/**
 * Environment and compatibility detection (evidence only — unknowns stay unknown).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Compatibility;

use SemanticSEO\Database\Migrator;
use SemanticSEO\Database\Schema;
use SemanticSEO\Language\LanguageResolver;
use SemanticSEO\Language\TextNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the diagnostics report shown in Setup Wizard, Settings & Diagnostics and get-site-status.
 */
final class Environment {

	/**
	 * Whether the core Abilities API is available (WP 6.9+).
	 *
	 * @return bool
	 */
	public static function abilities_available() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether the official MCP Adapter plugin is loaded.
	 *
	 * @return bool
	 */
	public static function mcp_adapter_available() {
		return class_exists( '\WP\MCP\Plugin' ) || class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/**
	 * Version of the MCP adapter, if detectable.
	 *
	 * @return string
	 */
	public static function mcp_adapter_version() {
		if ( ! self::mcp_adapter_available() ) {
			return '';
		}
		if ( defined( 'WP_MCP_VERSION' ) ) {
			return (string) WP_MCP_VERSION;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( 'mcp-adapter.php' === basename( $file ) ) {
				return (string) $data['Version'];
			}
		}
		return 'unknown';
	}

	/**
	 * Active plugins that matter for compatibility, detected by constants/classes/functions only.
	 *
	 * @return array<string,string[]>
	 */
	public static function detected_plugins() {
		$cache = array();
		foreach (
			array(
				'WP Rocket'          => defined( 'WP_ROCKET_VERSION' ),
				'W3 Total Cache'     => defined( 'W3TC' ),
				'LiteSpeed Cache'    => defined( 'LSCWP_V' ),
				'WP Super Cache'     => function_exists( 'wp_cache_phase2' ),
				'WP Fastest Cache'   => class_exists( 'WpFastestCache' ),
				'Autoptimize'        => defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
				'Cloudflare'         => defined( 'CLOUDFLARE_PLUGIN_DIR' ),
				'Redis Object Cache' => defined( 'WP_REDIS_VERSION' ),
			) as $name => $active
		) {
			if ( $active ) {
				$cache[] = $name;
			}
		}
		$related = array();
		foreach (
			array(
				'Jetpack Related Posts'   => class_exists( 'Jetpack_RelatedPosts' ),
				'YARPP'                   => defined( 'YARPP_VERSION' ),
				'Contextual Related Posts' => defined( 'CRP_PLUGIN_VERSION' ),
			) as $name => $active
		) {
			if ( $active ) {
				$related[] = $name;
			}
		}
		$cron = array();
		if ( class_exists( 'ActionScheduler' ) ) {
			$cron[] = 'Action Scheduler';
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$cron[] = 'DISABLE_WP_CRON=true (jobs advance from admin pages or WP-CLI)';
		}
		return array(
			'seo'      => SeoAdapter::detected(),
			'language' => array( LanguageResolver::framework() ),
			'cache'    => $cache,
			'related'  => $related,
			'cron'     => $cron,
		);
	}

	/**
	 * Full diagnostics report.
	 *
	 * @return array
	 */
	public static function report() {
		global $wp_version, $wpdb;
		$detected = self::detected_plugins();
		return array(
			'plugin_version'      => SSEO_VERSION,
			'wordpress'           => $wp_version,
			'php'                 => PHP_VERSION,
			'database'            => $wpdb->db_server_info(),
			'db_schema'           => array(
				'installed' => Migrator::installed_version(),
				'expected'  => Schema::VERSION,
				'missing'   => Migrator::missing_tables(),
			),
			'intl'                => TextNormalizer::has_intl(),
			'mbstring'            => function_exists( 'mb_strtolower' ),
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'wp_cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'abilities_api'       => self::abilities_available(),
			'mcp_adapter'         => self::mcp_adapter_available(),
			'mcp_adapter_version' => self::mcp_adapter_version(),
			'application_passwords' => function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available(),
			'https'               => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'seo_plugins'         => $detected['seo'],
			'seo_primary'         => SeoAdapter::primary(),
			'language_framework'  => LanguageResolver::framework(),
			'translation_mapping' => LanguageResolver::has_translation_mapping(),
			'languages'           => LanguageResolver::languages(),
			'cache_plugins'       => $detected['cache'],
			'related_plugins'     => $detected['related'],
			'cron_tools'          => $detected['cron'],
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'blog_public'         => (bool) get_option( 'blog_public', 1 ),
			'core_sitemaps'       => function_exists( 'wp_sitemaps_get_server' ) && (bool) apply_filters( 'wp_sitemaps_enabled', (bool) get_option( 'blog_public', 1 ) ),
		);
	}
}
