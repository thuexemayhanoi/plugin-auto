<?php
/**
 * Locks and exclusions enforced by the shared service layer for humans and agents alike.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Locks;

use SemanticSEO\Database\Db;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Types: lock = object may not be modified by any proposal; editorial fields are frozen.
 * exclude = object is inventoried but never suggested, proposed or used as a link target.
 */
final class LockService {

	const SCOPES = array( 'document', 'cluster', 'url_pattern' );
	const TYPES  = array( 'lock', 'exclude' );

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Adds a lock/exclusion.
	 *
	 * @param string $scope  document|cluster|url_pattern.
	 * @param string $value  e.g. "post:12", "7", "/en/old-section/".
	 * @param string $type   lock|exclude.
	 * @param string $reason Reason.
	 * @return int|\WP_Error Lock ID.
	 */
	public static function add( $scope, $value, $type, $reason = '' ) {
		if ( ! in_array( $scope, self::SCOPES, true ) || ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'sseo_invalid_lock', __( 'Invalid lock scope or type.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$value = self::sanitize_value( $scope, $value );
		if ( '' === $value ) {
			return new \WP_Error( 'sseo_invalid_lock', __( 'Invalid lock target.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$existing = Db::value( 'SELECT id FROM {locks} WHERE scope_type = %s AND scope_value = %s AND lock_type = %s', array( $scope, $value, $type ) );
		if ( null !== $existing ) {
			return (int) $existing;
		}
		self::$cache = null;
		return Db::insert(
			'locks',
			array(
				'scope_type'  => $scope,
				'scope_value' => $value,
				'lock_type'   => $type,
				'reason'      => Util::truncate( sanitize_text_field( (string) $reason ), 500 ),
				'created_by'  => get_current_user_id(),
				'created_at'  => Util::now(),
			)
		);
	}

	/**
	 * Removes a lock by ID.
	 *
	 * @param int $id Lock ID.
	 * @return bool
	 */
	public static function remove( $id ) {
		self::$cache = null;
		return Db::delete( 'locks', array( 'id' => (int) $id ) ) > 0;
	}

	/**
	 * All locks.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = Db::rows( 'SELECT * FROM {locks} ORDER BY id DESC LIMIT 2000' );
		}
		return self::$cache;
	}

	/**
	 * Clears the request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Whether a document is excluded (settings, explicit exclusion or URL pattern).
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   Object ID.
	 * @param string $url         URL.
	 * @return bool
	 */
	public static function is_excluded( $object_type, $object_id, $url ) {
		if ( 'post' === $object_type && in_array( (int) $object_id, (array) Settings::get( 'exclude_post_ids', array() ), true ) ) {
			return true;
		}
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		foreach ( (array) Settings::get( 'exclude_url_patterns', array() ) as $p ) {
			if ( '' !== $p && false !== strpos( $path, $p ) ) {
				return true;
			}
		}
		return self::matches( $object_type, $object_id, $path, 'exclude' );
	}

	/**
	 * Whether a document is locked against modification.
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   Object ID.
	 * @param string $url         URL.
	 * @return bool
	 */
	public static function is_locked( $object_type, $object_id, $url = '' ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return self::matches( $object_type, $object_id, $path, 'lock' );
	}

	/**
	 * Whether a cluster is locked.
	 *
	 * @param int $cluster_id Cluster ID.
	 * @return bool
	 */
	public static function is_cluster_locked( $cluster_id ) {
		foreach ( self::all() as $l ) {
			if ( 'cluster' === $l['scope_type'] && (string) (int) $cluster_id === $l['scope_value'] && 'lock' === $l['lock_type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lock matching.
	 *
	 * @param string $object_type Type.
	 * @param int    $object_id   ID.
	 * @param string $path        URL path.
	 * @param string $type        lock|exclude.
	 * @return bool
	 */
	private static function matches( $object_type, $object_id, $path, $type ) {
		$key = $object_type . ':' . (int) $object_id;
		foreach ( self::all() as $l ) {
			if ( $l['lock_type'] !== $type ) {
				continue;
			}
			if ( 'document' === $l['scope_type'] && $l['scope_value'] === $key ) {
				return true;
			}
			if ( 'url_pattern' === $l['scope_type'] && '' !== $path && false !== strpos( $path, $l['scope_value'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalizes a lock target value.
	 *
	 * @param string $scope Scope.
	 * @param string $value Value.
	 * @return string
	 */
	private static function sanitize_value( $scope, $value ) {
		$value = trim( (string) $value );
		if ( 'document' === $scope ) {
			return preg_match( '/^(post|term):[1-9]\d*$/', $value ) ? $value : '';
		}
		if ( 'cluster' === $scope ) {
			return ctype_digit( $value ) && (int) $value > 0 ? (string) (int) $value : '';
		}
		return ( strlen( $value ) <= 190 && preg_match( '#^/[^\s<>"\'\\\\]*$#', $value ) ) ? $value : '';
	}
}
