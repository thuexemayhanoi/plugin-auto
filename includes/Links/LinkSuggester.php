<?php
/**
 * M2 — internal link suggestions with reasons, anchors and exact locations.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Links;

use SemanticSEO\Analysis\Scorer;
use SemanticSEO\Graph\LinkGraph;
use SemanticSEO\Language\TextNormalizer;
use SemanticSEO\Lexical\Tokenizer;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Produces suggestions only; nothing is inserted automatically. Every gate that a candidate
 * passed or failed is recorded so a reviewer can see why it was (not) suggested.
 */
final class LinkSuggester {

	const VERSION = 'links-1';

	/**
	 * Suggestions for one source document.
	 *
	 * @param array $source     Source profile.
	 * @param array $candidates List of [profile, features].
	 * @param string $content   Source raw content (post_content).
	 * @return array<int,array> Findings.
	 */
	public static function suggest( array $source, array $candidates, $content ) {
		if ( 'post' !== $source['object_type'] || 'publish' !== $source['status'] || $source['excluded'] ) {
			return array();
		}
		$source_locked = LockService::is_locked( 'post', $source['object_id'], $source['url'] );
		$linked        = array_flip( LinkGraph::linked_targets( $source['id'] ) );
		$min           = (float) Settings::get( 'link_min_score', 0.30 );
		$max           = (int) Settings::get( 'link_max_per_source', 3 );
		$used_anchors  = array();
		$out           = array();

		foreach ( $candidates as $pair ) {
			list( $target, $f ) = $pair;
			$gates = self::gates( $source, $target, $linked );
			if ( true !== $gates ) {
				continue;
			}
			if ( $f['exact_duplicate'] || $f['near_duplicate'] >= (float) Settings::get( 'near_duplicate', 0.80 ) ) {
				continue; // Linking to a near-copy is not a useful suggestion (see cannibalization report).
			}
			$phrases = AnchorFinder::phrases( $target );
			$anchor  = AnchorFinder::find( $content, $phrases );
			$notes   = array();
			if ( null !== $anchor && ! self::anchor_is_distinctive( $source, $target, $anchor ) ) {
				$notes[] = 'candidate anchor "' . $anchor['text'] . '" only repeats the source topic; it would not tell readers why this target is different';
				$anchor  = null;
			}
			$quality = null === $anchor ? 0.0 : $anchor['quality'];
			if ( null !== $anchor ) {
				$generic = self::title_frequency( $anchor['text'], $source['locale'] );
				if ( $generic >= 4 ) {
					$quality *= 0.5;
					$notes[]  = 'generic anchor: the phrase appears in ' . $generic . ' page titles of this locale';
				}
			}
			if ( null !== $anchor ) {
				$key = TextNormalizer::normalize( $anchor['text'] );
				if ( isset( $used_anchors[ $key ] ) ) {
					continue; // One anchor text → one target per source.
				}
				$other = array_diff( LinkGraph::targets_for_anchor( $anchor['text'] ), array( $target['id'] ) );
				if ( array() !== $other ) {
					$quality -= 0.3;
					$notes[]  = 'anchor text already links to another URL on this site (document IDs: ' . implode( ',', array_slice( $other, 0, 5 ) ) . ')';
				}
			}
			$graph = Scorer::graph_usefulness( $source, $target );
			$score = Scorer::link_score( $f, max( 0.0, $quality ), $graph );
			if ( $score['score'] < $min ) {
				continue;
			}
			if ( null === $anchor && $f['topic'] < 0.25 ) {
				continue;
			}
			$confidence = 'low';
			if ( null !== $anchor && $score['score'] >= $min + 0.15 && array() === $notes ) {
				$confidence = 'high';
			} elseif ( null !== $anchor ) {
				$confidence = 'medium';
			}
			$reason = array();
			if ( array() !== $f['shared_terms'] ) {
				$reason[] = 'shared topic terms: ' . implode( ', ', array_slice( $f['shared_terms'], 0, 5 ) );
			}
			if ( null !== $f['semantic'] ) {
				$reason[] = 'semantic similarity ' . $f['semantic'];
			}
			if ( 0 === $target['inbound_count'] ) {
				$reason[] = 'target has no observed inbound internal links';
			}
			if ( $f['same_cluster'] ) {
				$reason[] = 'same topic cluster';
			}
			if ( null === $anchor ) {
				$reason[] = 'no natural anchor found in unprotected text — editorial insertion needed';
			}
			$out[] = array(
				'type'              => 'link',
				'check_id'          => 'internal_link',
				'document_id'       => $source['id'],
				'target_id'         => $target['id'],
				'locale'            => $source['locale'],
				'label'             => 'suggestion',
				'severity'          => 'info',
				'confidence'        => $confidence,
				'score'             => $score['score'],
				'algorithm_version' => self::VERSION . '/' . Scorer::VERSION,
				'evidence'          => array(
					'reason'          => implode( '; ', $reason ),
					'components'      => $score['components'],
					'features'        => array(
						'topic'          => $f['topic'],
						'title_overlap'  => $f['title_overlap'],
						'near_duplicate' => $f['near_duplicate'],
						'shared_terms'   => $f['shared_terms'],
					),
					'anchor'          => $anchor,
					'phrase_candidates' => array_slice( array_column( $phrases, 'phrase' ), 0, 5 ),
					'target'          => array(
						'url'           => $target['url'],
						'title'         => $target['title'],
						'inbound_count' => $target['inbound_count'],
						'cluster_role'  => $target['cluster_role'],
					),
					'source_locked'   => $source_locked,
					'source_hash'     => Util::sha256( $content ),
					'notes'           => $notes,
					'gates'           => array( 'same_locale', 'target_published', 'target_indexable', 'not_already_linked', 'not_self', 'not_excluded' ),
				),
			);
			if ( null !== $anchor ) {
				$used_anchors[ TextNormalizer::normalize( $anchor['text'] ) ] = true;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $out, 0, $max );
	}

	/**
	 * An anchor must describe the target, not just restate the source's own topic: it needs at least
	 * one significant token absent from the source title, unless it is the target's declared focus
	 * keyword or the target is the hub/pillar (or category archive) of the source's topic.
	 *
	 * @param array $source Source profile.
	 * @param array $target Target profile.
	 * @param array $anchor Anchor.
	 * @return bool
	 */
	public static function anchor_is_distinctive( array $source, array $target, array $anchor ) {
		if ( 'focus_keyword' === $anchor['phrase_source'] || 'term' === $target['object_type'] ) {
			return true;
		}
		if ( $target['cluster_id'] > 0 && $target['cluster_id'] === $source['cluster_id'] && in_array( $target['cluster_role'], array( 'pillar', 'hub' ), true ) ) {
			return true;
		}
		$lang   = in_array( $source['locale'], array( 'vi', 'en' ), true ) ? $source['locale'] : '';
		$tokens = Tokenizer::significant_set( $anchor['text'], $lang );
		return array() !== array_diff( $tokens, $source['title_set'] );
	}

	/**
	 * Number of published same-locale titles containing a phrase (anchor genericity).
	 *
	 * @param string $phrase Phrase.
	 * @param string $locale Locale.
	 * @return int
	 */
	public static function title_frequency( $phrase, $locale ) {
		return (int) \SemanticSEO\Database\Db::value(
			"SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND locale = %s AND title LIKE %s",
			array( (string) $locale, '%' . \SemanticSEO\Database\Db::esc_like_prefix( (string) $phrase ) )
		);
	}

	/**
	 * Validity gates for a source/target pair.
	 *
	 * @param array $source Source profile.
	 * @param array $target Target profile.
	 * @param array $linked Already linked target IDs (flipped).
	 * @return true|string True or failed gate.
	 */
	public static function gates( array $source, array $target, array $linked ) {
		if ( $source['id'] === $target['id'] ) {
			return 'self';
		}
		if ( $source['locale'] !== $target['locale'] ) {
			return 'locale';
		}
		if ( 'publish' !== $target['status'] ) {
			return 'target_not_published';
		}
		if ( ! $target['indexable'] ) {
			return 'target_not_indexable';
		}
		if ( $target['excluded'] ) {
			return 'target_excluded';
		}
		if ( isset( $linked[ $target['id'] ] ) ) {
			return 'already_linked';
		}
		if ( '' !== $source['translation_group'] && $source['translation_group'] === $target['translation_group'] ) {
			return 'translation';
		}
		return true;
	}
}
