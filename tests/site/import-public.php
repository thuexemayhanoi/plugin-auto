<?php
/**
 * CI only (not shipped): loads a public-content export (tests/site/export_public.py) into a
 * DISPOSABLE local WordPress site, for the read-only pilot. Never run on a real site.
 *
 *   SSEO_SITE_EXPORT=/path/site.json wp eval-file tests/site/import-public.php
 *
 * - Categories (slug, name, parent, description), published posts and pages (slug, dates,
 *   content, categories, page parents) are recreated.
 * - Links to the public site inside the content are rewritten to the copied objects so the link
 *   graph resolves as it does on the real site; other links to the site stay internal.
 * - Language: content published below /en/ is English. English posts are registered as standalone
 *   English posts and English categories as English categories in "Nguyen Ha VI-EN Core" (the
 *   site's language plugin, installed on this disposable copy), because its VI⇔EN pairs are not
 *   public; so no translation pairs exist in the copy.
 * - Yoast data visible to visitors (meta description, noindex, canonical) is copied into Yoast's
 *   post meta; focus keywords are not public and stay empty.
 *
 * @package SemanticSEO
 */

// phpcs:disable

$export = getenv( 'SSEO_SITE_EXPORT' );
$data   = json_decode( (string) file_get_contents( $export ), true );
if ( ! is_array( $data ) ) {
	WP_CLI::error( 'Cannot read the export.' );
}
$prod = rtrim( $data['site'], '/' );
$home = rtrim( home_url(), '/' );

$is_en = static function ( $link ) use ( $prod ) {
	$path = (string) wp_parse_url( $link, PHP_URL_PATH );
	return '/en/' === $path || 0 === strpos( $path, '/en/' );
};

// 1. Categories (parents first by repeated passes).
$cat_map  = array();
$cat_link = array();
$en_cats  = array();
$pending  = $data['categories'];
for ( $pass = 0; $pass < 10 && $pending; $pass++ ) {
	$next = array();
	foreach ( $pending as $c ) {
		if ( 1 === (int) $c['id'] && 'uncategorized' === $c['slug'] ) {
			$cat_map[1] = 1;
			continue;
		}
		if ( $c['parent'] && ! isset( $cat_map[ $c['parent'] ] ) ) {
			$next[] = $c;
			continue;
		}
		$res = wp_insert_term(
			html_entity_decode( $c['name'], ENT_QUOTES, 'UTF-8' ),
			'category',
			array(
				'slug'        => $c['slug'],
				'parent'      => $c['parent'] ? $cat_map[ $c['parent'] ] : 0,
				'description' => (string) $c['description'],
			)
		);
		if ( is_wp_error( $res ) ) {
			$existing = get_term_by( 'slug', $c['slug'], 'category' );
			$cat_map[ $c['id'] ] = $existing ? (int) $existing->term_id : 1;
		} else {
			$cat_map[ $c['id'] ] = (int) $res['term_id'];
		}
		$cat_link[ $c['link'] ] = $cat_map[ $c['id'] ];
		if ( $is_en( $c['link'] ) ) {
			$en_cats[] = $cat_map[ $c['id'] ];
		}
	}
	$pending = $next;
}

