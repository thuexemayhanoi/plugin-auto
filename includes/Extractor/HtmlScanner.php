<?php
/**
 * Offset-preserving HTML scanner for post_content.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Extractor;

defined( 'ABSPATH' ) || exit;

/**
 * Walks raw post_content (classic HTML or block markup) and returns text segments with their
 * exact byte offsets plus the element context, links and headings. It never rewrites content,
 * so offsets can be used for exact, verifiable diffs.
 */
final class HtmlScanner {

	/**
	 * Elements whose text is never a candidate for link insertion.
	 */
	const PROTECTED_TAGS = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'button', 'textarea', 'select', 'option', 'label', 'figcaption', 'summary', 'title', 'svg', 'math', 'iframe', 'noscript', 'kbd', 'samp' );

	const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	const BLOCK_TAGS = array( 'p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'tr', 'td', 'th', 'section', 'article', 'figure', 'br', 'hr', 'dd', 'dt', 'header', 'footer', 'details', 'summary' );

	/**
	 * Scans content.
	 *
	 * @param string $html Raw post_content.
	 * @return array{segments:array,links:array,headings:array,images:array,jsonld:int,text:string}
	 */
	public static function scan( $html ) {
		$html     = (string) $html;
		$len      = strlen( $html );
		$segments = array();
		$links    = array();
		$headings = array();
		$images   = array();
		$jsonld   = 0;
		$stack    = array();
		$open_a   = null;
		$open_h   = null;
		$i        = 0;
		$text_buf = array();

		$masks = self::shortcode_ranges( $html );

		while ( $i < $len ) {
			$lt = strpos( $html, '<', $i );
			$end_text = false === $lt ? $len : $lt;
			if ( $end_text > $i ) {
				self::add_text( $html, $i, $end_text, $stack, $masks, $segments, $open_a, $open_h, $text_buf );
			}
			if ( false === $lt ) {
				break;
			}
			// Comment (includes Gutenberg block delimiters).
			if ( '<!--' === substr( $html, $lt, 4 ) ) {
				$close = strpos( $html, '-->', $lt + 4 );
				$i     = false === $close ? $len : $close + 3;
				continue;
			}
			if ( preg_match( '/\G<(\/?)([a-zA-Z][a-zA-Z0-9\-]*)/A', $html, $m, 0, $lt ) !== 1 ) {
				// A literal "<" in text.
				self::add_text( $html, $lt, $lt + 1, $stack, $masks, $segments, $open_a, $open_h, $text_buf );
				$i = $lt + 1;
				continue;
			}
			$gt = self::tag_end( $html, $lt );
			if ( false === $gt ) {
				break;
			}
			$tag_src = substr( $html, $lt, $gt - $lt + 1 );
			$closing = '/' === $m[1];
			$name    = strtolower( $m[2] );
			$i       = $gt + 1;

			if ( in_array( $name, self::BLOCK_TAGS, true ) ) {
				$text_buf[] = "\n";
			}

			if ( $closing ) {
				for ( $s = count( $stack ) - 1; $s >= 0; $s-- ) {
					if ( $stack[ $s ] === $name ) {
						array_splice( $stack, $s );
						break;
					}
				}
				if ( 'a' === $name && null !== $open_a ) {
					$open_a['end']    = $lt + 4;
					$open_a['anchor'] = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $open_a['anchor'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
					$links[]          = $open_a;
					$open_a           = null;
				}
				if ( null !== $open_h && $name === $open_h['tag'] ) {
					$open_h['text'] = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $open_h['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
					$open_h['end']  = $gt + 1;
					$headings[]     = $open_h;
					$open_h         = null;
				}
				continue;
			}

			$attrs = self::attributes( $tag_src );
			if ( 'script' === $name || 'style' === $name ) {
				if ( 'script' === $name && isset( $attrs['type'] ) && false !== stripos( $attrs['type'], 'ld+json' ) ) {
					++$jsonld;
				}
				$close = stripos( $html, '</' . $name, $i );
				$i     = false === $close ? $len : ( false === strpos( $html, '>', $close ) ? $len : strpos( $html, '>', $close ) + 1 );
				continue;
			}
			if ( 'a' === $name ) {
				$open_a = array(
					'href'   => isset( $attrs['href'] ) ? html_entity_decode( $attrs['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
					'rel'    => isset( $attrs['rel'] ) ? strtolower( $attrs['rel'] ) : '',
					'start'  => $lt,
					'end'    => $gt + 1,
					'anchor' => '',
				);
			}
			if ( preg_match( '/^h([1-6])$/', $name, $hm ) ) {
				$open_h = array(
					'tag'   => $name,
					'level' => (int) $hm[1],
					'start' => $lt,
					'end'   => $gt + 1,
					'text'  => '',
				);
			}
			if ( 'img' === $name ) {
				$images[] = array(
					'src'     => isset( $attrs['src'] ) ? $attrs['src'] : '',
					'has_alt' => isset( $attrs['alt'] ) && '' !== trim( $attrs['alt'] ),
					'offset'  => $lt,
				);
			}
			$self_closing = '/' === substr( rtrim( substr( $tag_src, 0, -1 ) ), -1 );
			if ( ! in_array( $name, self::VOID_TAGS, true ) && ! $self_closing ) {
				$stack[] = $name;
			}
		}
		if ( null !== $open_a ) {
			$open_a['anchor'] = trim( $open_a['anchor'] );
			$links[]          = $open_a;
		}
		$text = trim( preg_replace( "/[ \t]*\n[\s]*/u", "\n", preg_replace( '/[ \t\x{00A0}]+/u', ' ', implode( '', $text_buf ) ) ) );
		return array(
			'segments' => $segments,
			'links'    => $links,
			'headings' => $headings,
			'images'   => $images,
			'jsonld'   => $jsonld,
			'text'     => $text,
		);
	}

	/**
	 * Records a text run.
	 *
	 * @param string     $html     HTML.
	 * @param int        $start    Start offset.
	 * @param int        $end      End offset (exclusive).
	 * @param array      $stack    Open element stack.
	 * @param array      $masks    Shortcode ranges.
	 * @param array      $segments Segments (by reference).
	 * @param array|null $open_a   Open link (by reference).
	 * @param array|null $open_h   Open heading (by reference).
	 * @param array      $text_buf Plain text buffer (by reference).
	 * @return void
	 */
	private static function add_text( $html, $start, $end, array $stack, array $masks, array &$segments, &$open_a, &$open_h, array &$text_buf ) {
		$raw = substr( $html, $start, $end - $start );
		if ( null !== $open_a ) {
			$open_a['anchor'] .= $raw;
		}
		if ( null !== $open_h ) {
			$open_h['text'] .= $raw;
		}
		$plain      = html_entity_decode( self::strip_shortcodes_text( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text_buf[] = $plain;
		if ( '' === trim( $raw ) ) {
			return;
		}
		$protected = false;
		foreach ( $stack as $tag ) {
			if ( in_array( $tag, self::PROTECTED_TAGS, true ) ) {
				$protected = true;
				break;
			}
		}
		foreach ( $masks as $range ) {
			if ( $start < $range[1] && $end > $range[0] ) {
				$protected = true;
				break;
			}
		}
		$segments[] = array(
			'start'     => $start,
			'end'       => $end,
			'raw'       => $raw,
			'context'   => array() === $stack ? '' : $stack[ count( $stack ) - 1 ],
			'protected' => $protected,
		);
	}

	/**
	 * Byte ranges of shortcodes ([tag ...] and [/tag]) which must never be edited.
	 *
	 * @param string $html HTML.
	 * @return array<int,array{0:int,1:int}>
	 */
	public static function shortcode_ranges( $html ) {
		$ranges = array();
		if ( preg_match_all( '/\[\/?[a-zA-Z][\w\-]*[^\]]*\]/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$ranges[] = array( $hit[1], $hit[1] + strlen( $hit[0] ) );
			}
		}
		return $ranges;
	}

	/**
	 * Removes shortcode tags from a text run (plain-text extraction only).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_shortcodes_text( $text ) {
		return (string) preg_replace( '/\[\/?[a-zA-Z][\w\-]*[^\]]*\]/', ' ', $text );
	}

	/**
	 * Finds the end of a tag, honouring quoted attribute values.
	 *
	 * @param string $html  HTML.
	 * @param int    $start Offset of "<".
	 * @return int|false Offset of ">".
	 */
	private static function tag_end( $html, $start ) {
		$len   = strlen( $html );
		$quote = '';
		for ( $j = $start + 1; $j < $len; $j++ ) {
			$c = $html[ $j ];
			if ( '' !== $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '>' === $c ) {
				return $j;
			}
		}
		return false;
	}

	/**
	 * Parses attributes of a start tag.
	 *
	 * @param string $tag Tag source.
	 * @return array<string,string>
	 */
	public static function attributes( $tag ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][\w:\-\.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', substr( $tag, 1 ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $i => $a ) {
				if ( 0 === $i ) {
					continue; // Tag name.
				}
				$val                           = isset( $a[4] ) && '' !== $a[4] ? $a[4] : ( isset( $a[3] ) && '' !== $a[3] ? $a[3] : ( isset( $a[2] ) ? $a[2] : '' ) );
				$attrs[ strtolower( $a[1] ) ] = $val;
			}
		}
		return $attrs;
	}
}
