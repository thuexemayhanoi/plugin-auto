<?php
/**
 * Explainable heuristic search-intent classifier (VI/EN).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Analysis;

use SemanticSEO\Language\TextNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Scores cue phrases found in the title, headings, slug and opening paragraph. Output is an
 * editorial hint with the matched evidence — never a claim about real user intent.
 */
final class IntentClassifier {

	const VERSION = 'intent-1';

	/**
	 * Cue phrases (normalized, accent-preserving; unaccented forms matched via folding).
	 */
	const CUES = array(
		'transactional' => array( 'thuê', 'cho thuê', 'giá', 'bảng giá', 'giá thuê', 'đặt xe', 'đặt', 'mua', 'bán', 'khuyến mãi', 'ưu đãi', 'báo giá', 'chi phí', 'phí', 'đặt cọc', 'tiền cọc', 'thủ tục', 'rent', 'rental', 'hire', 'price', 'prices', 'pricing', 'cost', 'book', 'booking', 'buy', 'deal', 'discount', 'deposit', 'quote' ),
		'commercial'    => array( 'so sánh', 'tốt nhất', 'review', 'đánh giá', 'nên chọn', 'top', 'loại nào', 'ưu nhược điểm', 'best', 'compare', 'comparison', 'vs', 'versus', 'alternatives', 'which' ),
		'informational' => array( 'là gì', 'cách', 'hướng dẫn', 'kinh nghiệm', 'mẹo', 'lưu ý', 'tại sao', 'có nên', 'bao nhiêu', 'quy định', 'luật', 'bằng lái', 'giấy phép', 'lịch trình', 'du lịch', 'what', 'how', 'why', 'guide', 'tips', 'tutorial', 'checklist', 'itinerary', 'travel', 'license', 'licence', 'rules', 'law', 'faq' ),
		'navigational'  => array( 'liên hệ', 'địa chỉ', 'giới thiệu', 'về chúng tôi', 'hotline', 'chính sách', 'điều khoản', 'contact', 'about us', 'address', 'policy', 'terms', 'privacy', 'login' ),
	);

	const FIELD_WEIGHTS = array(
		'title'    => 3.0,
		'slug'     => 2.0,
		'headings' => 1.5,
		'opening'  => 1.0,
	);

	/**
	 * Classifies a document.
	 *
	 * @param array $doc {title, slug, headings[], opening, attributes[], word_count}.
	 * @return array{intent:string,confidence:string,scores:array,evidence:array,version:string}
	 */
	public static function classify( array $doc ) {
		$fields = array(
			'title'    => isset( $doc['title'] ) ? (string) $doc['title'] : '',
			'slug'     => isset( $doc['slug'] ) ? str_replace( array( '-', '_' ), ' ', urldecode( (string) $doc['slug'] ) ) : '',
			'headings' => isset( $doc['headings'] ) ? implode( ' | ', (array) $doc['headings'] ) : '',
			'opening'  => isset( $doc['opening'] ) ? (string) $doc['opening'] : '',
		);
		$scores   = array_fill_keys( array_keys( self::CUES ), 0.0 );
		$evidence = array();
		foreach ( $fields as $field => $text ) {
			if ( '' === $text ) {
				continue;
			}
			$norm   = ' ' . preg_replace( '/[^\p{L}\p{N}]+/u', ' ', TextNormalizer::normalize( $text ) ) . ' ';
			$folded = TextNormalizer::fold( $norm );
			foreach ( self::CUES as $intent => $cues ) {
				foreach ( $cues as $cue ) {
					$cue_n = ' ' . $cue . ' ';
					$hit   = false !== strpos( $norm, $cue_n );
					// The slug is usually unaccented: compare folded forms there.
					if ( ! $hit && 'slug' === $field ) {
						$hit = false !== strpos( $folded, TextNormalizer::fold( $cue_n ) );
					}
					if ( $hit ) {
						$scores[ $intent ] += self::FIELD_WEIGHTS[ $field ];
						$evidence[]         = array(
							'intent' => $intent,
							'cue'    => $cue,
							'field'  => $field,
						);
					}
				}
			}
		}
		$attributes = isset( $doc['attributes'] ) ? (array) $doc['attributes'] : array();
		$prices     = 0;
		foreach ( $attributes as $a ) {
			if ( preg_match( '/(đ|vnđ|vnd|usd|\$|nghìn|triệu|k)$/u', (string) $a ) ) {
				++$prices;
			}
		}
		if ( $prices >= 2 ) {
			$scores['transactional'] += 1.0;
			$evidence[]               = array(
				'intent' => 'transactional',
				'cue'    => $prices . ' price values',
				'field'  => 'body',
			);
		}
		arsort( $scores );
		$top    = (string) key( $scores );
		$values = array_values( $scores );
		$first  = $values[0];
		$second = isset( $values[1] ) ? $values[1] : 0.0;
		if ( $first <= 0.0 ) {
			return array(
				'intent'     => 'unknown',
				'confidence' => 'low',
				'scores'     => $scores,
				'evidence'   => array(),
				'version'    => self::VERSION,
			);
		}
		if ( $first >= 3.0 && $first >= 2 * $second ) {
			$confidence = 'high';
		} elseif ( $first > $second ) {
			$confidence = 'medium';
		} else {
			$confidence = 'low';
		}
		return array(
			'intent'     => $top,
			'confidence' => $confidence,
			'scores'     => array_map(
				static function ( $v ) {
					return round( $v, 2 );
				},
				$scores
			),
			'evidence'   => array_slice( $evidence, 0, 12 ),
			'version'    => self::VERSION,
		);
	}
}
