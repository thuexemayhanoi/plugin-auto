<?php
/**
 * Strict URL allowlist and SSRF defense: only this site's own host is ever accepted.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Security;

defined( 'ABSPATH' ) || exit;

/**
 * URL validation helpers.
 */
final class UrlGuard {

	/**
	 * Hosts considered internal (home + site URL hosts, lowercase, without www. variance).
	 *
	 * @return string[]
	 */
	public static function internal_hosts() {
		$hosts = array();
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( is_string( $h ) && '' !== $h ) {
				$h             = strtolower( $h );
				$hosts[ $h ]   = $h;
				$alt           = 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h;
				$hosts[ $alt ] = $alt;
			}
		}
		return array_values( $hosts );
	}

	/**
	 * Normalizes a candidate URL relative to the site. Returns '' when unusable.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		if ( preg_match( '/[\x00-\x1F\x7F\s]/', $url ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Whether a URL points to this site (scheme http/https, host in allowlist, no credentials).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_internal( $url ) {
		$url = self::normalize( $url );
		if ( '' === $url ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, self::internal_hosts(), true );
	}

	/**
	 * Validates a URL for server-side HTTP verification. Internal host only; the site's own
	 * resolved IP is the only private address allowed (staging on localhost).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function may_fetch( $url ) {
		if ( ! self::is_internal( $url ) ) {
			return false;
		}
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
		return null === $port || $port === $home || in_array( (int) $port, array( 80, 443 ), true );
	}
}
