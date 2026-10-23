<?php
/**
 * TEST DOUBLE of the public Polylang API (pll_* functions) — test environment only.
 *
 * It implements the documented function signatures used by LanguageResolver so the integration
 * suite can exercise the Polylang code path deterministically. It is never shipped in the ZIP.
 * The real-plugin path is exercised separately in the end-to-end environment when available.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration {

	/**
	 * Storage for the double.
	 */
	final class PolylangDouble {

		/**
		 * Post languages.
		 *
		 * @var array<int,string>
		 */
		public static $post_lang = array();

		/**
		 * Translation groups.
		 *
		 * @var array<int,string>
		 */
		public static $post_group = array();

		/**
		 * Term languages.
		 *
		 * @var array<int,string>
		 */
		public static $term_lang = array();

		/**
		 * Resets state.
		 *
		 * @return void
		 */
		public static function reset() {
			self::$post_lang  = array();
			self::$post_group = array();
			self::$term_lang  = array();
		}

		/**
		 * Sets a post language and translation group.
		 *
		 * @param int    $id    Post ID.
		 * @param string $lang  Language slug.
		 * @param string $group Group key.
		 * @return void
		 */
		public static function set_post_language( $id, $lang, $group = '' ) {
			self::$post_lang[ $id ]  = $lang;
			self::$post_group[ $id ] = $group;
		}

		/**
		 * Sets a term language.
		 *
		 * @param int    $id   Term ID.
		 * @param string $lang Language.
		 * @return void
		 */
		public static function set_term_language( $id, $lang ) {
			self::$term_lang[ $id ] = $lang;
		}
	}
}

namespace {

	use SemanticSEO\Tests\Integration\PolylangDouble;

	if ( ! function_exists( 'pll_get_post_language' ) ) {
		/**
		 * Test double of pll_get_post_language().
		 *
		 * @param int    $post_id Post ID.
		 * @param string $field   Field.
		 * @return string|false
		 */
		function pll_get_post_language( $post_id, $field = 'slug' ) {
			unset( $field );
			return isset( PolylangDouble::$post_lang[ $post_id ] ) ? PolylangDouble::$post_lang[ $post_id ] : false;
		}

		/**
		 * Test double of pll_get_post_translations().
		 *
		 * @param int $post_id Post ID.
		 * @return array<string,int>
		 */
		function pll_get_post_translations( $post_id ) {
			$group = isset( PolylangDouble::$post_group[ $post_id ] ) ? PolylangDouble::$post_group[ $post_id ] : '';
			if ( '' === $group ) {
				return array();
			}
			$out = array();
			foreach ( PolylangDouble::$post_group as $id => $g ) {
				if ( $g === $group && isset( PolylangDouble::$post_lang[ $id ] ) && 'trash' !== get_post_status( $id ) ) {
					$out[ PolylangDouble::$post_lang[ $id ] ] = (int) $id;
				}
			}
			return $out;
		}

		/**
		 * Test double of pll_get_term_language().
		 *
		 * @param int    $term_id Term ID.
		 * @param string $field   Field.
		 * @return string|false
		 */
		function pll_get_term_language( $term_id, $field = 'slug' ) {
			unset( $field );
			return isset( PolylangDouble::$term_lang[ $term_id ] ) ? PolylangDouble::$term_lang[ $term_id ] : false;
		}

		/**
		 * Test double of pll_languages_list().
		 *
		 * @param array $args Args.
		 * @return string[]
		 */
		function pll_languages_list( $args = array() ) {
			unset( $args );
			return array( 'vi', 'en' );
		}
	}
}
