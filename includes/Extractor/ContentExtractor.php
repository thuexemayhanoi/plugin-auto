<?php
/**
 * Extracts structured, evidence-bearing signals from a document.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Extractor;

use SemanticSEO\Language\LanguageDetector;
use SemanticSEO\Language\TextNormalizer;
use SemanticSEO\Lexical\Tokenizer;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Pure (WordPress-independent) extractor so it can be unit-tested in isolation.
 */
final class ContentExtractor {

	const VERSION       = 'x2';
	const MINHASH_SIZE  = 64;
	const SHINGLE_WIDTH = 4;
	const CHUNK_WORDS   = 320;

	/**
	 * Extracts signals.
	 *
	 * @param array $doc {title, content, excerpt, description, url, slug}.
	 * @return array
	 */
	public static function extract( array $doc ) {
		$title       = isset( $doc['title'] ) ? (string) $doc['title'] : '';
		$content     = isset( $doc['content'] ) ? (string) $doc['content'] : '';
		$description = isset( $doc['description'] ) ? (string) $doc['description'] : '';
		$excerpt     = isset( $doc['excerpt'] ) ? (string) $doc['excerpt'] : '';
		$slug        = isset( $doc['slug'] ) ? (string) $doc['slug'] : '';

		$scan     = HtmlScanner::scan( $content );
		$text     = $scan['text'];
		$words    = Tokenizer::words( $text );
		$detected = LanguageDetector::detect( $title . "\n" . $text );

		$headings = array();
		foreach ( $scan['headings'] as $h ) {
			if ( '' !== $h['text'] ) {
				$headings[] = array(
					'level'  => $h['level'],
					'text'   => Util::truncate( $h['text'], 200 ),
					'offset' => $h['start'],
				);
			}
		}

		$links = array();
		foreach ( $scan['links'] as $l ) {
			if ( '' === $l['href'] || 0 === strpos( $l['href'], '#' ) || preg_match( '/^(mailto|tel|javascript|data):/i', $l['href'] ) ) {
				continue;
			}
			$links[] = array(
				'href'        => $l['href'],
				'anchor'      => Util::truncate( $l['anchor'], 200 ),
				'start'       => $l['start'],
				'length'      => max( 0, $l['end'] - $l['start'] ),
				'rel'         => $l['rel'],
				'fingerprint' => sha1( substr( $content, max( 0, $l['start'] - 40 ), ( $l['end'] - max( 0, $l['start'] - 40 ) ) + 40 ) ),
			);
		}

		$first_paragraph = '';
		foreach ( explode( "\n", $text ) as $line ) {
			if ( mb_strlen( trim( $line ), 'UTF-8' ) >= 40 ) {
				$first_paragraph = Util::truncate( trim( $line ), 400 );
				break;
			}
		}

		return array(
			'version'           => self::VERSION,
			'text'              => $text,
			'word_count'        => count( $words ),
			'detected_lang'     => $detected,
			'headings'          => $headings,
			'links'             => $links,
			'images_no_alt'     => count(
				array_filter(
					$scan['images'],
					static function ( $img ) {
						return ! $img['has_alt'];
					}
				)
			),
			'images'            => count( $scan['images'] ),
			'jsonld_in_content' => $scan['jsonld'],
			'first_paragraph'   => $first_paragraph,
			'entities'          => self::entities( $title, $headings, $text ),
			'attributes'        => self::attributes( $title . "\n" . $text ),
			'repetition'        => self::repetition( $words ),
			'minhash'           => self::minhash( $words ),
			'chunks'            => self::chunks( $title, $description, $excerpt, $headings, $scan, $content ),
			'slug'              => $slug,
		);
	}

