<?php
/**
 * M4 — taxonomy-first Pillar / Hub / Silo / Supporting clusters and classification suggestions.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Clustering;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Database\Db;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Uses existing taxonomy as the backbone; never creates terms and never edits posts. Roles are
 * editorial recommendations that administrators can override and lock.
 */
final class ClusterBuilder {

	const VERSION = 'clusters-2';

	/**
	 * Posts read per query; only slim profiles are kept, so memory does not grow with page size.
	 */
	const PAGE = 500;

	/**
	 * Members per link-count query.
	 */
	const COUNT_CHUNK = 1000;

	/**
	 * Post meta keys of SEO plugins' primary category.
	 */
	const PRIMARY_KEYS = array( '_yoast_wpseo_primary_category', 'rank_math_primary_category' );

	/**
	 * Builds clusters for every locale.
	 *
	 * @param int $run_id Analysis job ID.
	 * @return array Summary.
	 */
	public static function build( $run_id ) {
		$summary = array();
		foreach ( Db::col( "SELECT DISTINCT locale FROM {documents} WHERE status = 'publish'" ) as $locale ) {
			$summary[ $locale ] = self::build_locale( (string) $locale, (int) $run_id );
		}
		ClusterTree::flush();
		return $summary;
	}

	/**
	 * Builds the clusters of one locale.
	 *
	 * @param string $locale Locale.
	 * @param int    $run_id Run ID.
	 * @return array
	 */
	private static function build_locale( $locale, $run_id ) {
		$term_rows = Db::rows( "SELECT * FROM {documents} WHERE object_type = 'term' AND status = 'publish' AND locale = %s ORDER BY id ASC", array( $locale ) );
		$terms     = array();
		foreach ( $term_rows as $row ) {
			$tj                               = json_decode( (string) $row['terms_json'], true );
			$t                                = is_array( $tj ) && isset( $tj[0] ) ? $tj[0] : array();
			$terms[ (int) $row['object_id'] ] = array(
				'doc_id'   => (int) $row['id'],
				'taxonomy' => (string) $row['subtype'],
				'name'     => (string) $row['title'],
				'parent'   => isset( $t['parent'] ) ? (int) $t['parent'] : 0,
			);
		}
		$default_cat = (int) get_option( 'default_category' );

		// 1. Upsert cluster rows (taxonomy backbone).
		$cluster_of = array();
		foreach ( $terms as $term_id => $t ) {
			if ( $term_id === $default_cat && 'category' === $t['taxonomy'] ) {
				continue;
			}
			$depth    = self::depth( $term_id, $terms );
			$role     = 0 === $depth ? 'pillar' : ( 1 === $depth ? 'hub' : 'silo' );
			$existing = Db::row( 'SELECT * FROM {clusters} WHERE locale = %s AND taxonomy = %s AND term_id = %d', array( $locale, $t['taxonomy'], $term_id ) );
			if ( null === $existing ) {
				$id = Db::insert(
					'clusters',
					array(
						'locale'     => $locale,
						'label'      => Util::truncate( $t['name'], 250 ),
						'source'     => 'taxonomy',
						'taxonomy'   => $t['taxonomy'],
						'term_id'    => $term_id,
						'role'       => $role,
						'updated_at' => Util::now(),
					)
				);
			} else {
				$id = (int) $existing['id'];
				if ( ! (int) $existing['locked'] ) {
					Db::update(
						'clusters',
						array(
							'label'      => Util::truncate( $t['name'], 250 ),
							'role'       => $role,
							'updated_at' => Util::now(),
						),
						array( 'id' => $id )
					);
				}
			}
			$cluster_of[ $term_id ] = $id;
		}
		foreach ( $terms as $term_id => $t ) {
			if ( isset( $cluster_of[ $term_id ] ) ) {
				$parent = $t['parent'] > 0 && isset( $cluster_of[ $t['parent'] ] ) ? $cluster_of[ $t['parent'] ] : 0;
				Db::update( 'clusters', array( 'parent_id' => $parent ), array( 'id' => $cluster_of[ $term_id ] ) );
			}
		}

		// 2. Assign posts to their primary (or deepest) term's cluster unless locked. Posts are read in
		// pages and only a slim profile is kept (on a 20,000-post site, all full rows and profiles at
		// once needed about 760 MB and broke a 256 MB WP-Cron request).
		$profiles = array();
		$members  = array();
		$after    = 0;
		do {
			$page    = Db::rows(
				"SELECT id, object_id, subtype, word_count, excluded, cluster_id, cluster_locked, terms_json, signals FROM {documents} WHERE object_type = 'post' AND status = 'publish' AND locale = %s AND id > %d ORDER BY id ASC LIMIT %d",
				array( $locale, $after, self::PAGE )
			);
			$fetched = count( $page );
			$primary = self::primary_meta( array_map( 'intval', array_column( $page, 'object_id' ) ) );
			foreach ( $page as $row ) {
				$after                = (int) $row['id'];
				$p                    = self::slim_profile( $row );
				$profiles[ $p['id'] ] = $p;
				if ( (int) $row['cluster_locked'] && (int) $row['cluster_id'] > 0 ) {
					$members[ (int) $row['cluster_id'] ][] = $p['id'];
					continue;
				}
				$terms_json = json_decode( (string) $row['terms_json'], true );
				$term_id    = self::primary_term(
					array(
						'terms'   => is_array( $terms_json ) ? $terms_json : array(),
						'primary' => isset( $primary[ $p['object_id'] ] ) ? $primary[ $p['object_id'] ] : array(),
					),
					$terms,
					$default_cat
				);
				$cid        = ( $term_id > 0 && isset( $cluster_of[ $term_id ] ) ) ? $cluster_of[ $term_id ] : 0;
				if ( $cid > 0 && LockService::is_cluster_locked( $cid ) && (int) $row['cluster_id'] !== $cid ) {
					$cid = (int) $row['cluster_id']; // Locked cluster: membership frozen.
				}
				$profiles[ $p['id'] ]['cluster_id'] = $cid;
				if ( $cid > 0 ) {
					$members[ $cid ][] = $p['id'];
				}
				Db::update(
					'documents',
					array(
						'cluster_id'   => $cid,
						'cluster_role' => $cid > 0 ? 'supporting' : '',
					),
					array( 'id' => $p['id'] )
				);
			}
		} while ( self::PAGE === $fetched );

		// 3. Centroids, recommended pillar/hub page per cluster. Pillars are linked from (and link to)
		// pages of the whole subtree, not only from direct members, so links are counted per subtree.
		$children = array();
		foreach ( $cluster_of as $term_id => $cid ) {
			$parent_term = isset( $terms[ $term_id ] ) ? (int) $terms[ $term_id ]['parent'] : 0;
			if ( $parent_term > 0 && isset( $cluster_of[ $parent_term ] ) ) {
				$children[ $cluster_of[ $parent_term ] ][] = $cid;
			}
		}
		$subtree   = static function ( $cid ) use ( &$subtree, $children, $members ) {
			$ids = isset( $members[ $cid ] ) ? $members[ $cid ] : array();
			foreach ( isset( $children[ $cid ] ) ? $children[ $cid ] : array() as $child ) {
				$ids = array_merge( $ids, $subtree( $child ) );
			}
			return $ids;
		};
		$centroids = array();
		foreach ( Db::rows( 'SELECT * FROM {clusters} WHERE locale = %s', array( $locale ) ) as $c ) {
			$cid                            = (int) $c['id'];
			$ids                            = isset( $members[ $cid ] ) ? $members[ $cid ] : array();
			$cent                           = self::centroid( $ids, $profiles );
			$centroids[ $cid ]              = array(
				'vec'   => $cent,
				'label' => $c['label'],
				'term'  => (int) $c['term_id'],
				'tax'   => (string) $c['taxonomy'],
				'role'  => (string) $c['role'],
			);
			$best                           = 0;
			$best_score                     = -1.0;
			$evidence                       = array();
			$tree                           = array() === $ids ? array() : array_values( array_unique( $subtree( $cid ) ) );
			list( $in_counts, $out_counts ) = self::subtree_link_counts( $ids, $tree );
			foreach ( $ids as $id ) {
				$p               = $profiles[ $id ];
				$inbound         = isset( $in_counts[ $id ] ) ? $in_counts[ $id ] : 0;
				$outbound        = isset( $out_counts[ $id ] ) ? $out_counts[ $id ] : 0;
				$central         = LexicalIndex::cosine( self::lexvec( $p ), $cent );
				$score           = 2 * $inbound + 1.5 * $outbound + 3 * $central + ( 'page' === $p['subtype'] ? 0.5 : 0 ) + min( 1.0, $p['word_count'] / 1500 );
				$evidence[ $id ] = array(
					'inbound_from_cluster' => $inbound,
					'outbound_to_cluster'  => $outbound,
					'centrality'           => round( $central, 3 ),
					'is_page'              => 'page' === $p['subtype'],
					'word_count'           => $p['word_count'],
					'score'                => round( $score, 3 ),
				);
				if ( $score > $best_score ) {
					$best_score = $score;
					$best       = $id;
				}
			}
			uasort(
				$evidence,
				static function ( $x, $y ) {
					return $y['score'] <=> $x['score'];
				}
			);
			$pillar_role = 'pillar' === $c['role'] ? 'pillar' : 'hub';
			if ( $best > 0 && count( $ids ) >= 2 ) {
				$row = Db::row( 'SELECT cluster_locked FROM {documents} WHERE id = %d', array( $best ) );
				if ( null !== $row && ! (int) $row['cluster_locked'] ) {
					Db::update( 'documents', array( 'cluster_role' => $pillar_role ), array( 'id' => $best ) );
				}
			}
			$update = array(
				'recommended_pillar_id' => count( $ids ) >= 2 ? $best : 0,
				'member_count'          => count( $ids ),
				'evidence'              => Util::json(
					array(
						'candidates' => array_slice( $evidence, 0, 5, true ),
						'method'     => '2×inbound links from the cluster subtree + 1.5×outbound links to it + 3×centrality + page bonus + length factor',
						'version'    => self::VERSION,
					)
				),
				'updated_at'            => Util::now(),
			);
			Db::update( 'clusters', $update, array( 'id' => $cid ) );
		}

		// 4. Classification suggestions for uncategorized / possibly miscategorized posts.
		$suggested = 0;
		foreach ( $profiles as $id => $p ) {
			$lexvec = self::lexvec( $p );
			if ( $p['excluded'] || array() === $lexvec ) {
				continue;
			}
			$scores = array();
			foreach ( $centroids as $cid => $c ) {
				if ( array() !== $c['vec'] ) {
					$scores[ $cid ] = LexicalIndex::cosine( $lexvec, $c['vec'] );
				}
			}
			if ( array() === $scores ) {
				continue;
			}
			arsort( $scores );
			$best_cid = (int) key( $scores );
			$best     = (float) current( $scores );
			$current  = (int) $p['cluster_id'];
			$cur_sim  = $current > 0 && isset( $scores[ $current ] ) ? $scores[ $current ] : 0.0;
			$check    = '';
			if ( 0 === $current && $best >= 0.15 ) {
				$check = 'suggested_category';
			} elseif ( $current > 0 && $best_cid !== $current && $best >= 0.25 && $best >= 3 * max( 0.01, $cur_sim ) ) {
				$check = 'possible_miscategorization';
			}
			if ( '' === $check ) {
				continue;
			}
			$alts = array();
			foreach ( array_slice( $scores, 0, 3, true ) as $cid => $sim ) {
				$alts[] = array(
					'cluster_id' => $cid,
					'label'      => $centroids[ $cid ]['label'],
					'term_id'    => $centroids[ $cid ]['term'],
					'taxonomy'   => $centroids[ $cid ]['tax'],
					'similarity' => round( $sim, 3 ),
				);
			}
			FindingRepository::upsert(
				array(
					'type'              => 'classification',
					'check_id'          => $check,
					'document_id'       => $id,
					'target_id'         => 0,
					'locale'            => $locale,
					'label'             => 'suggestion',
					'severity'          => 'info',
					'confidence'        => $best >= 0.35 ? 'medium' : 'low',
					'score'             => round( $best, 4 ),
					'algorithm_version' => self::VERSION,
					'evidence'          => array(
						'current_cluster'    => $current,
						'current_similarity' => round( $cur_sim, 3 ),
						'suggested'          => $alts[0],
						'alternatives'       => $alts,
						'note'               => 'Existing categories only; no category is created. Applying requires an approved change.',
					),
				),
				$run_id
			);
			++$suggested;
		}
		return array(
			'clusters'                   => count( $cluster_of ),
			'posts'                      => count( $profiles ),
			'classification_suggestions' => $suggested,
		);
	}

