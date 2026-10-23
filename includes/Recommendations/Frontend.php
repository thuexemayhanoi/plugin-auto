<?php
/**
 * M6 frontend output: shortcode, dynamic block and template. Disabled until enabled in Settings.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Recommendations;

use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is injected into post bodies or footers automatically; output only appears where an
 * editor placed the shortcode/block or a theme calls sseo_get_related_posts(). No model, admin
 * data or inference ever reaches the frontend.
 */
final class Frontend {

	const SHORTCODE = 'sseo_related';
	const BLOCK     = 'semantic-seo/related-content';

	/**
	 * Whether the inline stylesheet was already printed.
	 *
	 * @var bool
	 */
	private static $styled = false;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the shortcode and the block.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		if ( function_exists( 'register_block_type' ) ) {
			wp_register_script(
				'sseo-related-block',
				SSEO_URL . 'assets/js/related-block.js',
				array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
				SSEO_VERSION,
				true
			);
			register_block_type(
				SSEO_DIR . 'blocks/related-content',
				array(
					'render_callback' => array( __CLASS__, 'render_block' ),
					'editor_script'   => 'sseo-related-block',
				)
			);
		}
	}

	/**
	 * Shortcode handler: [sseo_related limit="5" title="…"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'   => Settings::get( 'related_limit', 5 ),
				'title'   => '',
				'post_id' => 0,
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		return self::render( (int) $atts['post_id'], (int) $atts['limit'], (string) $atts['title'] );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		return self::render( 0, isset( $attributes['limit'] ) ? (int) $attributes['limit'] : (int) Settings::get( 'related_limit', 5 ), isset( $attributes['title'] ) ? (string) $attributes['title'] : '' );
	}

	/**
	 * Renders the list.
	 *
	 * @param int    $post_id Post ID (0 = current post).
	 * @param int    $limit   Max items.
	 * @param string $title   Heading.
	 * @return string
	 */
	public static function render( $post_id, $limit, $title ) {
		if ( ! Settings::get( 'related_enabled', false ) ) {
			return '';
		}
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return '';
		}
		$items = function_exists( 'sseo_get_related_posts' ) ? sseo_get_related_posts( $post_id, array( 'limit' => $limit ) ) : array();
		if ( array() === $items ) {
			return '';
		}
		$title    = '' !== $title ? $title : (string) Settings::get( 'related_title', '' );
		$title    = '' !== $title ? $title : __( 'Related articles', 'semantic-seo-engine-vi-en' );
		$template = locate_template( array( 'semantic-seo/related-content.php' ) );
		if ( '' === $template ) {
			$template = SSEO_DIR . 'templates/related-content.php';
		}
		ob_start();
		$sseo_related_items = $items;
		$sseo_related_title = $title;
		include $template;
		$html = (string) ob_get_clean();
		if ( Settings::get( 'related_css', true ) && ! self::$styled ) {
			self::$styled = true;
			$html         = '<style id="sseo-related-css">.sseo-related{margin:2em 0}.sseo-related__list{margin:.5em 0 0;padding-left:1.2em}.sseo-related__item{margin:.25em 0}</style>' . $html;
		}
		return $html;
	}
}
