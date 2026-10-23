<?php
/**
 * Small, dependency-free helpers shared by all layers.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers: canonical JSON, hashing, time, bounded values.
 */
final class Util {

	/**
	 * JSON-encodes with stable unicode output.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function json( $data ) {
		$out = function_exists( 'wp_json_encode' )
			? wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return false === $out ? '{}' : $out;
	}

	/**
	 * Decodes JSON into an array, returning a default on failure.
	 *
	 * @param string|null $json    JSON string.
	 * @param array       $default_value Fallback.
	 * @return array
	 */
	public static function json_array( $json, array $default_value = array() ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return $default_value;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : $default_value;
	}

	/**
	 * Canonical JSON (recursively key-sorted) used for diff hashes.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function canonical_json( $data ) {
		return self::json( self::ksort_recursive( $data ) );
	}

	/**
	 * Recursively sorts associative arrays by key.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function ksort_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		foreach ( $data as $k => $v ) {
			$data[ $k ] = self::ksort_recursive( $v );
		}
		if ( ! $is_list ) {
			ksort( $data, SORT_STRING );
		}
		return $data;
	}

	/**
	 * SHA-256 of a string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function sha256( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int $offset_seconds Optional offset.
	 * @return string
	 */
	public static function now( $offset_seconds = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset_seconds );
	}

	/**
	 * Clamps an integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Min.
	 * @param int   $max   Max.
	 * @return int
	 */
	public static function clamp_int( $value, $min, $max ) {
		$value = (int) $value;
		return max( (int) $min, min( (int) $max, $value ) );
	}

	/**
	 * Clamps a float.
	 *
	 * @param mixed $value Value.
	 * @param float $min   Min.
	 * @param float $max   Max.
	 * @return float
	 */
	public static function clamp_float( $value, $min, $max ) {
		$value = (float) $value;
		return max( (float) $min, min( (float) $max, $value ) );
	}

	/**
	 * Truncates a UTF-8 string to a maximum number of characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	public static function truncate( $text, $length ) {
		$text = (string) $text;
		if ( mb_strlen( $text, 'UTF-8' ) <= $length ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, max( 1, $length - 1 ), 'UTF-8' ) ) . '…';
	}

	/**
	 * Generates a random hex token.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	public static function token( $bytes = 32 ) {
		return bin2hex( random_bytes( (int) $bytes ) );
	}

	/**
	 * Encodes an opaque pagination cursor.
	 *
	 * @param array $data Cursor data.
	 * @return string
	 */
	public static function encode_cursor( array $data ) {
		return rtrim( strtr( base64_encode( self::json( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decodes an opaque pagination cursor.
	 *
	 * @param string $cursor Cursor.
	 * @return array
	 */
	public static function decode_cursor( $cursor ) {
		if ( ! is_string( $cursor ) || '' === $cursor || strlen( $cursor ) > 512 ) {
			return array();
		}
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $raw ? array() : self::json_array( $raw );
	}
}
