<?php
/**
 * Read-only fingerprint of everything activation must NOT change (wp eval-file).
 * Prints JSON: table/area => sha256. Plugin-owned options/tables and volatile caches are excluded.
 *
 * @package SemanticSEO
 */

global $wpdb;

$sseo_hash = static function ( $rows ) {
	return hash( 'sha256', wp_json_encode( $rows ) );
};
$sseo_out  = array(
	'posts'              => $sseo_hash( $wpdb->get_results( "SELECT ID, post_author, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_modified_gmt, post_parent, guid, menu_order, post_type, post_mime_type FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) ),
	'postmeta'           => $sseo_hash( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key NOT IN ('_edit_lock') ORDER BY meta_id", ARRAY_A ) ),
	'terms'              => $sseo_hash( $wpdb->get_results( "SELECT * FROM {$wpdb->terms} ORDER BY term_id", ARRAY_A ) ),
	'term_taxonomy'      => $sseo_hash( $wpdb->get_results( "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id", ARRAY_A ) ),
	'term_relationships' => $sseo_hash( $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A ) ),
	'termmeta'           => $sseo_hash( $wpdb->get_results( "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} ORDER BY meta_id", ARRAY_A ) ),
	'comments'           => $sseo_hash( $wpdb->get_results( "SELECT * FROM {$wpdb->comments} ORDER BY comment_ID", ARRAY_A ) ),
	'users'              => $sseo_hash( $wpdb->get_results( "SELECT ID, user_login, user_email, user_url, user_status FROM {$wpdb->users} ORDER BY ID", ARRAY_A ) ),
	'usermeta'           => $sseo_hash( $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key NOT IN ('session_tokens') ORDER BY umeta_id", ARRAY_A ) ),
	'options'            => $sseo_hash(
		$wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'
			AND option_name NOT LIKE 'sseo\\_%' AND option_name NOT IN ('cron', 'active_plugins', 'recently_activated', 'rewrite_rules', 'recovery_keys', 'can_compress_scripts')
			ORDER BY option_name",
			ARRAY_A
		)
	),
	'rewrite_rules'      => $sseo_hash( get_option( 'rewrite_rules' ) ),
	'roles'              => $sseo_hash( get_option( $wpdb->prefix . 'user_roles' ) ),
	'permalink'          => get_option( 'permalink_structure' ),
	'blog_public'        => get_option( 'blog_public' ),
);
echo wp_json_encode( $sseo_out );
