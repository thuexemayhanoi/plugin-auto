<?php
/**
 * Internal link graph built from links observed by the scanner.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Graph;

use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Security\UrlGuard;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Stores edges, resolves targets to inventoried documents and computes inbound/outbound counts.
 * Coverage is always reported: links in menus, widgets and theme templates are not observed.
 */
final class LinkGraph {

	/**
	 * Replaces the outbound links of a source document.
	 *
	 * @param int   $source_id Source document ID.
	 * @param array $links     Links from ContentExtractor.
	 * @param string $base_url Source URL (resolves relative links).
	 * @return void
	 */
	public static function store_links( $source_id, array $links, $base_url = '' ) {
		Db::delete( 'links', array( 'source_id' => (int) $source_id ) );
		$now = Util::now();
		foreach ( array_slice( $links, 0, 500 ) as $l ) {
			$href = self::absolutize( (string) $l['href'], $base_url );
			if ( '' === $href ) {
				continue;
			}
			$internal = UrlGuard::is_internal( $href );
			Db::insert(
				'links',
				array(
					'source_id'    => (int) $source_id,
					'target_id'    => 0,
					'target_url'   => Util::truncate( $href, 2000 ),
					'target_hash'  => sha1( self::canonical_form( $href ) ),
					'anchor'       => Util::truncate( (string) $l['anchor'], 250 ),
					'start_offset' => (int) $l['start'],
					'length'       => (int) $l['length'],
					'fingerprint'  => (string) $l['fingerprint'],
					'rel'          => substr( (string) $l['rel'], 0, 64 ),
					'is_internal'  => $internal ? 1 : 0,
					'resolution'   => $internal ? 'unresolved' : 'external',
					'scanned_at'   => $now,
				)
			);
		}
	}

	/**
	 * Converts relative hrefs to absolute URLs.
	 *
	 * @param string $href Href.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function absolutize( $href, $base ) {
		$href = trim( $href );
		if ( '' === $href ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) || 0 === strpos( $href, '//' ) || 0 === strpos( $href, '/' ) ) {
			return UrlGuard::normalize( $href );
		}
		if ( '' === $base ) {
			return '';
		}
		$dir = preg_replace( '#[^/]*$#', '', (string) wp_parse_url( $base, PHP_URL_PATH ) );
		return UrlGuard::normalize( '/' . ltrim( $dir . $href, '/' ) );
	}

	/**
	 * Canonical comparison form of a URL: lowercase host, no fragment, trailing slash normalized,
	 * tracking parameters dropped.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function canonical_form( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return (string) $url;
		}
		$path  = isset( $p['path'] ) ? $p['path'] : '/';
		$path  = '/' === $path ? '/' : trailingslashit( $path );
		$query = '';
		if ( ! empty( $p['query'] ) ) {
			parse_str( $p['query'], $q );
			foreach ( array_keys( $q ) as $k ) {
				if ( preg_match( '/^(utm_|fbclid|gclid|ref$)/i', (string) $k ) ) {
					unset( $q[ $k ] );
				}
			}
			ksort( $q );
			$query = array() === $q ? '' : '?' . http_build_query( $q );
		}
		$host = strtolower( preg_replace( '/^www\./i', '', $p['host'] ) );
		return $host . strtolower( rawurldecode( $path ) ) . $query;
	}

	/**
	 * Resolves internal link targets to documents (batch, resumable by link ID cursor).
	 *
	 * @param int $after Link ID cursor.
	 * @param int $limit Batch size.
	 * @return array{last_id:int,processed:int}
	 */
	public static function resolve_batch( $after, $limit ) {
		$rows = Db::rows( 'SELECT id, target_url FROM {links} WHERE is_internal = 1 AND id > %d ORDER BY id ASC LIMIT %d', array( (int) $after, (int) $limit ) );
		$last = (int) $after;
		foreach ( $rows as $r ) {
			$last = (int) $r['id'];
			list( $target, $resolution ) = self::resolve_url( (string) $r['target_url'] );
			Db::update(
				'links',
				array(
					'target_id'  => $target,
					'resolution' => $resolution,
				),
				array( 'id' => $last )
			);
		}
		return array(
			'last_id'   => $last,
			'processed' => count( $rows ),
		);
	}

