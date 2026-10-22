<?php
/**
 * Page 8 — Related Content Output (M6): settings, usage and preview.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Output is disabled until enabled here; nothing is injected automatically.
 */
final class RelatedPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Related Content Output', 'semantic-seo-engine-vi-en' ), __( 'Precomputed, same-language related articles. Rendered only where you place the shortcode or block (or call the PHP function) and only after you enable output.', 'semantic-seo-engine-vi-en' ) );
		$enabled = (bool) Settings::get( 'related_enabled', false );
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Status', 'semantic-seo-engine-vi-en' ) . '</h2><p>' . ( $enabled ? Ui::badge( 'active', 'status' ) . ' ' . esc_html__( 'Frontend output is enabled.', 'semantic-seo-engine-vi-en' ) : Ui::badge( 'paused', 'status' ) . ' ' . esc_html__( 'Frontend output is disabled (default). Shortcodes and blocks render nothing.', 'semantic-seo-engine-vi-en' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( current_user_can( Capabilities::MANAGE ) ) {
			Ui::form_open( 'save_settings' );
			echo '<input type="hidden" name="form" value="related">';
			echo '<p><label><input type="checkbox" name="sseo[related_enabled]" value="1"' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Enable related content output', 'semantic-seo-engine-vi-en' ) . '</label></p>';
			echo '<p><label for="sseo-rl">' . esc_html__( 'Number of items', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-rl" type="number" min="1" max="10" name="sseo[related_limit]" value="' . esc_attr( (string) Settings::get( 'related_limit' ) ) . '"> ';
			echo '<label for="sseo-rt">' . esc_html__( 'Default heading', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-rt" type="text" name="sseo[related_title]" value="' . esc_attr( (string) Settings::get( 'related_title' ) ) . '" placeholder="' . esc_attr__( 'Related articles', 'semantic-seo-engine-vi-en' ) . '"></p>';
			echo '<p><label><input type="checkbox" name="sseo[related_css]" value="1"' . checked( (bool) Settings::get( 'related_css', true ), true, false ) . '> ' . esc_html__( 'Print the tiny default stylesheet (disable to style it in your theme)', 'semantic-seo-engine-vi-en' ) . '</label></p>';
			echo '<button class="button button-primary">' . esc_html__( 'Save', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		}
		echo '</section>';

		echo '<section class="sseo-panel"><h2>' . esc_html__( 'How to place it', 'semantic-seo-engine-vi-en' ) . '</h2><ul>';
		echo '<li><strong>' . esc_html__( 'Shortcode:', 'semantic-seo-engine-vi-en' ) . '</strong> <code>[sseo_related limit="5" title="…"]</code></li>';
		echo '<li><strong>' . esc_html__( 'Block:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html__( '“Related content (Semantic SEO)” in the block inserter (server-rendered).', 'semantic-seo-engine-vi-en' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'PHP:', 'semantic-seo-engine-vi-en' ) . '</strong> <code>sseo_get_related_posts( $post_id, array( \'limit\' =&gt; 5 ) )</code> ' . esc_html__( 'and the filter', 'semantic-seo-engine-vi-en' ) . ' <code>sseo_related_posts</code></li>';
		echo '<li><strong>' . esc_html__( 'Template override:', 'semantic-seo-engine-vi-en' ) . '</strong> <code>your-theme/semantic-seo/related-content.php</code></li></ul>';
		echo '<p class="description">' . esc_html__( 'Only published, indexable, same-language posts are listed; near-duplicates and translations are excluded. Lists are cached and refreshed when posts change. No model or analysis runs on page views.', 'semantic-seo-engine-vi-en' ) . '</p></section>';

		$q = (int) Ui::get( 'post', '0' );
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Preview', 'semantic-seo-engine-vi-en' ) . '</h2><form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-related"><label for="sseo-rp">' . esc_html__( 'Post ID', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-rp" type="number" min="1" name="post" value="' . esc_attr( $q > 0 ? (string) $q : '' ) . '"> <button class="button">' . esc_html__( 'Preview', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		if ( $q > 0 ) {
			$res = Api::related( array( 'post_id' => $q ) );
			if ( is_wp_error( $res ) ) {
				Ui::error_state( $res );
			} else {
				$rows = array();
				foreach ( $res['items'] as $r ) {
					$ev     = json_decode( (string) $r['evidence'], true );
					$rows[] = array(
						'rank'  => esc_html( (string) $r['rank_pos'] ),
						'page'  => Ui::doc_link( $r['related_id'], $r['title'], $r['url'] ),
						'why'   => esc_html( is_array( $ev ) ? implode( ', ', (array) $ev['shared_terms'] ) : '' ),
						'score' => esc_html( (string) round( (float) $r['score'], 3 ) ),
					);
				}
				Ui::table(
					__( 'Related preview', 'semantic-seo-engine-vi-en' ),
					array(
						'rank'  => '#',
						'page'  => __( 'Page', 'semantic-seo-engine-vi-en' ),
						'why'   => __( 'Shared topic terms', 'semantic-seo-engine-vi-en' ),
						'score' => __( 'Score', 'semantic-seo-engine-vi-en' ),
					),
					$rows,
					__( 'No related content computed for this post yet.', 'semantic-seo-engine-vi-en' )
				);
			}
		}
		echo '</section>';
		Ui::footer();
	}
}