	/**
	 * The fields of a post the clustering needs; the lexical vector is kept JSON-encoded.
	 *
	 * @param array $row Document row (selected columns).
	 * @return array
	 */
	private static function slim_profile( array $row ) {
		$signals = json_decode( (string) $row['signals'], true );
		$lexvec  = is_array( $signals ) && isset( $signals['lexvec'] ) && is_array( $signals['lexvec'] ) ? $signals['lexvec'] : array();
		return array(
			'id'         => (int) $row['id'],
			'object_id'  => (int) $row['object_id'],
			'subtype'    => (string) $row['subtype'],
			'word_count' => (int) $row['word_count'],
			'excluded'   => (int) $row['excluded'],
			'cluster_id' => (int) $row['cluster_id'],
			'lex'        => array() === $lexvec ? '' : (string) wp_json_encode( $lexvec ),
		);
	}

	/**
	 * Decoded lexical vector of a slim profile.
	 *
	 * @param array $p Slim profile.
	 * @return array<string,float>
	 */
	private static function lexvec( array $p ) {
		if ( '' === $p['lex'] ) {
			return array();
		}
		$v = json_decode( $p['lex'], true );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * SEO plugins' primary category of a page of posts, in one query (first value per key, as
	 * get_post_meta() returns it), without filling the object cache with every post's meta.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int,array<string,int>>
	 */
	private static function primary_meta( array $post_ids ) {
		$out = array();
		if ( array() === $post_ids ) {
			return $out;
		}
		$rows = Db::rows(
			'SELECT post_id, meta_key, meta_value FROM {postmeta} WHERE post_id IN (' . Db::in( $post_ids ) . ') AND meta_key IN (' . Db::in( self::PRIMARY_KEYS, '%s' ) . ') ORDER BY meta_id ASC',
			array_merge( $post_ids, self::PRIMARY_KEYS )
		);
		foreach ( $rows as $m ) {
			if ( ! isset( $out[ (int) $m['post_id'] ][ $m['meta_key'] ] ) ) {
				$out[ (int) $m['post_id'] ][ $m['meta_key'] ] = (int) $m['meta_value'];
			}
		}
		return $out;
	}