	/**
	 * Resolves one internal URL.
	 *
	 * @param string $url URL.
	 * @return array{0:int,1:string} [document ID, resolution]
	 */
	public static function resolve_url( $url ) {
		$doc = DocumentRepository::by_url( $url );
		if ( null === $doc ) {
			$no_fragment = preg_replace( '/#.*$/', '', $url );
			$doc         = DocumentRepository::by_url( $no_fragment );
		}
		if ( null === $doc ) {
			$post_id = url_to_postid( $url );
			if ( $post_id > 0 ) {
				$doc = DocumentRepository::by_object( 'post', $post_id );
				if ( null === $doc ) {
					$status = get_post_status( $post_id );
					return array( 0, 'publish' === $status ? 'not_in_scope' : 'non_public' );
				}
			}
		}
		if ( null === $doc ) {
			$slug = basename( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			if ( '' !== $slug ) {
				global $wpdb;
				// A trashed post keeps its original slug in _wp_desired_post_slug: the link is broken.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$trashed = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_status = 'trash' AND m.meta_key = '_wp_desired_post_slug' AND m.meta_value = %s LIMIT 1", $slug ) );
				if ( $trashed ) {
					return array( 0, 'broken' );
				}
				// An old slug means WordPress will redirect (an extra hop, not an error).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$moved = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1", $slug ) );
				if ( $moved ) {
					$target = DocumentRepository::by_object( 'post', (int) $moved );
					return array( null === $target ? 0 : (int) $target['id'], 'redirect' );
				}
			}
			return array( 0, 'unresolved' );
		}
		if ( 'deleted' === $doc['status'] ) {
			return array( (int) $doc['id'], 'broken' );
		}
		if ( 'publish' !== $doc['status'] ) {
			return array( (int) $doc['id'], 'non_public' );
		}
		return array( (int) $doc['id'], 'resolved' );
	}

	/**
	 * Recomputes inbound/outbound internal link counts for all documents.
	 *
	 * @return void
	 */
	public static function recount() {
		Db::query( 'UPDATE {documents} SET inbound_count = 0, outbound_count = 0' );
		Db::query(
			"UPDATE {documents} d JOIN (SELECT target_id, COUNT(DISTINCT source_id) AS n FROM {links} WHERE is_internal = 1 AND target_id > 0 AND resolution = 'resolved' AND target_id <> source_id GROUP BY target_id) x ON x.target_id = d.id SET d.inbound_count = x.n"
		);
		Db::query(
			"UPDATE {documents} d JOIN (SELECT source_id, COUNT(DISTINCT target_id) AS n FROM {links} WHERE is_internal = 1 AND target_id > 0 AND resolution = 'resolved' AND target_id <> source_id GROUP BY source_id) x ON x.source_id = d.id SET d.outbound_count = x.n"
		);
	}

	/**
	 * Target document IDs already linked from a source.
	 *
	 * @param int $source_id Source document ID.
	 * @return int[]
	 */
	public static function linked_targets( $source_id ) {
		return array_map( 'intval', Db::col( 'SELECT DISTINCT target_id FROM {links} WHERE source_id = %d AND target_id > 0', array( (int) $source_id ) ) );
	}

	/**
	 * Anchors used site-wide for a target (anchor reuse checks).
	 *
	 * @param int $target_id Target document ID.
	 * @return array<string,int> anchor => count
	 */
	public static function anchors_for_target( $target_id ) {
		$out = array();
		foreach ( Db::rows( 'SELECT anchor, COUNT(*) AS n FROM {links} WHERE target_id = %d GROUP BY anchor', array( (int) $target_id ) ) as $r ) {
			$out[ (string) $r['anchor'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Targets already using an anchor text (to avoid one anchor pointing at several pages).
	 *
	 * @param string $anchor Anchor text.
	 * @return int[]
	 */
	public static function targets_for_anchor( $anchor ) {
		return array_map( 'intval', Db::col( 'SELECT DISTINCT target_id FROM {links} WHERE anchor = %s AND target_id > 0', array( (string) $anchor ) ) );
	}

	/**
	 * Scan coverage figures shown next to orphan reports.
	 *
	 * @return array
	 */
	public static function coverage() {
		return array(
			'documents_scanned'   => (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND scanned_at IS NOT NULL" ),
			'internal_links'      => (int) Db::value( 'SELECT COUNT(*) FROM {links} WHERE is_internal = 1' ),
			'resolved_links'      => (int) Db::value( "SELECT COUNT(*) FROM {links} WHERE resolution = 'resolved'" ),
			'unresolved_links'    => (int) Db::value( "SELECT COUNT(*) FROM {links} WHERE is_internal = 1 AND resolution IN ('unresolved','broken','non_public','not_in_scope')" ),
			'observed_sources'    => 'post_content and term descriptions only',
			'not_observed'        => 'navigation menus, widgets, theme templates, JavaScript-rendered links, other plugins\' output',
		);
	}

	/**
	 * Orphan candidates: published, indexable, in-scope documents with zero observed inbound links.
	 *
	 * @param string $locale Locale filter.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function orphans( $locale = '', $limit = 50 ) {
		$args = array();
		$sql  = "SELECT id, title, url, locale, subtype, word_count, cluster_role FROM {documents} WHERE status = 'publish' AND indexable = 1 AND excluded = 0 AND inbound_count = 0 AND object_type = 'post'";
		if ( '' !== $locale ) {
			$sql   .= ' AND locale = %s';
			$args[] = $locale;
		}
		$front = (int) get_option( 'page_on_front' );
		if ( $front > 0 ) {
			$sql   .= ' AND NOT (object_type = %s AND object_id = %d)';
			$args[] = 'post';
			$args[] = $front;
		}
		$sql   .= ' ORDER BY word_count DESC LIMIT %d';
		$args[] = (int) $limit;
		return Db::rows( $sql, $args );
	}
}
