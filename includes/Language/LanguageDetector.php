<?php
/**
 * Lightweight VI/EN language identification with explicit evidence.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristic detector (diacritic ratio + function-word hits). It is a QA signal only; the
 * authoritative locale always comes from the language plugin or configured URL rules.
 */
final class LanguageDetector {

	const VI_MARKERS = array( 'của', 'và', 'là', 'những', 'các', 'được', 'cho', 'với', 'này', 'có', 'không', 'một', 'trong', 'người', 'thuê', 'xe', 'giá', 'ngày', 'tháng' );
	const EN_MARKERS = array( 'the', 'and', 'of', 'to', 'in', 'is', 'for', 'with', 'that', 'this', 'are', 'on', 'you', 'your', 'rent', 'rental', 'price', 'day', 'month' );

	/**
	 * Detects the language of a text.
	 *
	 * @param string $text Plain text.
	 * @return array{lang:string,confidence:string,evidence:array}
	 */
	public static function detect( $text ) {
		$text  = TextNormalizer::normalize( mb_substr( (string) $text, 0, 20000, 'UTF-8' ) );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total = count( $words );
		if ( $total < 5 ) {
			return array(
				'lang'       => 'unknown',
				'confidence' => 'low',
				'evidence'   => array( 'words' => $total ),
			);
		}
		$vi_set   = array_fill_keys( self::VI_MARKERS, true );
		$en_set   = array_fill_keys( self::EN_MARKERS, true );
		$vi_hits  = 0;
		$en_hits  = 0;
		$vi_marks = 0;
		foreach ( $words as $w ) {
			if ( isset( $vi_set[ $w ] ) ) {
				++$vi_hits;
			}
			if ( isset( $en_set[ $w ] ) ) {
				++$en_hits;
			}
			if ( TextNormalizer::has_vietnamese_marks( $w ) ) {
				++$vi_marks;
			}
		}
		$vi_score = ( $vi_marks / $total ) + ( $vi_hits / $total );
		$en_score = ( $en_hits / $total ) * 1.6;
		$evidence = array(
			'words'            => $total,
			'vi_marked_words'  => $vi_marks,
			'vi_function_hits' => $vi_hits,
			'en_function_hits' => $en_hits,
			'vi_score'         => round( $vi_score, 3 ),
			'en_score'         => round( $en_score, 3 ),
		);
		if ( $vi_score < 0.05 && $en_score < 0.05 ) {
			$lang = 'unknown';
		} elseif ( $vi_score >= 0.15 && $en_score >= 0.15 && min( $vi_score, $en_score ) / max( $vi_score, $en_score ) > 0.6 ) {
			$lang = 'mixed';
		} else {
			$lang = $vi_score >= $en_score ? 'vi' : 'en';
		}
		$margin     = abs( $vi_score - $en_score );
		$confidence = 'low';
		if ( 'unknown' !== $lang && 'mixed' !== $lang ) {
			if ( $margin >= 0.25 && $total >= 30 ) {
				$confidence = 'high';
			} elseif ( $margin >= 0.10 ) {
				$confidence = 'medium';
			}
		}
		return array(
			'lang'       => $lang,
			'confidence' => $confidence,
			'evidence'   => $evidence,
		);
	}
}