	/**
	 * Links from the cluster subtree to each member and from each member to the subtree, counted
	 * with a few grouped queries per cluster (it was two queries per member).
	 *
	 * @param int[] $ids  Members.
	 * @param int[] $tree Subtree documents.
	 * @return array{0:array<int,int>,1:array<int,int>} Inbound and outbound counts by member.
	 */
	private static function subtree_link_counts( array $ids, array $tree ) {
		$in  = array();
		$out = array();
		if ( array() === $ids || array() === $tree ) {
			return array( $in, $out );
		}
		foreach ( array_chunk( $ids, self::COUNT_CHUNK ) as $chunk ) {
			$args = array_merge( $chunk, $tree );
			foreach ( Db::rows( 'SELECT target_id AS id, COUNT(DISTINCT source_id) AS n FROM {links} WHERE target_id IN (' . Db::in( $chunk ) . ') AND source_id IN (' . Db::in( $tree ) . ') GROUP BY target_id', $args ) as $r ) {
				$in[ (int) $r['id'] ] = (int) $r['n'];
			}
			foreach ( Db::rows( 'SELECT source_id AS id, COUNT(DISTINCT target_id) AS n FROM {links} WHERE source_id IN (' . Db::in( $chunk ) . ') AND target_id IN (' . Db::in( $tree ) . ') GROUP BY source_id', $args ) as $r ) {
				$out[ (int) $r['id'] ] = (int) $r['n'];
			}
		}
		return array( $in, $out );
	}

