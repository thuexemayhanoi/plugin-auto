<?php
/**
 * Unicode normalization for Vietnamese/English text: NFC, lowercase, accent folding.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Accent-aware normalization. Uses ext-intl when present and a tested Vietnamese-specific
 * fallback otherwise (shared hosting without intl).
 */
final class TextNormalizer {

	const VERSION = 'n1';

	/**
	 * Precomposed lowercase rows: base => grave, acute, hook above, tilde, dot below.
	 */
	const ROWS = array(
		'a' => 'àáảãạ',
		'ă' => 'ằắẳẵặ',
		'â' => 'ầấẩẫậ',
		'e' => 'èéẻẽẹ',
		'ê' => 'ềếểễệ',
		'i' => 'ìíỉĩị',
		'o' => 'òóỏõọ',
		'ô' => 'ồốổỗộ',
		'ơ' => 'ờớởỡợ',
		'u' => 'ùúủũụ',
		'ư' => 'ừứửữự',
		'y' => 'ỳýỷỹỵ',
	);

	/**
	 * Cached maps.
	 *
	 * @var array|null
	 */
	private static $fold_map = null;

	/**
	 * Cached NFD→NFC fallback map.
	 *
	 * @var array|null
	 */
	private static $compose_map = null;

	/**
	 * Whether the intl Normalizer is used (diagnostics).
	 *
	 * @return bool
	 */
	public static function has_intl() {
		return class_exists( '\Normalizer' );
	}

	/**
	 * Canonical composition (NFC).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function nfc( $text ) {
		$text = (string) $text;
		if ( '' === $text || ! preg_match( '/[\x{0300}-\x{036F}]/u', $text ) ) {
			return $text;
		}
		if ( self::has_intl() ) {
			$out = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		return strtr( $text, self::compose_map() );
	}

	/**
	 * NFC + lowercase + whitespace collapse.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = self::nfc( $text );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Removes Vietnamese (and common Latin) diacritics; đ → d.
	 *
	 * @param string $text Text (any case).
	 * @return string
	 */
	public static function fold( $text ) {
		$text = strtr( self::nfc( $text ), self::fold_map() );
		if ( function_exists( 'remove_accents' ) && preg_match( '/[^\x00-\x7F]/', $text ) ) {
			$text = remove_accents( $text );
		}
		return $text;
	}

	/**
	 * Whether a string contains Vietnamese-specific letters.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_vietnamese_marks( $text ) {
		return (bool) preg_match( '/[ăâđêôơưĂÂĐÊÔƠƯ]|[\x{1EA0}-\x{1EF9}]/u', (string) $text );
	}

	/**
	 * Builds the folding map.
	 *
	 * @return array<string,string>
	 */
	private static function fold_map() {
		if ( null !== self::$fold_map ) {
			return self::$fold_map;
		}
		$map  = array(
			'đ' => 'd',
			'Đ' => 'D',
		);
		$base = array(
			'a' => 'a',
			'ă' => 'a',
			'â' => 'a',
			'e' => 'e',
			'ê' => 'e',
			'i' => 'i',
			'o' => 'o',
			'ô' => 'o',
			'ơ' => 'o',
			'u' => 'u',
			'ư' => 'u',
			'y' => 'y',
		);
		foreach ( self::ROWS as $row_base => $row ) {
			$plain = $base[ $row_base ];
			if ( $row_base !== $plain ) {
				$map[ $row_base ]                           = $plain;
				$map[ mb_strtoupper( $row_base, 'UTF-8' ) ] = strtoupper( $plain );
			}
			foreach ( self::chars( $row ) as $ch ) {
				$map[ $ch ]                           = $plain;
				$map[ mb_strtoupper( $ch, 'UTF-8' ) ] = strtoupper( $plain );
			}
		}
		self::$fold_map = $map;
		return $map;
	}

	/**
	 * Builds the decomposed → precomposed fallback map (both mark orders accepted).
	 *
	 * @return array<string,string>
	 */
	private static function compose_map() {
		if ( null !== self::$compose_map ) {
			return self::$compose_map;
		}
		$tones     = array( "\u{0300}", "\u{0301}", "\u{0309}", "\u{0303}", "\u{0323}" );
		$modifiers = array(
			'a' => array( 'a', '' ),
			'ă' => array( 'a', "\u{0306}" ),
			'â' => array( 'a', "\u{0302}" ),
			'e' => array( 'e', '' ),
			'ê' => array( 'e', "\u{0302}" ),
			'i' => array( 'i', '' ),
			'o' => array( 'o', '' ),
			'ô' => array( 'o', "\u{0302}" ),
			'ơ' => array( 'o', "\u{031B}" ),
			'u' => array( 'u', '' ),
			'ư' => array( 'u', "\u{031B}" ),
			'y' => array( 'y', '' ),
		);
		$map       = array();
		foreach ( self::ROWS as $row_base => $row ) {
			list( $letter, $mod ) = $modifiers[ $row_base ];
			foreach ( array( false, true ) as $upper ) {
				$l    = $upper ? strtoupper( $letter ) : $letter;
				$conv = function ( $s ) use ( $upper ) {
					return $upper ? mb_strtoupper( $s, 'UTF-8' ) : $s;
				};
				if ( '' !== $mod ) {
					$map[ $l . $mod ] = $conv( $row_base );
				}
				foreach ( self::chars( $row ) as $i => $ch ) {
					$tone                     = $tones[ $i ];
					$map[ $l . $mod . $tone ] = $conv( $ch );
					if ( '' !== $mod ) {
						$map[ $l . $tone . $mod ] = $conv( $ch );
					}
				}
			}
		}
		$map[ 'd' . "\u{0335}" ] = 'đ';
		self::$compose_map       = $map;
		return $map;
	}

	/**
	 * Splits a UTF-8 string into characters.
	 *
	 * @param string $s String.
	 * @return string[]
	 */
	private static function chars( $s ) {
		return preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
	}
}
