<?php
/**
 * Read helpers for the cluster hierarchy (Pillar → Hub → Silo).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Clustering;

use SemanticSEO\Database\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Ancestor lookups with a per-request cache.
 */
final class ClusterTree {

	/**
	 * Parent by cluster ID.
	 *
	 * @var array<int,int>|null
	 */
	private static $parents = null;

	/**
	 * Clears the cache (after a rebuild).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$parents = null;
	}

	/**
	 * The cluster and its ancestors, nearest first.
	 *
	 * @param int $cluster_id Cluster ID.
	 * @return int[]
	 */
	public static function lineage( $cluster_id ) {
		if ( null === self::$parents ) {
			self::$parents = array();
			foreach ( Db::rows( 'SELECT id, parent_id FROM {clusters}' ) as $r ) {
				self::$parents[ (int) $r['id'] ] = (int) $r['parent_id'];
			}
		}
		$out = array();
		$id  = (int) $cluster_id;
		while ( $id > 0 && ! in_array( $id, $out, true ) && count( $out ) < 10 ) {
			$out[] = $id;
			$id    = isset( self::$parents[ $id ] ) ? self::$parents[ $id ] : 0;
		}
		return $out;
	}

	/**
	 * Whether $ancestor is $cluster_id itself or one of its ancestors.
	 *
	 * @param int $ancestor   Candidate ancestor.
	 * @param int $cluster_id Cluster.
	 * @return bool
	 */
	public static function is_same_or_ancestor( $ancestor, $cluster_id ) {
		return $ancestor > 0 && in_array( (int) $ancestor, self::lineage( $cluster_id ), true );
	}

	/**
	 * Pillar/hub documents of the ancestor clusters (excluding the cluster itself).
	 *
	 * @param int $cluster_id Cluster.
	 * @return int[] Document IDs.
	 */
	public static function ancestor_pillars( $cluster_id ) {
		$ancestors = array_slice( self::lineage( $cluster_id ), 1 );
		if ( array() === $ancestors ) {
			return array();
		}
		return array_map(
			'intval',
			Db::col(
				"SELECT id FROM {documents} WHERE status = 'publish' AND cluster_id IN (" . Db::in( $ancestors ) . ") AND cluster_role IN ('pillar','hub') LIMIT 10",
				$ancestors
			)
		);
	}
}
