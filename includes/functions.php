<?php
/**
 * Public PHP API for themes and other plugins.
 *
 * @package SemanticSEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'sseo_get_related_posts' ) ) {
	/**
	 * Precomputed related posts for a post (same locale, published, indexable). Returns an empty
	 * array when M6 output is disabled, the post was not analyzed, or nothing qualifies.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    { @type int $limit Max items (1–10). @type bool $ignore_setting Return data even when frontend output is disabled. }
	 * @return array<int,array{post_id:int,title:string,url:string,score:float}>
	 */
	function sseo_get_related_posts( $post_id, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'          => (int) \SemanticSEO\Settings::get( 'related_limit', 5 ),
				'ignore_setting' => false,
			)
		);
		if ( ! $args['ignore_setting'] && ! \SemanticSEO\Settings::get( 'related_enabled', false ) ) {
			return array();
		}
		$items = \SemanticSEO\Recommendations\RelatedContent::for_post( (int) $post_id, (int) $args['limit'] );
		/**
		 * Filters the related posts list before output.
		 *
		 * @param array $items   Items.
		 * @param int   $post_id Post ID.
		 * @param array $args    Arguments.
		 */
		return (array) apply_filters( 'sseo_related_posts', $items, (int) $post_id, $args );
	}
}