// 2. Posts and pages (pages parents first).
$post_map  = array();
$post_link = array();
$en_posts  = array();
$yoast     = array();
$insert    = static function ( $item, $type, $parent ) use ( &$cat_map ) {
	$args = array(
		'post_type'     => $type,
		'post_status'   => 'publish',
		'post_title'    => html_entity_decode( (string) $item['title']['rendered'], ENT_QUOTES, 'UTF-8' ),
		'post_name'     => $item['slug'],
		'post_content'  => (string) $item['content']['rendered'],
		'post_excerpt'  => '',
		'post_date_gmt' => $item['date_gmt'],
		'post_date'     => get_date_from_gmt( $item['date_gmt'] ),
		'post_parent'   => $parent,
	);
	if ( 'post' === $type ) {
		$cats = array();
		foreach ( (array) $item['categories'] as $cid ) {
			if ( isset( $cat_map[ $cid ] ) ) {
				$cats[] = $cat_map[ $cid ];
			}
		}
		$args['post_category'] = $cats ? $cats : array( 1 );
	}
	return wp_insert_post( wp_slash( $args ), true );
};
foreach ( $data['posts'] as $p ) {
	$id = $insert( $p, 'post', 0 );
	if ( is_wp_error( $id ) ) {
		continue;
	}
	$post_map[ 'post:' . $p['id'] ] = $id;
	$post_link[ $p['link'] ]       = $id;
	$yoast[ $id ]                  = isset( $p['yoast_head_json'] ) ? $p['yoast_head_json'] : null;
	if ( $is_en( $p['link'] ) ) {
		$en_posts[] = $id;
	}
}
$pending = $data['pages'];
for ( $pass = 0; $pass < 10 && $pending; $pass++ ) {
	$next = array();
	foreach ( $pending as $p ) {
		if ( $p['parent'] && ! isset( $post_map[ 'page:' . $p['parent'] ] ) ) {
			$next[] = $p;
			continue;
		}
		$id = $insert( $p, 'page', $p['parent'] ? $post_map[ 'page:' . $p['parent'] ] : 0 );
		if ( is_wp_error( $id ) ) {
			continue;
		}
		$post_map[ 'page:' . $p['id'] ] = $id;
		$post_link[ $p['link'] ]       = $id;
		$yoast[ $id ]                  = isset( $p['yoast_head_json'] ) ? $p['yoast_head_json'] : null;
	}
	$pending = $next;
}

// 3. Yoast data visible to visitors.
$noindex = 0;
foreach ( $yoast as $id => $y ) {
	if ( ! is_array( $y ) ) {
		continue;
	}
	if ( ! empty( $y['description'] ) ) {
		update_post_meta( $id, '_yoast_wpseo_metadesc', wp_slash( html_entity_decode( $y['description'], ENT_QUOTES, 'UTF-8' ) ) );
	}
	if ( isset( $y['robots']['index'] ) && 'noindex' === $y['robots']['index'] ) {
		update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
		++$noindex;
	}
}

// 4. Language plugin data (standalone English posts and English categories; pairs are not public).
update_option( 'nh_vien_independent_en_posts_v1', array_values( $en_posts ), false );
update_option( 'nh_vien_english_categories_v1', array_values( array_unique( $en_cats ) ), false );

// 5. Rewrite links to the public site so they point at the copies.
$map = array();
foreach ( $post_link as $link => $id ) {
	$local = get_permalink( $id );
	foreach ( array( $link, rtrim( $link, '/' ) ) as $variant ) {
		$map[ $variant ] = $local;
	}
	$canonical = isset( $yoast[ $id ]['canonical'] ) ? (string) $yoast[ $id ]['canonical'] : '';
	if ( '' !== $canonical && rtrim( $canonical, '/' ) !== rtrim( $link, '/' ) ) {
		update_post_meta( $id, '_yoast_wpseo_canonical', isset( $map[ $canonical ] ) ? $map[ $canonical ] : str_replace( $prod, $home, $canonical ) );
	}
}
foreach ( $cat_link as $link => $tid ) {
	$local = get_term_link( (int) $tid, 'category' );
	if ( ! is_wp_error( $local ) ) {
		$map[ $link ]             = $local;
		$map[ rtrim( $link, '/' ) ] = $local;
	}
}
$map[ $prod ] = $home;
$rewritten    = 0;
foreach ( $post_link as $id ) {
	$content = get_post_field( 'post_content', $id, 'raw' );
	$new     = strtr( $content, $map );
	if ( $new !== $content ) {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $new,
				)
			)
		);
		++$rewritten;
	}
}

WP_CLI::line(
	wp_json_encode(
		array(
			'categories'           => count( $cat_map ),
			'posts'                => count( array_filter( array_keys( $post_map ), static function ( $k ) {
				return 0 === strpos( $k, 'post:' );
			} ) ),
			'pages'                => count( array_filter( array_keys( $post_map ), static function ( $k ) {
				return 0 === strpos( $k, 'page:' );
			} ) ),
			'english_posts'        => count( $en_posts ),
			'english_categories'   => count( array_unique( $en_cats ) ),
			'noindex'              => $noindex,
			'contents_rewritten'   => $rewritten,
		)
	)
);