	/**
	 * Depth of a term in its hierarchy.
	 *
	 * @param int   $term_id Term ID.
	 * @param array $terms   Terms map.
	 * @return int
	 */
	private static function depth( $term_id, array $terms ) {
		$depth = 0;
		$seen  = array();
		while ( isset( $terms[ $term_id ] ) && $terms[ $term_id ]['parent'] > 0 && ! isset( $seen[ $term_id ] ) && $depth < 10 ) {
			$seen[ $term_id ] = true;
			$term_id          = $terms[ $term_id ]['parent'];
			++$depth;
		}
		return $depth;
	}

	/**
	 * Primary term: SEO plugin primary category when set, else the deepest assigned term.
	 *
	 * @param array $p           Assigned terms and SEO plugins' primary category meta of the post.
	 * @param array $terms       Term map (in-scope term documents of this locale).
	 * @param int   $default_cat Default ("Uncategorized") category ID.
	 * @return int
	 */
	private static function primary_term( array $p, array $terms, $default_cat ) {
		$assigned = array();
		foreach ( $p['terms'] as $t ) {
			$tid = (int) $t['term_id'];
			if ( isset( $terms[ $tid ] ) && $tid !== (int) $default_cat ) {
				$assigned[] = $tid;
			}
		}
		if ( array() === $assigned ) {
			return 0;
		}
		foreach ( self::PRIMARY_KEYS as $key ) {
			$primary = isset( $p['primary'][ $key ] ) ? (int) $p['primary'][ $key ] : 0;
			if ( $primary > 0 && in_array( $primary, $assigned, true ) ) {
				return $primary;
			}
		}
		usort(
			$assigned,
			static function ( $a, $b ) use ( $terms ) {
				$by_depth = self::depth( $b, $terms ) <=> self::depth( $a, $terms );
				return 0 !== $by_depth ? $by_depth : $a <=> $b;
			}
		);
		return $assigned[0];
	}

	/**
	 * Normalized centroid of member lexical vectors (top 100 terms).
	 *
	 * @param int[] $ids      Member IDs.
	 * @param array $profiles Profiles.
	 * @return array<string,float>
	 */
	private static function centroid( array $ids, array $profiles ) {
		$sum = array();
		foreach ( $ids as $id ) {
			foreach ( self::lexvec( $profiles[ $id ] ) as $t => $w ) {
				$sum[ $t ] = ( isset( $sum[ $t ] ) ? $sum[ $t ] : 0.0 ) + $w;
			}
		}
		arsort( $sum );
		return LexicalIndex::normalize( array_slice( $sum, 0, 100, true ) );
	}
}