	/**
	 * Pattern-based candidate entities (capitalized multi-syllable names) with occurrence counts.
	 * These are labelled "pattern" — not verified against any knowledge base.
	 *
	 * @param string $title    Title.
	 * @param array  $headings Headings.
	 * @param string $text     Plain text.
	 * @return array<int,array{text:string,count:int,in_title:bool}>
	 */
	public static function entities( $title, array $headings, $text ) {
		$sources = $title . "\n" . implode( "\n", array_column( $headings, 'text' ) ) . "\n" . $text;
		$counts  = array();
		if ( preg_match_all( '/(?<![\p{L}\p{N}])(\p{Lu}[\p{Ll}\p{N}]*(?:\s+\p{Lu}[\p{Ll}\p{N}]*){1,3})(?![\p{L}\p{N}])/u', TextNormalizer::nfc( $sources ), $m ) ) {
			foreach ( $m[1] as $hit ) {
				$hit = trim( preg_replace( '/\s+/u', ' ', $hit ) );
				if ( mb_strlen( $hit, 'UTF-8' ) < 4 ) {
					continue;
				}
				$counts[ $hit ] = isset( $counts[ $hit ] ) ? $counts[ $hit ] + 1 : 1;
			}
		}
		// Models / codes such as "50cc", "Air Blade 125", "Wave Alpha".
		if ( preg_match_all( '/\b(\d{2,4}\s?cc)\b/iu', $sources, $m2 ) ) {
			foreach ( $m2[1] as $hit ) {
				$hit            = strtolower( str_replace( ' ', '', $hit ) );
				$counts[ $hit ] = isset( $counts[ $hit ] ) ? $counts[ $hit ] + 1 : 1;
			}
		}
		$title_n = TextNormalizer::normalize( $title );
		$out     = array();
		foreach ( $counts as $name => $count ) {
			$in_title = false !== mb_strpos( $title_n, TextNormalizer::normalize( $name ), 0, 'UTF-8' );
			if ( $count >= 2 || $in_title ) {
				$out[] = array(
					'text'     => $name,
					'count'    => $count,
					'in_title' => $in_title,
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return array_slice( $out, 0, 15 );
	}

	/**
	 * Numeric attributes with units (prices, engine sizes, durations).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function attributes( $text ) {
		$out = array();
		if ( preg_match_all( '/(\d[\d\.,]*)\s?(cc|km|đ|vnđ|vnd|usd|\$|k|nghìn|triệu|ngày|tháng|năm|giờ|days?|months?|years?|hours?)(?![\p{L}])/iu', (string) $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$out[ mb_strtolower( $hit[1] . ' ' . $hit[2], 'UTF-8' ) ] = true;
				if ( count( $out ) >= 30 ) {
					break;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Most repeated trigram and its density (repetitive phrasing indicator).
	 *
	 * @param string[] $words Tokens.
	 * @return array{phrase:string,count:int,density:float}
	 */
	public static function repetition( array $words ) {
		$n      = count( $words );
		$counts = array();
		for ( $i = 0; $i + 2 < $n && $i < 6000; $i++ ) {
			$tri            = $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ];
			$counts[ $tri ] = isset( $counts[ $tri ] ) ? $counts[ $tri ] + 1 : 1;
		}
		if ( array() === $counts ) {
			return array(
				'phrase'  => '',
				'count'   => 0,
				'density' => 0.0,
			);
		}
		arsort( $counts );
		$phrase = (string) key( $counts );
		$count  = (int) current( $counts );
		return array(
			'phrase'  => $phrase,
			'count'   => $count,
			'density' => $n > 0 ? round( ( $count * 3 ) / $n, 4 ) : 0.0,
		);
	}

	/**
	 * MinHash signature over word shingles (crc32 with seeded prefixes; portable on 32/64-bit).
	 *
	 * @param string[] $words Tokens.
	 * @return int[]
	 */
	public static function minhash( array $words ) {
		$words = array_slice( $words, 0, 3000 );
		$n     = count( $words );
		$sig   = array_fill( 0, self::MINHASH_SIZE, PHP_INT_MAX );
		if ( $n < self::SHINGLE_WIDTH ) {
			if ( 0 === $n ) {
				return array();
			}
			$shingles = array( implode( ' ', $words ) );
		} else {
			$shingles = array();
			for ( $i = 0; $i + self::SHINGLE_WIDTH <= $n; $i++ ) {
				$shingles[ implode( ' ', array_slice( $words, $i, self::SHINGLE_WIDTH ) ) ] = true;
			}
			$shingles = array_keys( $shingles );
		}
		foreach ( $shingles as $sh ) {
			for ( $k = 0; $k < self::MINHASH_SIZE; $k++ ) {
				$h = crc32( $k . '|' . $sh );
				if ( $h < $sig[ $k ] ) {
					$sig[ $k ] = $h;
				}
			}
		}
		return $sig;
	}

	/**
	 * Estimated Jaccard similarity between two MinHash signatures.
	 *
	 * @param int[] $a Signature.
	 * @param int[] $b Signature.
	 * @return float
	 */
	public static function minhash_similarity( array $a, array $b ) {
		$n = min( count( $a ), count( $b ) );
		if ( 0 === $n ) {
			return 0.0;
		}
		$same = 0;
		for ( $i = 0; $i < $n; $i++ ) {
			if ( (int) $a[ $i ] === (int) $b[ $i ] ) {
				++$same;
			}
		}
		return (float) ( $same / $n );
	}

	/**
	 * Heading-aware chunks with byte offsets. Chunk 0 is the "summary" representation used for
	 * document-level embeddings (title, description, headings, opening text).
	 *
	 * @param string $title       Title.
	 * @param string $description Meta description.
	 * @param string $excerpt     Excerpt.
	 * @param array  $headings    Headings.
	 * @param array  $scan        HtmlScanner output.
	 * @param string $content     Raw content.
	 * @return array<int,array{kind:string,heading:string,start:int,end:int,text:string}>
	 */
	public static function chunks( $title, $description, $excerpt, array $headings, array $scan, $content ) {
		$h_texts = array();
		foreach ( $headings as $h ) {
			if ( $h['level'] <= 3 ) {
				$h_texts[] = $h['text'];
			}
		}
		$opening = implode( ' ', array_slice( preg_split( '/\s+/u', $scan['text'] ), 0, 120 ) );
		$summary = trim( implode( "\n", array_filter( array( $title, '' !== $description ? $description : $excerpt, implode( '; ', array_slice( $h_texts, 0, 12 ) ), $opening ) ) ) );

		$chunks    = array(
			array(
				'kind'    => 'summary',
				'heading' => $title,
				'start'   => 0,
				'end'     => strlen( (string) $content ),
				'text'    => Util::truncate( $summary, 2400 ),
			),
		);
		$sections  = array();
		$current   = array(
			'heading' => $title,
			'start'   => 0,
			'parts'   => array(),
			'end'     => 0,
		);
		$h_offsets = array();
		foreach ( $scan['headings'] as $h ) {
			$h_offsets[ $h['start'] ] = $h['text'];
		}
		ksort( $h_offsets );
		$h_keys  = array_keys( $h_offsets );
		$h_count = count( $h_keys );
		$h_idx   = 0;
		foreach ( $scan['segments'] as $seg ) {
			while ( $h_idx < $h_count && $h_keys[ $h_idx ] <= $seg['start'] ) {
				if ( array() !== $current['parts'] ) {
					$sections[] = $current;
				}
				$current = array(
					'heading' => $h_offsets[ $h_keys[ $h_idx ] ],
					'start'   => $h_keys[ $h_idx ],
					'parts'   => array(),
					'end'     => $h_keys[ $h_idx ],
				);
				++$h_idx;
			}
			if ( preg_match( '/^h[1-6]$/', $seg['context'] ) ) {
				continue;
			}
			$current['parts'][] = html_entity_decode( $seg['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$current['end']     = $seg['end'];
		}
		if ( array() !== $current['parts'] ) {
			$sections[] = $current;
		}
		foreach ( $sections as $sec ) {
			$text  = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $sec['parts'] ) ) );
			$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
			if ( count( $words ) < 8 ) {
				continue;
			}
			foreach ( array_chunk( $words, self::CHUNK_WORDS ) as $part ) {
				$chunks[] = array(
					'kind'    => 'section',
					'heading' => Util::truncate( (string) $sec['heading'], 200 ),
					'start'   => (int) $sec['start'],
					'end'     => (int) $sec['end'],
					'text'    => implode( ' ', $part ),
				);
				if ( count( $chunks ) >= 41 ) {
					break 2;
				}
			}
		}
		return $chunks;
	}
}
