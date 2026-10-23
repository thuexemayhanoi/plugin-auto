<?php
/**
 * Per-task scoring with separate, inspectable evidence components.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Analysis;

use SemanticSEO\Extractor\ContentExtractor;
use SemanticSEO\Language\TextNormalizer;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Lexical\Tokenizer;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\SimilarityBaseline;
use SemanticSEO\Semantic\VectorMath;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * No single "magic" weight: each task combines the components it needs. Weights and thresholds
 * are exposed in the Expert panel; defaults were evaluated on the labelled fixture set.
 */
final class Scorer {

	const VERSION = 'score-3';

	/**
	 * Active model key per precision (request cache).
	 *
	 * @var array<string,string>
	 */
	private static $model_keys = array();

	/**
	 * Decodes a document row into an analysis profile.
	 *
	 * @param array $row Document row.
	 * @return array
	 */
	public static function profile( array $row ) {
		$signals = json_decode( (string) $row['signals'], true );
		$signals = is_array( $signals ) ? $signals : array();
		$terms   = json_decode( (string) $row['terms_json'], true );
		$lang    = in_array( $row['locale'], array( 'vi', 'en' ), true ) ? $row['locale'] : '';
		$heads   = array();
		if ( isset( $signals['headings'] ) ) {
			foreach ( (array) $signals['headings'] as $h ) {
				if ( isset( $h['level'] ) && $h['level'] <= 3 ) {
					$heads[] = (string) $h['text'];
				}
			}
		}
		return array(
			'id'                => (int) $row['id'],
			'object_type'       => (string) $row['object_type'],
			'object_id'         => (int) $row['object_id'],
			'subtype'           => (string) $row['subtype'],
			'url'               => (string) $row['url'],
			'title'             => (string) $row['title'],
			'locale'            => (string) $row['locale'],
			'status'            => (string) $row['status'],
			'indexable'         => (int) $row['indexable'],
			'excluded'          => (int) $row['excluded'],
			'intent'            => '' !== (string) $row['editorial_intent'] ? (string) $row['editorial_intent'] : (string) $row['intent'],
			'intent_confidence' => '' !== (string) $row['editorial_intent'] ? 'editorial' : (string) $row['intent_confidence'],
			'focus_keyword'     => (string) $row['focus_keyword'],
			'translation_group' => (string) $row['translation_group'],
			'cluster_id'        => (int) $row['cluster_id'],
			'cluster_role'      => (string) $row['cluster_role'],
			'inbound_count'     => (int) $row['inbound_count'],
			'outbound_count'    => (int) $row['outbound_count'],
			'word_count'        => (int) $row['word_count'],
			'content_hash'      => (string) $row['content_hash'],
			'terms'             => is_array( $terms ) ? $terms : array(),
			'signals'           => $signals,
			'lexvec'            => isset( $signals['lexvec'] ) && is_array( $signals['lexvec'] ) ? $signals['lexvec'] : array(),
			'minhash'           => isset( $signals['minhash'] ) && is_array( $signals['minhash'] ) ? $signals['minhash'] : array(),
			'title_set'         => Tokenizer::significant_set( (string) $row['title'], $lang ),
			'heading_set'       => Tokenizer::significant_set( implode( ' ', array_slice( $heads, 0, 20 ) ), $lang ),
		);
	}

	/**
	 * Jaccard similarity of two token sets.
	 *
	 * @param string[] $a Set.
	 * @param string[] $b Set.
	 * @return float
	 */
	public static function jaccard( array $a, array $b ) {
		if ( array() === $a || array() === $b ) {
			return 0.0;
		}
		$ia = array_flip( $a );
		$ib = array_flip( $b );
		$in = count( array_intersect_key( $ia, $ib ) );
		$un = count( $ia + $ib );
		return $un > 0 ? $in / $un : 0.0;
	}

	/**
	 * Overlap coefficient |A∩B| / min(|A|,|B|): how much of the shorter title is contained in the other.
	 *
	 * @param string[] $a Set.
	 * @param string[] $b Set.
	 * @return float
	 */
	public static function containment( array $a, array $b ) {
		$min = min( count( $a ), count( $b ) );
		if ( 0 === $min ) {
			return 0.0;
		}
		return count( array_intersect_key( array_flip( $a ), array_flip( $b ) ) ) / $min;
	}

