<?php
/**
 * M6 — precomputed related content (locale-aware, diversified, exclusion-aware).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Recommendations;

use SemanticSEO\Database\Db;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Computation happens only inside analysis jobs. The frontend reads the stored list (cached).
 */
final class RelatedContent {

	const VERSION      = 'related-1';
	const CACHE_GROUP  = 'sseo_related';
	const MMR_LAMBDA   = 0.75;

	/**
	 * Computes and stores the related list for a source document.
	 *
	 * @param array $source     Source profile.
	 * @param array $candidates List of [profile, features].
	 * @return array<int,array> Stored rows.
	 */
	public static function compute( array $source, array $candidates ) {
		$limit = (int) Settings::get( 'related_limit', 5 );
		$min   = (float) Settings::get( 'related_min_score', 0.15 );
		$near  = (float) Settings::get( 'near_duplicate', 0.80 );
		$pool  = array();
		foreach ( $candidates as $pair ) {
			list( $t, $f ) = $pair;
			if ( $t['id'] === $source['id'] || $t['locale'] !== $source['locale'] || 'post' !== $t['object_type'] ) {
				continue;
			}
			if ( 'publish' !== $t['status'] || ! $t['indexable'] || $t['excluded'] ) {
				continue;
			}
			if ( $f['near_duplicate'] >= $near || $f['exact_duplicate'] ) {
				continue; // Never recommend a near-copy.
			}
			if ( '' !== $source['translation_group'] && $source['translation_group'] === $t['translation_group'] ) {
				continue;
			}
			$score = $f['topic'] + ( $f['same_cluster'] ? 0.1 : 0.0 );
			if ( $score < $min ) {
				continue;
			}
			$pool[ $t['id'] ] = array(
				'profile' => $t,
				'score'   => $score,
				'f'       => $f,
			);
		}
		// Maximal Marginal Relevance for diversity.
		$selected = array();
		while ( count( $selected ) < $limit && array() !== $pool ) {
			$best_id  = 0;
			$best_mmr = -INF;
			foreach ( $pool as $id => $c ) {
				$max_sim = 0.0;
				foreach ( $selected as $s ) {
					$max_sim = max( $max_sim, LexicalIndex::cosine( $c['profile']['lexvec'], $s['profile']['lexvec'] ) );
				}
				$mmr = self::MMR_LAMBDA * $c['score'] - ( 1 - self::MMR_LAMBDA ) * $max_sim;
				if ( $mmr > $best_mmr ) {
					$best_mmr = $mmr;
					$best_id  = $id;
				}
			}
			$selected[] = $pool[ $best_id ];
			unset( $pool[ $best_id ] );
		}
		Db::delete( 'related', array( 'document_id' => (int) $source['id'] ) );
		$rows = array();
		foreach ( $selected as $rank => $s ) {
			$row = array(
				'document_id' => (int) $source['id'],
				'related_id'  => (int) $s['profile']['id'],
				'rank_pos'    => $rank + 1,
				'score'       => round( $s['score'], 4 ),
				'evidence'    => Util::json(
					array(
						'topic'        => $s['f']['topic'],
						'lexical'      => $s['f']['lexical'],
						'semantic'     => $s['f']['semantic'],
						'same_cluster' => $s['f']['same_cluster'],
						'shared_terms' => array_slice( $s['f']['shared_terms'], 0, 5 ),
						'version'      => self::VERSION,
					)
				),
				'updated_at'  => Util::now(),
			);
			Db::insert( 'related', $row );
			$rows[] = $row;
		}
		wp_cache_delete( 'doc_' . (int) $source['id'], self::CACHE_GROUP );
		return $rows;
	}

	/**
	 * Related posts for a WordPress post (frontend/API). Published, same-locale posts only.
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   Max items.
	 * @return array<int,array{post_id:int,title:string,url:string,score:float}>
	 */
	public static function for_post( $post_id, $limit = 5 ) {
		$doc_id = (int) Db::value( "SELECT id FROM {documents} WHERE object_type = 'post' AND object_id = %d", array( (int) $post_id ) );
		if ( $doc_id <= 0 ) {
			return array();
		}
		$cached = wp_cache_get( 'doc_' . $doc_id, self::CACHE_GROUP );
		if ( false === $cached ) {
			$cached = Db::rows(
				"SELECT d.object_id, d.title, d.url, r.score FROM {related} r JOIN {documents} d ON d.id = r.related_id WHERE r.document_id = %d AND d.status = 'publish' AND d.indexable = 1 AND d.excluded = 0 AND d.object_type = 'post' ORDER BY r.rank_pos ASC LIMIT 10",
				array( $doc_id )
			);
			wp_cache_set( 'doc_' . $doc_id, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		$out = array();
		foreach ( array_slice( (array) $cached, 0, max( 1, min( 10, (int) $limit ) ) ) as $r ) {
			// Final guard at render time: the post must still be public.
			if ( 'publish' !== get_post_status( (int) $r['object_id'] ) || post_password_required( (int) $r['object_id'] ) ) {
				continue;
			}
			$out[] = array(
				'post_id' => (int) $r['object_id'],
				'title'   => get_the_title( (int) $r['object_id'] ),
				'url'     => (string) get_permalink( (int) $r['object_id'] ),
				'score'   => (float) $r['score'],
			);
		}
		return $out;
	}

	/**
	 * Invalidates the cache for a document and anything pointing at it.
	 *
	 * @param int $doc_id Document ID.
	 * @return void
	 */
	public static function invalidate( $doc_id ) {
		wp_cache_delete( 'doc_' . (int) $doc_id, self::CACHE_GROUP );
		foreach ( Db::col( 'SELECT document_id FROM {related} WHERE related_id = %d', array( (int) $doc_id ) ) as $src ) {
			wp_cache_delete( 'doc_' . (int) $src, self::CACHE_GROUP );
		}
	}
}
