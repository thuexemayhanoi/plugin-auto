<?php
/**
 * Cross-language similarity: possible translations that are not linked yet.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

use SemanticSEO\Database\Db;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Qwen3-Embedding places a page and its translation close together across languages. Measured on
 * the labelled fixture with the real model (fp32, document level): translation pairs 0.74–0.84,
 * other VI/EN pairs at most 0.78, and every page's nearest page in the other language was its
 * translation (9/9). A pair is suggested only when the two pages are each other's nearest page in
 * the other language, the similarity reaches the threshold and it beats the runner-up by a margin.
 * Never linked automatically: the suggestion asks a human to check and link it in the language
 * plugin. Vector-only signal; nothing is suggested without vectors of the active model profile.
 */
final class CrossLanguage {

	const VERSION = 'xlang-1';

	/**
	 * Upper bound of vector comparisons per locale pair and run (keeps the request bounded).
	 */
	const MAX_COMPARISONS = 2000000;

	/**
	 * Mutual nearest cross-language pairs among published, indexable posts and pages.
	 *
	 * @param string $model_key       Active model key.
	 * @param bool   $include_linked  Also return pairs that already share a translation group
	 *                                (used to measure the method against known pairs).
	 * @return array{pairs: array, skipped: array}
	 */
	public static function candidates( $model_key, $include_linked = false ) {
		$min    = (float) Settings::get( 'translation_min_similarity', 0.70 );
		$margin = 0.02;
		$rows   = Db::rows( "SELECT id, locale, subtype, translation_group FROM {documents} WHERE object_type = 'post' AND status = 'publish' AND indexable = 1 AND excluded = 0 AND locale <> ''" );
		$groups = array();
		foreach ( $rows as $r ) {
			if ( ! $include_linked && '' !== $r['translation_group'] ) {
				continue;
			}
			$groups[ $r['subtype'] ][ $r['locale'] ][ (int) $r['id'] ] = $r['translation_group'];
		}
		$pairs   = array();
		$skipped = array();
		foreach ( $groups as $subtype => $by_locale ) {
			$locales = array_keys( $by_locale );
			sort( $locales );
			$count = count( $locales );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a = $by_locale[ $locales[ $i ] ];
					$b = $by_locale[ $locales[ $j ] ];
					if ( count( $a ) * count( $b ) > self::MAX_COMPARISONS ) {
						$skipped[] = $subtype . ':' . $locales[ $i ] . '-' . $locales[ $j ];
						continue;
					}
					$va = EmbeddingRepository::summary_vectors( array_keys( $a ), $model_key );
					$vb = EmbeddingRepository::summary_vectors( array_keys( $b ), $model_key );
					if ( array() === $va || array() === $vb ) {
						continue;
					}
					foreach ( self::mutual_best( $va, $vb, $min, $margin ) as $p ) {
						$p['linked'] = '' !== $a[ $p['a'] ] && $a[ $p['a'] ] === $b[ $p['b'] ];
						$pairs[]     = $p;
					}
				}
			}
		}
		return array(
			'pairs'   => $pairs,
			'skipped' => $skipped,
		);
	}

	/**
	 * Mutual nearest neighbours above a threshold, with the margin over each side's runner-up.
	 *
	 * @param array<int,float[]> $va     Vectors of side A (normalized).
	 * @param array<int,float[]> $vb     Vectors of side B (normalized).
	 * @param float              $min    Minimum similarity.
	 * @param float              $margin Minimum lead over the runner-up on both sides.
	 * @return array<int,array{a:int,b:int,similarity:float,margin:float}>
	 */
	public static function mutual_best( array $va, array $vb, $min, $margin ) {
		$best_a = array();
		$best_b = array();
		foreach ( $va as $ia => $x ) {
			foreach ( $vb as $ib => $y ) {
				$s = VectorMath::dot( $x, $y );
				self::keep_top2( $best_a, $ia, $ib, $s );
				self::keep_top2( $best_b, $ib, $ia, $s );
			}
		}
		$out = array();
		foreach ( $best_a as $ia => $top ) {
			$ib = $top[0][0];
			$s  = $top[0][1];
			if ( $s < $min || ! isset( $best_b[ $ib ] ) || $best_b[ $ib ][0][0] !== $ia ) {
				continue;
			}
			$lead_a = $s - ( isset( $top[1] ) ? $top[1][1] : -1.0 );
			$lead_b = $s - ( isset( $best_b[ $ib ][1] ) ? $best_b[ $ib ][1][1] : -1.0 );
			$lead   = min( $lead_a, $lead_b );
			if ( $lead < $margin ) {
				continue;
			}
			$out[] = array(
				'a'          => (int) $ia,
				'b'          => (int) $ib,
				'similarity' => round( $s, 4 ),
				'margin'     => round( $lead, 4 ),
			);
		}
		return $out;
	}

	/**
	 * Keeps the two best (id, score) entries per key.
	 *
	 * @param array $best  Map key => [[id, score], [id, score]].
	 * @param int   $key   Key.
	 * @param int   $id    Candidate.
	 * @param float $score Score.
	 * @return void
	 */
	private static function keep_top2( array &$best, $key, $id, $score ) {
		if ( ! isset( $best[ $key ] ) ) {
			$best[ $key ] = array( array( $id, $score ) );
			return;
		}
		if ( $score > $best[ $key ][0][1] ) {
			array_unshift( $best[ $key ], array( $id, $score ) );
			$best[ $key ] = array_slice( $best[ $key ], 0, 2 );
		} elseif ( ! isset( $best[ $key ][1] ) || $score > $best[ $key ][1][1] ) {
			$best[ $key ][1] = array( $id, $score );
		}
	}

	/**
	 * QA suggestions for unlinked pairs.
	 *
	 * @param string $model_key Active model key.
	 * @return array<int,array> Findings.
	 */
	public static function findings( $model_key ) {
		$res = self::candidates( $model_key, false );
		$out = array();
		foreach ( $res['pairs'] as $p ) {
			$locale = (string) Db::value( 'SELECT locale FROM {documents} WHERE id = %d', array( $p['a'] ) );
			$out[]  = array(
				'type'              => 'qa',
				'check_id'          => 'possible_translation_pair',
				'document_id'       => $p['a'],
				'target_id'         => $p['b'],
				'locale'            => $locale,
				'label'             => 'suggestion',
				'severity'          => 'info',
				'confidence'        => $p['similarity'] >= 0.8 && $p['margin'] >= 0.05 ? 'high' : 'medium',
				'score'             => $p['similarity'],
				'algorithm_version' => self::VERSION,
				'evidence'          => array(
					'check'      => 'Possible translation in another language, not linked yet (cross-language semantic similarity)',
					'similarity' => $p['similarity'],
					'margin'     => $p['margin'],
					'model_key'  => substr( (string) $model_key, 0, 12 ),
					'note'       => 'Each page is the other\'s nearest page in that language. Similar meaning is not proof of a translation: check both pages, then link them in your language plugin if they match. Nothing is linked automatically.',
				),
			);
		}
		return $out;
	}
}
