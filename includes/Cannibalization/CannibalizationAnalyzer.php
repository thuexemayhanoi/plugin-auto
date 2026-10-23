<?php
/**
 * M3 — same-locale overlap classification. Outputs risk hypotheses, never verdicts.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Cannibalization;

use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Categories:
 *  exact_duplicate, near_duplicate            — content overlap (verified by hash / MinHash estimate)
 *  potential_competition                      — same topic + same intent + title/keyword/GSC evidence
 *  same_topic_different_intent                — related, likely complementary
 *  distinct_facet                             — titles differ by a distinguishing modifier (theo ngày / theo tháng)
 *  pillar_supporting                          — valid cluster relationship
 *  translation_mapping_conflict               — same-locale pages inside one translation group
 *  manual_review                              — ambiguous
 * Pages in different locales are never compared (VI/EN translations cannot be flagged).
 */
final class CannibalizationAnalyzer {

	const VERSION = 'cannibal-3';

	const FACET_WORDS = array( 'giờ', 'ngày', 'tuần', 'tháng', 'năm', 'hour', 'hourly', 'day', 'daily', 'week', 'weekly', 'month', 'monthly', 'year', 'yearly', 'annual' );

	const RISK_ORDER = array(
		'exact_duplicate'              => 6,
		'near_duplicate'               => 5,
		'potential_competition'        => 4,
		'translation_mapping_conflict' => 3,
		'manual_review'                => 2,
		'distinct_facet'               => 1,
		'same_topic_different_intent'  => 1,
		'pillar_supporting'            => 0,
	);

