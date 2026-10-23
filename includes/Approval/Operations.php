<?php
/**
 * Pure content operations with strict validation (unit-testable).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Approval;

use SemanticSEO\Extractor\HtmlScanner;

defined( 'ABSPATH' ) || exit;

/**
 * Applies and reverts the exact edits that proposals describe.
 */
final class Operations {

	/**
	 * Markup inserted for an internal link (attribute-escaped href, untouched anchor text).
	 *
	 * @param string $href   Internal URL.
	 * @param string $anchor Anchor text (already present in content).
	 * @return string
	 */
	public static function link_markup( $href, $anchor ) {
		return '<a href="' . esc_url( $href ) . '">' . $anchor . '</a>';
	}

	/**
	 * Wraps an exact byte range with a link after verifying it is plain, unprotected text.
	 *
	 * @param string $content Content.
	 * @param int    $offset  Byte offset.
	 * @param int    $length  Byte length.
	 * @param string $anchor  Expected text at the range.
	 * @param string $href    Internal URL.
	 * @return string|\WP_Error New content.
	 */
	public static function insert_link( $content, $offset, $length, $anchor, $href ) {
		if ( $offset < 0 || $length <= 0 || substr( $content, $offset, $length ) !== $anchor ) {
			return new \WP_Error( 'sseo_offset_mismatch', __( 'The anchor text is not at the recorded position.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$ok = false;
		foreach ( HtmlScanner::scan( $content )['segments'] as $seg ) {
			if ( $seg['start'] <= $offset && $seg['end'] >= $offset + $length ) {
				$ok = ! $seg['protected'];
				break;
			}
		}
		if ( ! $ok ) {
			return new \WP_Error( 'sseo_protected_position', __( 'The position is inside a heading, link, shortcode, code or other protected element.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		$markup = self::link_markup( $href, $anchor );
		$out    = substr( $content, 0, $offset ) . $markup . substr( $content, $offset + $length );
		// The result must parse as the same links plus exactly this one, starting at the offset: a
		// link nested in another link (or swallowed by markup the scanner misread) is refused.
		$before = HtmlScanner::scan( $content )['links'];
		$after  = HtmlScanner::scan( $out )['links'];
		$found  = false;
		foreach ( $after as $l ) {
			if ( $l['start'] === $offset && $l['end'] === $offset + strlen( $markup ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found || count( $after ) !== count( $before ) + 1 ) {
			return new \WP_Error( 'sseo_protected_position', __( 'The position is inside a heading, link, shortcode, code or other protected element.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		return $out;
	}

	/**
	 * Surgical revert: unwraps the inserted link when it occurs exactly once.
	 *
	 * @param string $content Current content.
	 * @param string $href    Href used.
	 * @param string $anchor  Anchor text.
	 * @return string|\WP_Error
	 */
	public static function remove_link( $content, $href, $anchor ) {
		$markup = self::link_markup( $href, $anchor );
		$count  = substr_count( $content, $markup );
		if ( 1 !== $count ) {
			return new \WP_Error(
				'sseo_manual_recovery',
				0 === $count
					? __( 'The inserted link is no longer present; nothing to revert automatically.', 'semantic-seo-engine-vi-en' )
					: __( 'The inserted link markup occurs several times; automatic revert would be ambiguous.', 'semantic-seo-engine-vi-en' ),
				array(
					'status'      => 409,
					'occurrences' => $count,
				)
			);
		}
		return str_replace( $markup, $anchor, $content );
	}
}