	/**
	 * Pair features between two profiles.
	 *
	 * @param array      $a        Profile.
	 * @param array      $b        Profile.
	 * @param array|null $vec_a    Semantic vector of A (normalized) or null.
	 * @param array|null $vec_b    Semantic vector of B (normalized) or null.
	 * @param int        $gsc_overlap Shared real GSC queries (0 when no data).
	 * @param bool       $gsc_available Whether GSC data exists for both pages.
	 * @return array
	 */
	public static function features( array $a, array $b, $vec_a = null, $vec_b = null, $gsc_overlap = 0, $gsc_available = false ) {
		$lexical  = LexicalIndex::cosine( $a['lexvec'], $b['lexvec'] );
		$semantic = ( is_array( $vec_a ) && is_array( $vec_b ) && array() !== $vec_a && array() !== $vec_b ) ? max( 0.0, VectorMath::dot( $vec_a, $vec_b ) ) : null;
		$cal      = null === $semantic ? null : self::calibration( $a['locale'] );
		$sem_cal  = null === $semantic ? null : self::calibrate( $semantic, $cal );
		$w_lex    = (float) Settings::get( 'w_lexical', 0.45 );
		$w_sem    = (float) Settings::get( 'w_semantic', 0.35 );
		// Ranking (links, related, candidate order) uses the raw cosine: measured better on the labelled
		// fixture with the real model. The overlap-risk gate uses the calibrated value, which keeps the
		// cosine floor of unrelated pages — and, on a site whose pages are unusually alike, the similarity
		// they all share — from turning every pair into a "risk" (ModelProfile::CALIBRATION, SimilarityBaseline).
		$topic     = null === $semantic ? $lexical : ( $w_lex * $lexical + $w_sem * $semantic ) / max( 0.0001, $w_lex + $w_sem );
		$topic_cal = null === $sem_cal ? $lexical : ( $w_lex * $lexical + $w_sem * $sem_cal ) / max( 0.0001, $w_lex + $w_sem );

		$only_a = array_values( array_diff( $a['title_set'], $b['title_set'] ) );
		$only_b = array_values( array_diff( $b['title_set'], $a['title_set'] ) );
		$shared = array_values( array_intersect( $a['title_set'], $b['title_set'] ) );

		$focus_same = '' !== $a['focus_keyword'] && '' !== $b['focus_keyword']
			&& TextNormalizer::normalize( $a['focus_keyword'] ) === TextNormalizer::normalize( $b['focus_keyword'] );

		return array(
			'lexical'             => round( $lexical, 4 ),
			'semantic'            => null === $semantic ? null : round( $semantic, 4 ),
			'semantic_calibrated' => null === $sem_cal ? null : round( $sem_cal, 4 ),
			'topic'               => round( $topic, 4 ),
			'topic_calibrated'    => round( $topic_cal, 4 ),
			'calibration'         => null === $semantic ? null : $cal,
			'title_overlap'       => round( self::jaccard( $a['title_set'], $b['title_set'] ), 4 ),
			'title_containment'   => round( self::containment( $a['title_set'], $b['title_set'] ), 4 ),
			'heading_overlap'     => round( self::jaccard( $a['heading_set'], $b['heading_set'] ), 4 ),
			'near_duplicate'      => round( ContentExtractor::minhash_similarity( $a['minhash'], $b['minhash'] ), 4 ),
			'same_intent'         => $a['intent'] === $b['intent'] && 'unknown' !== $a['intent'],
			'intents'             => array( $a['intent'], $b['intent'] ),
			'intent_conf'         => array( $a['intent_confidence'], $b['intent_confidence'] ),
			'focus_same'          => $focus_same,
			'shared_terms'        => LexicalIndex::shared_terms( $a['lexvec'], $b['lexvec'], 8 ),
			'title_shared'        => $shared,
			'title_only_a'        => $only_a,
			'title_only_b'        => $only_b,
			'same_cluster'        => $a['cluster_id'] > 0 && $a['cluster_id'] === $b['cluster_id'],
			'roles'               => array( $a['cluster_role'], $b['cluster_role'] ),
			'gsc_overlap'         => (int) $gsc_overlap,
			'gsc_available'       => (bool) $gsc_available,
			'exact_duplicate'     => '' !== $a['content_hash'] && $a['content_hash'] === $b['content_hash'] && $a['word_count'] > 20,
		);
	}

