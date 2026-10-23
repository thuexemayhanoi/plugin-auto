<?php
/**
 * Loads the LABELLED TEST FIXTURE corpus into a disposable site (wp eval-file).
 * Uses real Polylang when active (languages vi/en, translation groups), otherwise leaves
 * everything in the site language. Prints a JSON map fixture key => post ID.
 *
 * @package SemanticSEO
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

$sseo_corpus = require dirname( __DIR__ ) . '/fixtures/corpus.php';
$sseo_pll    = function_exists( 'pll_set_post_language' ) && function_exists( 'PLL' );

if ( $sseo_pll ) {
	$sseo_model = PLL()->model;
	$sseo_have  = wp_list_pluck( $sseo_model->get_languages_list(), 'slug' );
	foreach (
		array(
			array( 'name' => 'Tiếng Việt', 'slug' => 'vi', 'locale' => 'vi', 'rtl' => false, 'term_group' => 0, 'flag' => 'vn' ),
			array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => false, 'term_group' => 1, 'flag' => 'us' ),
		) as $sseo_lang
	) {
		if ( ! in_array( $sseo_lang['slug'], $sseo_have, true ) ) {
			$sseo_res = $sseo_model->add_language( $sseo_lang );
			if ( is_wp_error( $sseo_res ) ) {
				WP_CLI::error( $sseo_res->get_error_message() );
			}
		}
	}
	$sseo_model->clean_languages_cache();
	// Default category of the default language, as Polylang expects.
	$sseo_opts = get_option( 'polylang' );
	$sseo_opts['default_lang'] = 'vi';
	$sseo_opts['force_lang']   = 0;
	update_option( 'polylang', $sseo_opts );
}

$sseo_cats = array();
foreach ( $sseo_corpus['categories'] as $c ) {
	$sseo_parent = '' !== $c['parent'] ? $sseo_cats[ $c['parent'] ] : 0;
	$sseo_res    = wp_insert_term( $c['name'], 'category', array( 'slug' => $c['slug'], 'parent' => $sseo_parent ) );
	if ( is_wp_error( $sseo_res ) ) {
		WP_CLI::error( $c['slug'] . ': ' . $sseo_res->get_error_message() );
	}
	$sseo_cats[ $c['key'] ] = (int) $sseo_res['term_id'];
	if ( $sseo_pll ) {
		pll_set_term_language( (int) $sseo_res['term_id'], $c['lang'] );
	}
}

$sseo_posts  = array();
$sseo_groups = array();
$sseo_index  = 0;
foreach ( $sseo_corpus['docs'] as $d ) {
	$sseo_status = isset( $d['status'] ) ? $d['status'] : 'publish';
	// Distinct dates keep archive ordering deterministic (needed for byte-level front-end comparisons).
	$sseo_date = gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 08:00:00 UTC' ) + ( $sseo_index++ ) * 3600 );
	$sseo_id     = wp_insert_post(
		array(
			'post_type'    => $d['type'],
			'post_status'  => 'trash' === $sseo_status ? 'publish' : $sseo_status,
			'post_title'   => $d['title'],
			'post_name'    => $d['slug'],
			'post_content' => '',
			'post_author'  => 1,
			'post_date'    => $sseo_date,
			'post_date_gmt' => $sseo_date,
		),
		true
	);
	if ( is_wp_error( $sseo_id ) ) {
		WP_CLI::error( $d['key'] . ': ' . $sseo_id->get_error_message() );
	}
	$sseo_posts[ $d['key'] ] = $sseo_id;
	if ( $sseo_pll ) {
		pll_set_post_language( $sseo_id, $d['lang'] );
		$sseo_groups[ $d['group'] ][ $d['lang'] ] = $sseo_id;
	}
	if ( 'post' === $d['type'] && array() !== $d['cats'] ) {
		wp_set_post_categories(
			$sseo_id,
			array_map(
				static function ( $k ) use ( $sseo_cats ) {
					return $sseo_cats[ $k ];
				},
				$d['cats']
			)
		);
	}
	if ( ! empty( $d['focus'] ) ) {
		update_post_meta( $sseo_id, '_yoast_wpseo_focuskw', $d['focus'] );
	}
}
if ( $sseo_pll ) {
	foreach ( $sseo_groups as $sseo_members ) {
		if ( count( $sseo_members ) > 1 ) {
			pll_save_post_translations( $sseo_members );
		}
	}
}
foreach ( $sseo_corpus['docs'] as $d ) {
	$sseo_content = preg_replace_callback(
		'/\{\{url:([a-z0-9\-]+)\}\}/',
		static function ( $m ) use ( $sseo_posts ) {
			return get_permalink( $sseo_posts[ $m[1] ] );
		},
		$d['content']
	);
	wp_update_post(
		array(
			'ID'           => $sseo_posts[ $d['key'] ],
			'post_content' => $sseo_content,
		)
	);
	if ( isset( $d['status'] ) && 'trash' === $d['status'] ) {
		wp_trash_post( $sseo_posts[ $d['key'] ] );
	}
}
echo wp_json_encode( $sseo_posts );