	/**
	 * Classifies a pair. Returns null when the pair is not worth reporting.
	 *
	 * @param array $a Profile.
	 * @param array $b Profile.
	 * @param array $f Pair features.
	 * @return array|null Finding.
	 */
	public static function classify( array $a, array $b, array $f ) {
		if ( $a['locale'] !== $b['locale'] || '' === $a['locale'] ) {
			return null;
		}
		if ( 'publish' !== $a['status'] || 'publish' !== $b['status'] || $a['excluded'] || $b['excluded'] ) {
			return null;
		}
		if ( 'post' !== $a['object_type'] || 'post' !== $b['object_type'] ) {
			return null;
		}
		$threshold = (float) Settings::get( 'cannibal_min_score', 0.30 );
		$near      = (float) Settings::get( 'near_duplicate', 0.80 );
		$category  = null;
		$conf      = 'low';
		$notes     = array();

		if ( '' !== $a['translation_group'] && $a['translation_group'] === $b['translation_group'] ) {
			$category = 'translation_mapping_conflict';
			$conf     = 'manual_review';
			$notes[]  = 'Both pages belong to one translation group but share the same locale.';
		} elseif ( $f['exact_duplicate'] ) {
			$category = 'exact_duplicate';
			$conf     = 'high';
			$notes[]  = 'Identical extracted text (content hash match).';
		} elseif ( $f['near_duplicate'] >= $near ) {
			$category = 'near_duplicate';
			$conf     = $f['near_duplicate'] >= 0.9 ? 'high' : 'medium';
			$notes[]  = 'Estimated shingle overlap (MinHash) ' . $f['near_duplicate'] . '.';
		} elseif ( self::overlap_topic( $f ) < $threshold && $f['title_overlap'] < 0.6 && ! self::title_contained( $f ) && ! $f['focus_same'] && $f['gsc_overlap'] < 1 ) {
			return null;
		} elseif ( self::is_pillar_supporting( $a, $b, $f ) ) {
			$category = 'pillar_supporting';
			$conf     = 'medium';
			$notes[]  = 'One page is the recommended pillar/hub of the shared cluster; the other supports it.';
		} elseif ( $f['gsc_overlap'] >= 1 && $f['same_intent'] ) {
			$category = 'potential_competition';
			$conf     = 'high';
			$notes[]  = 'Real Search Console data shows both pages receiving impressions for ' . $f['gsc_overlap'] . ' shared queries in the imported period.';
		} elseif ( $f['focus_same'] ) {
			$category = 'potential_competition';
			$conf     = $f['same_intent'] ? 'high' : 'medium';
			$notes[]  = 'Both pages declare the same focus keyword in the SEO plugin.';
		} elseif ( ! $f['same_intent'] && self::confident( $f['intent_conf'] ) ) {
			$category = 'same_topic_different_intent';
			$conf     = 'medium';
			$notes[]  = 'Related topic but different heuristic intents (' . implode( ' / ', $f['intents'] ) . ').';
		} elseif ( self::is_distinct_facet( $f ) ) {
			$category = 'distinct_facet';
			$conf     = 'manual_review';
			$notes[]  = 'Titles differ by distinguishing modifiers (' . implode( ', ', $f['title_only_a'] ) . ' vs ' . implode( ', ', $f['title_only_b'] ) . '); likely separate offers/sub-topics.';
		} elseif ( $f['same_intent'] && ( $f['title_overlap'] >= 0.5 || self::title_contained( $f ) ) ) {
			$category = 'potential_competition';
			$conf     = self::overlap_topic( $f ) >= $threshold ? 'medium' : 'low';
			$notes[]  = self::title_contained( $f ) ? 'Same heuristic intent; one title is almost entirely contained in the other (' . implode( ' ', $f['title_shared'] ) . ').' : 'Same heuristic intent with strong title overlap.';
		} else {
			$category = 'manual_review';
			$conf     = 'manual_review';
			$notes[]  = 'Topically similar; evidence is ambiguous.';
		}

		$risk = self::risk_score( $category, $f );
		if ( ! $f['gsc_available'] ) {
			$notes[] = 'No imported Search Console data for this pair: this is a risk hypothesis, not confirmed cannibalization.';
		}
		$label = in_array( $category, array( 'exact_duplicate' ), true ) ? 'warning' : ( 'pillar_supporting' === $category ? 'info' : 'risk_hypothesis' );
		$lo    = min( $a['id'], $b['id'] );
		$hi    = max( $a['id'], $b['id'] );
		return array(
			'type'              => 'cannibalization',
			'check_id'          => $category,
			'document_id'       => $lo,
			'target_id'         => $hi,
			'group_key'         => 'pair-' . $lo . '-' . $hi,
			'locale'            => $a['locale'],
			'label'             => $label,
			'severity'          => self::RISK_ORDER[ $category ] >= 4 ? 'warning' : 'info',
			'confidence'        => $conf,
			'score'             => $risk,
			'algorithm_version' => self::VERSION,
			'evidence'          => array(
				'category'          => $category,
				'notes'             => $notes,
				'topic'             => $f['topic'],
				'topic_calibrated'  => isset( $f['topic_calibrated'] ) ? $f['topic_calibrated'] : null,
				'lexical'           => $f['lexical'],
				'semantic'          => $f['semantic'],
				'title_overlap'     => $f['title_overlap'],
				'heading_overlap'   => $f['heading_overlap'],
				'near_duplicate'    => $f['near_duplicate'],
				'intents'           => $f['intents'],
				'intent_confidence' => $f['intent_conf'],
				'focus_same'        => $f['focus_same'],
				'gsc_overlap'       => $f['gsc_overlap'],
				'gsc_available'     => $f['gsc_available'],
				'shared_terms'      => $f['shared_terms'],
				'title_difference'  => array( $f['title_only_a'], $f['title_only_b'] ),
				'pages'             => array(
					$a['id'] => array(
						'title' => $a['title'],
						'url'   => $a['url'],
						'role'  => $a['cluster_role'],
					),
					$b['id'] => array(
						'title' => $b['title'],
						'url'   => $b['url'],
						'role'  => $b['cluster_role'],
					),
				),
				'never_automatic'   => 'No canonical, 301, noindex, merge or deletion is applied by this plugin.',
			),
		);
	}

	/**
	 * Risk score used for prioritization (not a probability).
	 *
	 * @param string $category Category.
	 * @param array  $f        Features.
	 * @return float
	 */
	public static function risk_score( $category, array $f ) {
		$base = self::RISK_ORDER[ $category ] / 6;
		return round( 0.5 * $base + 0.25 * self::overlap_topic( $f ) + 0.15 * $f['title_overlap'] + ( $f['gsc_overlap'] > 0 ? 0.1 : 0 ), 4 );
	}