	/**
	 * Calibration for same-language pairs of a locale: the model's floor/ceiling, with the floor
	 * raised to the site's median similarity of that language when it is higher (one-topic sites).
	 *
	 * @param string $locale Locale of the pair.
	 * @return array{floor: float, ceiling: float, source: string}|null Null = raw cosine.
	 */
	public static function calibration( $locale ) {
		$dtype = (string) Settings::get( 'model_dtype', 'q4f16' );
		$cal   = ModelProfile::calibration( $dtype );
		if ( null === $cal ) {
			return null;
		}
		$cal['source'] = 'model';
		if ( ! isset( self::$model_keys[ $dtype ] ) ) {
			self::$model_keys[ $dtype ] = ModelProfile::active()['model_key'];
		}
		$median = SimilarityBaseline::median( (string) $locale, self::$model_keys[ $dtype ] );
		if ( null !== $median && $median > $cal['floor'] ) {
			// Keep a usable range below the ceiling.
			$cal['floor']  = round( min( $median, $cal['ceiling'] - 0.10 ), 4 );
			$cal['source'] = 'site_median';
		}
		return $cal;
	}

	/**
	 * Calibrated semantic score (raw cosine when there is no calibration).
	 *
	 * @param float      $cosine Raw cosine.
	 * @param array|null $cal    Calibration from calibration(); defaults to the model's own.
	 * @return float 0..1
	 */
	public static function calibrate( $cosine, $cal = false ) {
		if ( false === $cal ) {
			$cal = ModelProfile::calibration( (string) Settings::get( 'model_dtype', 'q4f16' ) );
		}
		if ( null === $cal ) {
			return (float) $cosine;
		}
		return max( 0.0, min( 1.0, ( (float) $cosine - $cal['floor'] ) / ( $cal['ceiling'] - $cal['floor'] ) ) );
	}

	/**
	 * Forgets request caches (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$model_keys = array();
		SimilarityBaseline::reset();
	}

	/**
	 * Graph usefulness of linking to a target (0..1).
	 *
	 * @param array $source Source profile.
	 * @param array $target Target profile.
	 * @return float
	 */
	public static function graph_usefulness( array $source, array $target ) {
		$score = 1.0 / ( 1.0 + log( 1 + max( 0, $target['inbound_count'] ) ) );
		if ( $source['cluster_id'] > 0 && $source['cluster_id'] === $target['cluster_id'] && in_array( $target['cluster_role'], array( 'pillar', 'hub' ), true ) && 'supporting' === $source['cluster_role'] ) {
			$score += 0.5;
		}
		return round( min( 1.0, $score ), 4 );
	}

	/**
	 * Link relevance (components + total). Semantic weight is dropped (and weights renormalized)
	 * when no comparable vectors exist — the component stays null, never invented.
	 *
	 * @param array $features Pair features.
	 * @param float $anchor   Anchor quality 0..1.
	 * @param float $graph    Graph usefulness 0..1.
	 * @return array{score:float,components:array}
	 */
	public static function link_score( array $features, $anchor, $graph ) {
		$w     = array(
			'lexical'  => (float) Settings::get( 'w_lexical', 0.45 ),
			'semantic' => null === $features['semantic'] ? 0.0 : (float) Settings::get( 'w_semantic', 0.35 ),
			'anchor'   => (float) Settings::get( 'w_anchor', 0.10 ),
			'graph'    => (float) Settings::get( 'w_graph', 0.10 ),
		);
		$sum   = max( 0.0001, array_sum( $w ) );
		$total = ( $w['lexical'] * min( 1.0, $features['lexical'] * 2.0 )
			+ $w['semantic'] * ( null === $features['semantic'] ? 0.0 : (float) $features['semantic'] )
			+ $w['anchor'] * $anchor
			+ $w['graph'] * $graph ) / $sum;
		return array(
			'score'      => round( $total, 4 ),
			'components' => array(
				'lexical'             => $features['lexical'],
				'semantic'            => $features['semantic'],
				'semantic_calibrated' => isset( $features['semantic_calibrated'] ) ? $features['semantic_calibrated'] : null,
				'anchor'              => round( $anchor, 4 ),
				'graph'               => round( $graph, 4 ),
				'weights'             => $w,
			),
		);
	}
}
