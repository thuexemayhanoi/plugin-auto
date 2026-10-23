<?php
/**
 * Evidence that a scan changed nothing on the site.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Security;

use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Fingerprints the WordPress-owned data a scan reads (posts, their meta and their terms) before
 * the scan and compares it afterwards. A difference means the content changed during the scan —
 * by a person editing, another plugin or a scheduled post — and the posts that changed are listed;
 * the scan itself never writes there. Meta that WordPress changes on its own (editor locks, ping
 * queue flags) is ignored.
 */
final class ReadOnlyProof {

	/**
	 * Per-post detail is kept for small scopes only (a pilot).
	 */
	const PER_POST_MAX = 100;

	/**
	 * Meta keys WordPress itself changes without any content change: editor locks (opening a post)
	 * and the ping/enclosure queue flags that WP-Cron removes after publishing.
	 */
	const IGNORED_META = array( '_edit_lock', '_edit_last', '_pingme', '_encloseme' );

	/**
	 * Fingerprint of the posts in scope (or of the given posts).
	 *
	 * @param int[] $post_ids Post IDs (empty = every post of the configured content types).
	 * @return array{at:string,posts:int,hash:string,per_post:array<int,string>}
	 */
	public static function snapshot( array $post_ids = array() ) {
		global $wpdb;
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$types    = array_values( array_filter( array_map( 'sanitize_key', (array) Settings::get( 'post_types', array( 'post', 'page' ) ) ) ) );
		$types    = array() === $types ? array( 'post', 'page' ) : $types;
		$where    = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
		$args     = $types;
		if ( array() !== $post_ids ) {
			$where .= ' AND p.ID IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
			$args   = array_merge( $args, $post_ids );
		}
		$ignored = implode( ',', array_fill( 0, count( self::IGNORED_META ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$posts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', p.ID, p.post_status, p.post_name, p.post_date_gmt, p.post_modified_gmt, p.post_parent, MD5(p.post_title), MD5(p.post_content), MD5(p.post_excerpt)))), 0) AS h FROM {$wpdb->posts} p WHERE {$where}", $args ), ARRAY_A );
		$meta  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', m.meta_id, m.post_id, m.meta_key, MD5(m.meta_value)))), 0) AS h FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE {$where} AND m.meta_key NOT IN ({$ignored})", array_merge( $args, self::IGNORED_META ) ), ARRAY_A );
		$terms = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', r.object_id, r.term_taxonomy_id))), 0) AS h FROM {$wpdb->term_relationships} r JOIN {$wpdb->posts} p ON p.ID = r.object_id WHERE {$where}", $args ), ARRAY_A );
		$per   = array();
		if ( array() !== $post_ids && count( $post_ids ) <= self::PER_POST_MAX ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS id, MD5(CONCAT_WS('|', p.post_status, p.post_name, p.post_date_gmt, p.post_modified_gmt, p.post_parent, MD5(p.post_title), MD5(p.post_content), MD5(p.post_excerpt), (SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', m.meta_id, m.meta_key, MD5(m.meta_value)))), 0) FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key NOT IN ({$ignored})), (SELECT COALESCE(BIT_XOR(CRC32(r.term_taxonomy_id)), 0) FROM {$wpdb->term_relationships} r WHERE r.object_id = p.ID))) AS h FROM {$wpdb->posts} p WHERE {$where}", array_merge( self::IGNORED_META, $args ) ), ARRAY_A ) as $r ) {
				$per[ (int) $r['id'] ] = (string) $r['h'];
			}
		}
		// phpcs:enable
		return array(
			'at'       => Util::now(),
			'posts'    => (int) $posts['n'],
			'hash'     => sha1( implode( '|', array( $posts['n'], $posts['h'], $meta['n'], $meta['h'], $terms['n'], $terms['h'] ) ) ),
			'per_post' => $per,
		);
	}

	/**
	 * Compares a snapshot with the current state.
	 *
	 * @param array $before   Snapshot taken before the scan.
	 * @param int[] $post_ids The same post IDs as the snapshot (empty = whole scope).
	 * @return array{unchanged:bool,posts:int,changed_post_ids:int[],checked_at:string}
	 */
	public static function compare( array $before, array $post_ids = array() ) {
		$now     = self::snapshot( $post_ids );
		$changed = array();
		if ( isset( $before['per_post'] ) && is_array( $before['per_post'] ) && array() !== $before['per_post'] ) {
			foreach ( $before['per_post'] as $id => $hash ) {
				if ( ! isset( $now['per_post'][ $id ] ) || $now['per_post'][ $id ] !== $hash ) {
					$changed[] = (int) $id;
				}
			}
		}
		return array(
			'unchanged'        => isset( $before['hash'] ) && $before['hash'] === $now['hash'],
			'posts'            => (int) $now['posts'],
			'changed_post_ids' => $changed,
			'checked_at'       => $now['at'],
		);
	}
}
