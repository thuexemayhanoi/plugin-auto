<?php
/**
 * Accent-aware tokenizer for Vietnamese and English.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Lexical;

use SemanticSEO\Language\Stopwords;
use SemanticSEO\Language\TextNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Vietnamese is written as space-separated syllables, so words are recovered through syllable
 * bigrams ("xe máy", "hà nội") rather than naive whitespace splitting. Each document produces:
 *  - accent-preserving syllable/word unigrams (stopwords removed),
 *  - accent-folded unigrams prefixed with "~" (match unaccented queries, lower weight),
 *  - bigrams of adjacent tokens that do not contain stopwords, joined with "_".
 */
final class Tokenizer {

	const VERSION = 't2';

	const FOLDED_WEIGHT = 0.4;
	const BIGRAM_WEIGHT = 1.2;

	/**
	 * Splits text into normalized tokens (NFC, lowercase, letters/digits only).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function words( $text ) {
		$text = TextNormalizer::normalize( (string) $text );
		$out  = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * Light English plural folding (Vietnamese has no inflection).
	 *
	 * @param string $w Token.
	 * @return string
	 */
	public static function stem( $w ) {
		if ( preg_match( '/^[a-z]{4,}$/', $w ) ) {
			if ( 'ies' === substr( $w, -3 ) ) {
				return substr( $w, 0, -3 ) . 'y';
			}
			if ( 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) && 'us' !== substr( $w, -2 ) && 'is' !== substr( $w, -2 ) ) {
				return substr( $w, 0, -1 );
			}
		}
		return $w;
	}

	/**
	 * Weighted term frequencies for indexing or querying.
	 *
	 * @param string $text   Text.
	 * @param float  $weight Field weight multiplier.
	 * @param array  $tf     Accumulator.
	 * @param string $lang   Document language (vi|en|'' unknown) for stopword selection.
	 * @return array<string,float>
	 */
	public static function term_frequencies( $text, $weight = 1.0, array $tf = array(), $lang = '' ) {
		$words = self::words( $text );
		$prev  = null;
		foreach ( $words as $raw ) {
			$w    = self::stem( $raw );
			$stop = Stopwords::is_stopword( $raw, $lang ) || ( mb_strlen( $w, 'UTF-8' ) < 2 && ! ctype_digit( $w ) );
			if ( ! $stop ) {
				$tf[ $w ] = ( isset( $tf[ $w ] ) ? $tf[ $w ] : 0.0 ) + $weight;
				$folded   = TextNormalizer::fold( $w );
				if ( $folded !== $w ) {
					$key        = '~' . $folded;
					$tf[ $key ] = ( isset( $tf[ $key ] ) ? $tf[ $key ] : 0.0 ) + $weight * self::FOLDED_WEIGHT;
				}
				if ( null !== $prev ) {
					$bi        = $prev . '_' . $w;
					$tf[ $bi ] = ( isset( $tf[ $bi ] ) ? $tf[ $bi ] : 0.0 ) + $weight * self::BIGRAM_WEIGHT;
				}
				$prev = $w;
			} else {
				$prev = null;
			}
		}
		return $tf;
	}

	/**
	 * Query terms (same pipeline, unit weights). Unaccented queries match "~" folded terms.
	 *
	 * @param string $query Query.
	 * @param string $lang  Query language if known.
	 * @return array<string,float>
	 */
	public static function query_terms( $query, $lang = '' ) {
		$tf  = self::term_frequencies( $query, 1.0, array(), $lang );
		$out = array();
		foreach ( $tf as $term => $w ) {
			$term         = (string) $term;
			$out[ $term ] = $w;
			// An unaccented ASCII query token should also hit folded postings.
			if ( '~' !== $term[0] && false === strpos( $term, '_' ) && ! preg_match( '/[^\x00-\x7F]/', $term ) ) {
				$out[ '~' . $term ] = $w;
			}
		}
		return $out;
	}

	/**
	 * Content tokens for shingling / overlap measures (no stopword removal, no folding).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max tokens.
	 * @return string[]
	 */
	public static function shingle_tokens( $text, $max = 3000 ) {
		return array_slice( self::words( $text ), 0, $max );
	}

	/**
	 * Set of significant title tokens (stopwords removed) for overlap measures.
	 *
	 * @param string $text Text.
	 * @param string $lang Language.
	 * @return string[]
	 */
	public static function significant_set( $text, $lang = '' ) {
		$set = array();
		foreach ( self::words( $text ) as $w ) {
			if ( ! Stopwords::is_stopword( $w, $lang ) ) {
				$set[ self::stem( $w ) ] = true;
			}
		}
		return array_keys( $set );
	}
}
