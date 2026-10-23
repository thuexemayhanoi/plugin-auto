<?php
/**
 * Plugin Name:       Semantic SEO Engine VI–EN
 * Plugin URI:        https://github.com/thuexemayhanoi/plugin-auto
 * Description:       Hybrid lexical + optional Qwen3 embedding SEO operations for bilingual (Vietnamese/English) WordPress sites: inventory, internal links, cannibalization risk, topic clusters, content QA and related content, with human approval, rollback and a permissioned agent API. Read-only by default.
 * Version:           1.0.0-beta.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            thuexemayhanoi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       semantic-seo-engine-vi-en
 * Domain Path:       /languages
 *
 * @package SemanticSEO
 */

defined( 'ABSPATH' ) || exit;

define( 'SSEO_VERSION', '1.0.0-beta.2' );
define( 'SSEO_FILE', __FILE__ );
define( 'SSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSEO_URL', plugin_dir_url( __FILE__ ) );
define( 'SSEO_MIN_PHP', '7.4' );
define( 'SSEO_MIN_WP', '6.5' );

/**
 * Returns a list of unmet requirements (empty when the environment is supported).
 *
 * @return string[]
 */
function sseo_unmet_requirements() {
	$problems = array();
	if ( version_compare( PHP_VERSION, SSEO_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		$problems[] = sprintf( __( 'PHP %1$s or newer is required (current: %2$s).', 'semantic-seo-engine-vi-en' ), SSEO_MIN_PHP, PHP_VERSION );
	}
	global $wp_version;
	if ( version_compare( $wp_version, SSEO_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		$problems[] = sprintf( __( 'WordPress %1$s or newer is required (current: %2$s).', 'semantic-seo-engine-vi-en' ), SSEO_MIN_WP, $wp_version );
	}
	if ( ! function_exists( 'mb_strtolower' ) ) {
		$problems[] = __( 'The PHP mbstring extension is required.', 'semantic-seo-engine-vi-en' );
	}
	return $problems;
}

if ( array() !== sseo_unmet_requirements() ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>Semantic SEO Engine VI–EN:</strong> ';
			echo esc_html( implode( ' ', sseo_unmet_requirements() ) );
			echo ' ' . esc_html__( 'The plugin is inactive until these requirements are met.', 'semantic-seo-engine-vi-en' ) . '</p></div>';
		}
	);
	return;
}

require_once SSEO_DIR . 'includes/Autoloader.php';
\SemanticSEO\Autoloader::register();
require_once SSEO_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( '\SemanticSEO\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SemanticSEO\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\SemanticSEO\Plugin', 'instance' ), 5 );
