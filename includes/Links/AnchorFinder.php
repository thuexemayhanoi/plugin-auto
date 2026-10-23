<?php
/**
 * Finds natural anchor text occurrences in a source document for a given target.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Links;

use SemanticSEO\Extractor\HtmlScanner;
use SemanticSEO\Language\Stopwords;
use SemanticSEO\Language\TextNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Only unprotected text (not headings, existing links, shortcodes, code, captions…) is searched.
 * Matches respect word boundaries and are recorded with exact byte offsets and a fingerprint so
 * a later diff can prove the content has not changed.
 */
final class AnchorFinder {

	const GENERIC = array( 'tại đây', 'xem thêm', 'click here', 'here', 'read more', 'this page', 'bài viết này', 'link này' );

	/**
	 * Candidate phrases for a target, most specific first.
	 *
	 * @param array $target Target profile (title, focus_keyword, signals.top_terms, signals.entities, locale).
	 * @return array<int,array{phrase:string,source:string,quality:float}>
	 */
	public static function phrases( array $target ) {
		$lang = in_array( $target['locale'], array( 'vi', 'en' ), true ) ? $target['locale'] : '';
		$out  = array();
		$add  = static function ( $phrase, $source, $quality ) use ( &$out ) {
			$phrase = trim( preg_replace( '/\s+/u', ' ', TextNormalizer::nfc( (string) $phrase ) ) );
			$key    = TextNormalizer::normalize( $phrase );
			if ( mb_strlen( $phrase, 'UTF-8' ) < 4 || isset( $out[ $key ] ) || in_array( $key, self::GENERIC, true ) ) {
				return;
			}
			$out[ $key ] = array(
				'phrase'  => $phrase,
				'source'  => $source,
				'quality' => $quality,
			);
		};
		if ( '' !== (string) $target['focus_keyword'] ) {
			$add( $target['focus_keyword'], 'focus_keyword', 1.0 );
		}
		$title_words = preg_split( '/\s+/u', trim( preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', TextNormalizer::nfc( (string) $target['title'] ) ) ), -1, PREG_SPLIT_NO_EMPTY );
		$n_words     = count( $title_words );
		if ( $n_words >= 2 && $n_words <= 8 ) {
			$add( implode( ' ', $title_words ), 'title', 0.9 );
		}
		// Title n-grams (longest first) that start and end with content words.
		for ( $len = min( 5, $n_words - 1 ); $len >= 2; $len-- ) {
			for ( $i = 0; $i + $len <= $n_words; $i++ ) {
				$gram  = array_slice( $title_words, $i, $len );
				$first = TextNormalizer::normalize( $gram[0] );
				$last  = TextNormalizer::normalize( $gram[ $len - 1 ] );
				if ( Stopwords::is_stopword( $first, $lang ) || Stopwords::is_stopword( $last, $lang ) ) {
					continue;
				}
				$content = 0;
				foreach ( $gram as $g ) {
					if ( ! Stopwords::is_stopword( TextNormalizer::normalize( $g ), $lang ) ) {
						++$content;
					}
				}
				if ( $content >= 2 ) {
					$add( implode( ' ', $gram ), 'title_ngram', $len >= 3 ? 0.8 : 0.6 );
				}
			}
		}
		$signals = isset( $target['signals'] ) ? $target['signals'] : array();
		if ( ! empty( $signals['entities'] ) ) {
			foreach ( array_slice( (array) $signals['entities'], 0, 5 ) as $e ) {
				if ( ! empty( $e['in_title'] ) && false !== strpos( (string) $e['text'], ' ' ) ) {
					$add( $e['text'], 'title_entity', 0.7 );
				}
			}
		}
		if ( ! empty( $signals['top_terms'] ) ) {
			foreach ( array_slice( array_keys( (array) $signals['top_terms'] ), 0, 8 ) as $term ) {
				if ( false !== strpos( (string) $term, '_' ) ) {
					$add( str_replace( '_', ' ', (string) $term ), 'top_bigram', 0.5 );
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Finds the best anchor occurrence in the source content.
	 *
	 * @param string $content Source post_content (raw).
	 * @param array  $phrases Phrases from phrases().
	 * @return array|null {text, phrase_source, quality, offset, length, snippet, fingerprint, context}
	 */
	public static function find( $content, array $phrases ) {
		$content = (string) $content;
		if ( '' === $content || array() === $phrases ) {
			return null;
		}
		$scan = HtmlScanner::scan( $content );
		foreach ( $phrases as $p ) {
			$needle = TextNormalizer::normalize( $p['phrase'] );
			foreach ( $scan['segments'] as $seg ) {
				if ( $seg['protected'] || ! in_array( $seg['context'], array( 'p', 'li', 'td', 'dd', 'blockquote', 'div', 'span', 'strong', 'em', 'b', 'i', '' ), true ) ) {
					continue;
				}
				$raw = $seg['raw'];
				// Entities would shift offsets; only plain-text segments are eligible.
				if ( false !== strpos( $raw, '&' ) && preg_match( '/&[#a-z0-9]+;/i', $raw ) ) {
					continue;
				}
				$hay = TextNormalizer::nfc( $raw );
				if ( $hay !== $raw ) {
					continue; // Non-NFC source text: byte offsets would not map back.
				}
				$pos = self::find_word_bounded( $hay, $needle );
				if ( null === $pos ) {
					continue;
				}
				$bytes_before = strlen( mb_substr( $hay, 0, $pos, 'UTF-8' ) );
				$match        = mb_substr( $hay, $pos, mb_strlen( $needle, 'UTF-8' ), 'UTF-8' );
				$offset       = $seg['start'] + $bytes_before;
				$length       = strlen( $match );
				if ( substr( $content, $offset, $length ) !== $match ) {
					continue; // Defensive: never return an offset that does not map exactly.
				}
				return array(
					'text'          => $match,
					'phrase_source' => $p['source'],
					'quality'       => (float) $p['quality'],
					'offset'        => $offset,
					'length'        => $length,
					'snippet'       => self::snippet( $content, $offset, $length ),
					'fingerprint'   => self::fingerprint( $content, $offset, $length ),
					'context'       => $seg['context'],
				);
			}
		}
		return null;
	}

	/**
	 * Fingerprint of an anchor location: 40 bytes before + match + 40 bytes after.
	 *
	 * @param string $content Content.
	 * @param int    $offset  Offset.
	 * @param int    $length  Length.
	 * @return string
	 */
	public static function fingerprint( $content, $offset, $length ) {
		$start = max( 0, $offset - 40 );
		return sha1( substr( $content, $start, ( $offset - $start ) + $length + 40 ) );
	}

	/**
	 * Human-readable snippet around a match.
	 *
	 * @param string $content Content.
	 * @param int    $offset  Offset.
	 * @param int    $length  Length.
	 * @return string
	 */
	public static function snippet( $content, $offset, $length ) {
		$before = self::strip_tags( substr( $content, max( 0, $offset - 160 ), min( 160, $offset ) ) );
		$after  = self::strip_tags( substr( $content, $offset + $length, 160 ) );
		$before = mb_substr( preg_replace( '/^[\x80-\xBF]+/', '', $before ), -60, null, 'UTF-8' );
		$after  = mb_substr( preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $after ), 0, 60, 'UTF-8' );
		return trim( '…' . $before . '[' . substr( $content, $offset, $length ) . ']' . $after . '…' );
	}

	/**
	 * Case-insensitive, word-bounded search on a normalized needle.
	 *
	 * @param string $hay    Haystack (NFC).
	 * @param string $needle Lowercase needle (NFC).
	 * @return int|null Character position.
	 */
	private static function find_word_bounded( $hay, $needle ) {
		$lower = mb_strtolower( $hay, 'UTF-8' );
		$from  = 0;
		$nlen  = mb_strlen( $needle, 'UTF-8' );
		while ( false !== ( $pos = mb_strpos( $lower, $needle, $from, 'UTF-8' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$before = $pos > 0 ? mb_substr( $lower, $pos - 1, 1, 'UTF-8' ) : ' ';
			$after  = mb_substr( $lower, $pos + $nlen, 1, 'UTF-8' );
			if ( ! preg_match( '/[\p{L}\p{N}]/u', $before ) && ( '' === $after || ! preg_match( '/[\p{L}\p{N}]/u', $after ) ) ) {
				return $pos;
			}
			$from = $pos + 1;
		}
		return null;
	}

	/**
	 * Strips tags from a fragment (works without WordPress for unit tests).
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function strip_tags( $html ) {
		$html = preg_replace( '/<[^>]*>?/', ' ', (string) $html );
		$html = preg_replace( '/^[^<]*>/', ' ', (string) $html );
		return (string) preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
