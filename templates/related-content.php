<?php
/**
 * Related content template. Copy to yourtheme/semantic-seo/related-content.php to override.
 *
 * Available variables: $sseo_related_items (array of post_id, title, url), $sseo_related_title (string).
 *
 * @package SemanticSEO
 */

defined( 'ABSPATH' ) || exit;
?>
<nav class="sseo-related" aria-label="<?php echo esc_attr( $sseo_related_title ); ?>">
	<h2 class="sseo-related__title"><?php echo esc_html( $sseo_related_title ); ?></h2>
	<ul class="sseo-related__list">
		<?php foreach ( $sseo_related_items as $sseo_item ) : ?>
			<li class="sseo-related__item"><a href="<?php echo esc_url( $sseo_item['url'] ); ?>"><?php echo esc_html( wp_strip_all_tags( $sseo_item['title'] ) ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</nav>
