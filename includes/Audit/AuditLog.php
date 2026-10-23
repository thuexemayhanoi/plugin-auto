<?php
/**
 * Redacted, bounded audit log.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Audit;

use SemanticSEO\Database\Db;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Security\RateLimiter;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Never stores secrets, raw IPs, passwords, tokens or full post content.
 */
final class AuditLog {

	const SECRET_KEYS = array( 'password', 'pass', 'token', 'secret', 'nonce', 'authorization', 'cookie', 'app_password', 'lease_token', 'api_key' );

	/**
	 * Records an event.
	 *
	 * @param string $action      Action (e.g. change.applied).
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $result      ok|denied|error|...
	 * @param array  $details     Details (redacted).
	 * @return int
	 */
	public static function record( $action, $object_type = '', $object_id = 0, $result = 'ok', array $details = array() ) {
		return Db::insert(
			'audit',
			array(
				'created_at'  => Util::now(),
				'user_id'     => get_current_user_id(),
				'via'         => AuthContext::via(),
				'action'      => substr( (string) $action, 0, 64 ),
				'object_type' => substr( (string) $object_type, 0, 20 ),
				'object_id'   => (int) $object_id,
				'result'      => substr( (string) $result, 0, 16 ),
				'ip_hash'     => RateLimiter::ip_hash(),
				'details'     => Util::truncate( Util::json( self::redact( $details ) ), 8000 ),
			)
		);
	}

	/**
	 * Recursively redacts secret-looking keys and truncates long strings.
	 *
	 * @param mixed $data Data.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	public static function redact( $data, $depth = 0 ) {
		if ( $depth > 5 ) {
			return '[depth]';
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				if ( is_string( $k ) && in_array( strtolower( $k ), self::SECRET_KEYS, true ) ) {
					$out[ $k ] = '[redacted]';
					continue;
				}
				$out[ $k ] = self::redact( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			// Application Password shaped strings and bearer tokens.
			$data = preg_replace( '/\b[A-Za-z0-9]{4}( [A-Za-z0-9]{4}){5}\b/', '[redacted]', $data );
			$data = preg_replace( '/Bearer\s+\S+/i', 'Bearer [redacted]', (string) $data );
			return Util::truncate( (string) $data, 500 );
		}
		return $data;
	}

	/**
	 * Paginated query.
	 *
	 * @param array $f Filters: action, user_id, result, object_id, before_id, limit.
	 * @return array{items:array,next_before_id:int}
	 */
	public static function query( array $f ) {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['action'] ) ) {
			$where[] = 'action LIKE %s';
			$args[]  = Db::esc_like_prefix( (string) $f['action'] );
		}
		if ( ! empty( $f['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $f['user_id'];
		}
		if ( ! empty( $f['result'] ) ) {
			$where[] = 'result = %s';
			$args[]  = (string) $f['result'];
		}
		if ( ! empty( $f['object_id'] ) ) {
			$where[] = 'object_id = %d';
			$args[]  = (int) $f['object_id'];
		}
		if ( ! empty( $f['before_id'] ) ) {
			$where[] = 'id < %d';
			$args[]  = (int) $f['before_id'];
		}
		$limit  = Util::clamp_int( isset( $f['limit'] ) ? $f['limit'] : 50, 1, 100 );
		$args[] = $limit;
		$rows   = Db::rows( 'SELECT * FROM {audit} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args );
		foreach ( $rows as &$r ) {
			$r['details'] = Util::json_array( $r['details'] );
			$r['id']      = (int) $r['id'];
		}
		unset( $r );
		return array(
			'items'          => $rows,
			'next_before_id' => count( $rows ) === $limit ? (int) end( $rows )['id'] : 0,
		);
	}

	/**
	 * Deletes entries older than the retention period.
	 *
	 * @return int
	 */
	public static function prune() {
		$days = (int) Settings::get( 'audit_retention_days', 180 );
		return max( 0, Db::query( 'DELETE FROM {audit} WHERE created_at < %s', array( Util::now( -DAY_IN_SECONDS * $days ) ) ) );
	}
}
