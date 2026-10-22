<?php
/**
 * Portable atomic mutex based on the unique wp_options.option_name constraint.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Uses INSERT IGNORE (add_option() is an upsert and therefore not atomic). Works on MySQL,
 * MariaDB and SQLite-backed WordPress alike, without GET_LOCK.
 */
final class Mutex {

	/**
	 * Tries to acquire a named lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which a stale lock may be taken over.
	 * @return string|false Lock token on success.
	 */
	public static function acquire( $name, $ttl = 120 ) {
		global $wpdb;
		$key   = self::key( $name );
		$token = bin2hex( random_bytes( 8 ) );
		$value = time() . ':' . $token;
		if ( self::insert( $key, $value ) ) {
			return $token;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$since   = (int) strtok( $current, ':' );
		if ( '' !== $current && $since > 0 && ( time() - $since ) > (int) $ttl ) {
			// Compare-and-delete so two processes cannot both take over the same stale lock.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $current ) );
			if ( $deleted && self::insert( $key, $value ) ) {
				return $token;
			}
		}
		return false;
	}

	/**
	 * Releases a lock if the token matches.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Token returned by acquire().
	 * @return bool
	 */
	public static function release( $name, $token ) {
		global $wpdb;
		$key = self::key( $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $key, '%:' . $wpdb->esc_like( (string) $token ) ) );
		self::flush_cache( $key );
		return (bool) $deleted;
	}

	/**
	 * Whether a lock is currently held (and not stale).
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  TTL.
	 * @return bool
	 */
	public static function is_locked( $name, $ttl = 120 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::key( $name ) ) );
		$since   = (int) strtok( $current, ':' );
		return $since > 0 && ( time() - $since ) <= (int) $ttl;
	}

	/**
	 * Atomic insert.
	 *
	 * @param string $key   Option key.
	 * @param string $value Value.
	 * @return bool True when this call created the row.
	 */
	private static function insert( $key, $value ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value ) );
		$wpdb->suppress_errors( $suppress );
		self::flush_cache( $key );
		return 1 === (int) $rows;
	}

	/**
	 * Drops cached copies of the option.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private static function flush_cache( $key ) {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Option key for a lock.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function key( $name ) {
		return 'sseo_mutex_' . substr( preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $name ) ), 0, 150 );
	}
}
