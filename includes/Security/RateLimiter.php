<?php
/**
 * Fixed-window rate limiter per user and route class.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Security;

use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Uses transients (object cache when available). Limits are per minute.
 */
final class RateLimiter {

	/**
	 * Consumes one unit; returns seconds to wait (0 = allowed).
	 *
	 * @param string $bucket  read|analyze|write|embed|auth.
	 * @param int    $user_id User ID (0 = anonymous, keyed by IP hash).
	 * @return int
	 */
	public static function hit( $bucket, $user_id ) {
		$limit  = self::limit( $bucket );
		$window = 60;
		$slot   = (int) floor( time() / $window );
		$who    = $user_id > 0 ? 'u' . (int) $user_id : 'ip' . self::ip_hash();
		$key    = 'sseo_rl_' . md5( $bucket . '|' . $who . '|' . $slot );
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return max( 1, ( $slot + 1 ) * $window - time() );
		}
		set_transient( $key, $count + 1, $window + 5 );
		return 0;
	}

	/**
	 * Configured per-minute limit of a bucket.
	 *
	 * @param string $bucket Bucket.
	 * @return int
	 */
	public static function limit( $bucket ) {
		switch ( $bucket ) {
			case 'analyze':
				return (int) Settings::get( 'rate_analyze', 10 );
			case 'write':
				return (int) Settings::get( 'rate_write', 10 );
			case 'embed':
				return max( 30, (int) Settings::get( 'rate_read', 120 ) );
			default:
				return (int) Settings::get( 'rate_read', 120 );
		}
	}

	/**
	 * Short salted hash of the client IP (never stored raw).
	 *
	 * @return string
	 */
	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 16 );
	}
}
