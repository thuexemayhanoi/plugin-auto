<?php
/**
 * Resolves object locale and translation mapping from the active multilingual framework.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Language;

use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Detection order: Polylang → WPML → configured URL prefixes → site default. Nothing is
 * assumed: the source of every locale is recorded as provenance.
 */
final class LanguageResolver {

	/**
	 * Name of the active framework.
	 *
	 * @return string polylang|wpml|url_prefix|site_default
	 */
	public static function framework() {
		$fw = 'site_default';
		if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_get_post_translations' ) ) {
			$fw = 'polylang';
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_post_language_details' ) ) {
			$fw = 'wpml';
		} elseif ( array() !== (array) Settings::get( 'url_prefix_locales', array() ) ) {
			$fw = 'url_prefix';
		}
		/**
		 * Filters the detected multilingual framework (polylang|wpml|url_prefix|site_default).
		 * Useful to force URL-prefix rules when a language plugin is present but unreliable.
		 *
		 * @param string $fw Detected framework.
		 */
		$filtered  = (string) apply_filters( 'sseo_language_framework', $fw );
		$available = array( 'url_prefix', 'site_default' );
		if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_get_post_translations' ) ) {
			$available[] = 'polylang';
		}
		if ( has_filter( 'wpml_post_language_details' ) ) {
			$available[] = 'wpml';
		}
		return in_array( $filtered, $available, true ) ? $filtered : $fw;
	}

	/**
	 * Whether translation relationships can be verified.
	 *
	 * @return bool
	 */
	public static function has_translation_mapping() {
		return in_array( self::framework(), array( 'polylang', 'wpml' ), true );
	}

	/**
	 * Languages known to the framework (two-letter codes).
	 *
	 * @return string[]
	 */
	public static function languages() {
		$fw = self::framework();
		if ( 'polylang' === $fw && function_exists( 'pll_languages_list' ) ) {
			return array_map( array( __CLASS__, 'short' ), (array) pll_languages_list( array( 'fields' => 'slug' ) ) );
		}
		if ( 'wpml' === $fw ) {
			$langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
			return is_array( $langs ) ? array_map( array( __CLASS__, 'short' ), array_keys( $langs ) ) : array();
		}
		$out = array( Settings::get( 'default_locale', 'en' ) );
		foreach ( array_keys( (array) Settings::get( 'url_prefix_locales', array() ) ) as $code ) {
			$out[] = $code;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Locale of a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Permalink (used by URL-prefix rules).
	 * @return array{locale:string,source:string}
	 */
	public static function post_locale( $post_id, $url = '' ) {
		$fw = self::framework();
		if ( 'polylang' === $fw ) {
			$slug = pll_get_post_language( (int) $post_id, 'slug' );
			if ( is_string( $slug ) && '' !== $slug ) {
				return array(
					'locale' => self::short( $slug ),
					'source' => 'polylang',
				);
			}
		} elseif ( 'wpml' === $fw ) {
			$details = apply_filters( 'wpml_post_language_details', null, (int) $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return array(
					'locale' => self::short( $details['language_code'] ),
					'source' => 'wpml',
				);
			}
		}
		return self::url_or_default( $url );
	}

	/**
	 * Locale of a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $url      Term link.
	 * @return array{locale:string,source:string}
	 */
	public static function term_locale( $term_id, $taxonomy, $url = '' ) {
		$fw = self::framework();
		if ( 'polylang' === $fw && function_exists( 'pll_get_term_language' ) ) {
			$slug = pll_get_term_language( (int) $term_id, 'slug' );
			if ( is_string( $slug ) && '' !== $slug ) {
				return array(
					'locale' => self::short( $slug ),
					'source' => 'polylang',
				);
			}
		} elseif ( 'wpml' === $fw ) {
			$details = apply_filters(
				'wpml_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
				null,
				array(
					'element_id'   => (int) $term_id,
					'element_type' => (string) $taxonomy,
				)
			);
			if ( is_object( $details ) && ! empty( $details->language_code ) ) {
				return array(
					'locale' => self::short( $details->language_code ),
					'source' => 'wpml',
				);
			}
		}
		return self::url_or_default( $url );
	}

	/**
	 * Translations of a post: lang => post ID (including itself). Empty when unverifiable.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return array<string,int>
	 */
	public static function post_translations( $post_id, $post_type = 'post' ) {
		$fw  = self::framework();
		$out = array();
		if ( 'polylang' === $fw ) {
			foreach ( (array) pll_get_post_translations( (int) $post_id ) as $lang => $id ) {
				$out[ self::short( $lang ) ] = (int) $id;
			}
		} elseif ( 'wpml' === $fw ) {
			$type = 'post_' . $post_type;
			$trid = apply_filters( 'wpml_element_trid', null, (int) $post_id, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
			if ( $trid ) {
				$rows = apply_filters( 'wpml_get_element_translations', null, $trid, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
				foreach ( (array) $rows as $row ) {
					if ( is_object( $row ) && ! empty( $row->language_code ) && ! empty( $row->element_id ) ) {
						$out[ self::short( $row->language_code ) ] = (int) $row->element_id;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Stable translation group key for a translations map.
	 *
	 * @param array<string,int> $translations Map.
	 * @return string
	 */
	public static function group_key( array $translations ) {
		if ( count( $translations ) < 2 ) {
			return '';
		}
		$ids = array_values( $translations );
		sort( $ids );
		return self::framework() . ':' . implode( '-', $ids );
	}

	/**
	 * URL-prefix rule or site default.
	 *
	 * @param string $url URL.
	 * @return array{locale:string,source:string}
	 */
	private static function url_or_default( $url ) {
		$prefixes = (array) Settings::get( 'url_prefix_locales', array() );
		if ( '' !== $url && array() !== $prefixes ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '' !== $home && '/' !== $home && 0 === strpos( $path, rtrim( $home, '/' ) ) ) {
				$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
			}
			$path = strtolower( '/' . ltrim( $path, '/' ) );
			foreach ( $prefixes as $code => $prefix ) {
				if ( 0 === strpos( $path . ( '/' === substr( $path, -1 ) ? '' : '/' ), $prefix ) ) {
					return array(
						'locale' => $code,
						'source' => 'url_prefix_rule',
					);
				}
			}
		}
		return array(
			'locale' => (string) Settings::get( 'default_locale', 'en' ),
			'source' => 'site_default',
		);
	}

	/**
	 * Two-letter code from a locale or slug.
	 *
	 * @param string $code Locale (vi, vi_VN, en-us…).
	 * @return string
	 */
	public static function short( $code ) {
		$code = strtolower( substr( (string) $code, 0, 2 ) );
		return preg_match( '/^[a-z]{2}$/', $code ) ? $code : 'xx';
	}
}