	/**
	 * Topic similarity used for overlap risk: with the calibrated semantic component when vectors
	 * exist (the raw cosine floor of unrelated pages must not raise every pair above the threshold).
	 *
	 * @param array $f Features.
	 * @return float
	 */
	private static function overlap_topic( array $f ) {
		return isset( $f['topic_calibrated'] ) ? (float) $f['topic_calibrated'] : (float) $f['topic'];
	}

	/**
	 * One title's significant tokens are (almost) all contained in the other title.
	 *
	 * @param array $f Features.
	 * @return bool
	 */
	private static function title_contained( array $f ) {
		return isset( $f['title_containment'] ) && $f['title_containment'] >= 0.75 && count( $f['title_shared'] ) >= 3;
	}

	/**
	 * Valid pillar/supporting relation inside one cluster.
	 *
	 * @param array $a Profile.
	 * @param array $b Profile.
	 * @param array $f Features.
	 * @return bool
	 */
	private static function is_pillar_supporting( array $a, array $b, array $f ) {
		if ( ! $f['same_cluster'] ) {
			return false;
		}
		$roles = array( $a['cluster_role'], $b['cluster_role'] );
		if ( ! ( in_array( 'pillar', $roles, true ) || in_array( 'hub', $roles, true ) ) || ! in_array( 'supporting', $roles, true ) ) {
			return false;
		}
		// The supporting page must narrow the pillar's topic. A page that only adds a generic modifier
		// ("giá rẻ", "cheap") to the pillar's head query targets the same query and may compete.
		$unique = 'supporting' === $a['cluster_role'] ? $f['title_only_a'] : $f['title_only_b'];
		return count( $unique ) >= 2 || self::has_facet_token( $unique );
	}

	/**
	 * Titles share a core but each has its own distinguishing token(s).
	 *
	 * @param array $f Features.
	 * @return bool
	 */
	private static function is_distinct_facet( array $f ) {
		if ( count( $f['title_shared'] ) < 2 ) {
			return false;
		}
		// The narrower page carries a distinguishing facet (duration, engine size, model…) that the
		// other title lacks: broad-vs-specific or sibling offers, not the same target query.
		foreach ( array( $f['title_only_a'], $f['title_only_b'] ) as $unique ) {
			if ( array() !== $unique && count( $unique ) <= 3 && self::has_facet_token( $unique ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Facet-like tokens: numbers/models (50cc, 110cc, 2024) and rental/duration units. Adjectives
	 * such as "giá rẻ" / "uy tín" are deliberately not facets.
	 *
	 * @param string[] $tokens Tokens.
	 * @return bool
	 */
	private static function has_facet_token( array $tokens ) {
		foreach ( $tokens as $t ) {
			if ( preg_match( '/\d/', (string) $t ) || in_array( (string) $t, self::FACET_WORDS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Both intent labels are at least medium confidence (or editorial).
	 *
	 * @param array $conf Confidences.
	 * @return bool
	 */
	private static function confident( array $conf ) {
		foreach ( $conf as $c ) {
			if ( ! in_array( $c, array( 'high', 'medium', 'editorial' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Groups risky pairs into connected components (group_key "cg-<min id>").
	 *
	 * @param array<int,array{0:int,1:int}> $pairs Document ID pairs.
	 * @return array<int,string> Document ID => group key.
	 */
	public static function components( array $pairs ) {
		$parent = array();
		$find   = function ( $x ) use ( &$parent, &$find ) {
			if ( ! isset( $parent[ $x ] ) ) {
				$parent[ $x ] = $x;
			}
			if ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $find( $parent[ $x ] );
			}
			return $parent[ $x ];
		};
		foreach ( $pairs as $p ) {
			$ra = $find( (int) $p[0] );
			$rb = $find( (int) $p[1] );
			if ( $ra !== $rb ) {
				$parent[ max( $ra, $rb ) ] = min( $ra, $rb );
			}
		}
		$out = array();
		foreach ( array_keys( $parent ) as $x ) {
			$out[ $x ] = 'cg-' . $find( $x );
		}
		return $out;
	}
}
