<?php
/**
 * Cluster building on large sites: posts are read in pages, link counts per cluster member come
 * from a few grouped queries per cluster (not two per member), and the results are the same as
 * counting member by member. The SEO plugin's primary category is still respected.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Clustering\ClusterBuilder;
use SemanticSEO\Database\Db;

/**
 * @group analysis
 */
final class ClusterScaleTest extends TestCase {

	/**
	 * SQL statements counting links, captured while building clusters.
	 *
	 * @var string[]
	 */
	private $link_counts = array();

	/**
	 * Captures link-count statements.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function capture( $sql ) {
		if ( false !== strpos( $sql, 'sseo_links' ) && false !== stripos( $sql, 'COUNT(DISTINCT' ) ) {
			$this->link_counts[] = $sql;
		}
		return $sql;
	}

	public function test_link_counts_use_a_few_queries_per_cluster_and_match_member_by_member_counts() {
		$this->load_corpus();
		$this->run_full_pipeline();
		add_filter( 'query', array( $this, 'capture' ) );
		ClusterBuilder::build( 999 );
		remove_filter( 'query', array( $this, 'capture' ) );

		$clusters = Db::rows( 'SELECT id, member_count, evidence FROM {clusters} WHERE member_count > 0' );
		$members  = (int) array_sum( array_column( $clusters, 'member_count' ) );
		$this->assertGreaterThan( count( $clusters ), $members, 'fixture: clusters have several members' );
		$this->assertLessThanOrEqual( 2 * count( $clusters ), count( $this->link_counts ), 'not two queries per member (' . $members . ' members)' );

		// The grouped counts equal the member-by-member counts restricted to the cluster subtree.
		$checked = 0;
		$linked  = 0;
		foreach ( $clusters as $c ) {
			$ev   = json_decode( $c['evidence'], true );
			$tree = $this->subtree_members( (int) $c['id'] );
			foreach ( (array) $ev['candidates'] as $id => $cand ) {
				$in  = (int) Db::value( 'SELECT COUNT(DISTINCT source_id) FROM {links} WHERE target_id = %d AND source_id IN (' . Db::in( $tree ) . ')', array_merge( array( (int) $id ), $tree ) );
				$out = (int) Db::value( 'SELECT COUNT(DISTINCT target_id) FROM {links} WHERE source_id = %d AND target_id IN (' . Db::in( $tree ) . ')', array_merge( array( (int) $id ), $tree ) );
				$this->assertSame( $in, $cand['inbound_from_cluster'], "inbound of $id" );
				$this->assertSame( $out, $cand['outbound_to_cluster'], "outbound of $id" );
				++$checked;
				$linked += $in + $out > 0 ? 1 : 0;
			}
		}
		$this->assertGreaterThan( 5, $checked );
		$this->assertGreaterThan( 0, $linked, 'some members have links within their cluster (non-trivial counts)' );
	}

	public function test_the_seo_plugin_primary_category_is_respected() {
		$parent = wp_insert_term( 'Thuê xe máy', 'category' );
		$child  = wp_insert_term( 'Thuê xe theo tháng', 'category', array( 'parent' => (int) $parent['term_id'] ) );
		$body   = '<p>' . str_repeat( 'Thuê xe máy theo tháng ở Hà Nội với giá rõ ràng. ', 20 ) . '</p>';
		$plain  = self::factory()->post->create(
			array(
				'post_title'    => 'Thuê xe máy theo tháng',
				'post_content'  => $body,
				'post_category' => array( (int) $parent['term_id'], (int) $child['term_id'] ),
			)
		);
		$marked = self::factory()->post->create(
			array(
				'post_title'    => 'Thuê xe máy dài ngày',
				'post_content'  => $body,
				'post_category' => array( (int) $parent['term_id'], (int) $child['term_id'] ),
			)
		);
		update_post_meta( $marked, '_yoast_wpseo_primary_category', (int) $parent['term_id'] );
		$this->run_full_pipeline();

		$cluster_of = function ( $term_id ) {
			return (int) Db::value( "SELECT id FROM {clusters} WHERE term_id = %d AND taxonomy = 'category'", array( (int) $term_id ) );
		};
		$doc_cluster = function ( $post_id ) {
			return (int) Db::value( "SELECT cluster_id FROM {documents} WHERE object_type = 'post' AND object_id = %d", array( (int) $post_id ) );
		};
		$this->assertSame( $cluster_of( $child['term_id'] ), $doc_cluster( $plain ), 'deepest category without a primary category' );
		$this->assertSame( $cluster_of( $parent['term_id'] ), $doc_cluster( $marked ), 'Yoast primary category wins' );
	}

	/**
	 * Members of a cluster and of its descendant clusters.
	 *
	 * @param int $cid Cluster ID.
	 * @return int[]
	 */
	private function subtree_members( $cid ) {
		$ids  = array_map( 'intval', Db::col( "SELECT id FROM {documents} WHERE cluster_id = %d AND object_type = 'post' AND status = 'publish'", array( $cid ) ) );
		$kids = array_map( 'intval', Db::col( 'SELECT id FROM {clusters} WHERE parent_id = %d', array( $cid ) ) );
		foreach ( $kids as $k ) {
			$ids = array_merge( $ids, $this->subtree_members( $k ) );
		}
		return array() === $ids ? array( 0 ) : array_values( array_unique( $ids ) );
	}
}
