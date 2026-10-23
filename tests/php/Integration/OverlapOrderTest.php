<?php
/**
 * Overlap review order (REST, abilities, admin page): competition first, then pairs needing a
 * judgement, then vector-only pairs, then information; by score within a tier.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Services\Api;

/**
 * @group cannibalization
 */
final class OverlapOrderTest extends TestCase {

	public function test_findings_are_listed_by_tier_then_score() {
		$this->load_corpus();
		$this->run_full_pipeline();
		wp_set_current_user( $this->admin );

		// Make score alone disagree with the tiers: an information finding scores highest and a
		// competition finding lowest.
		$info = (int) Db::value( "SELECT id FROM {findings} WHERE type = 'cannibalization' AND status = 'open' AND check_id IN ('pillar_supporting','same_topic_different_intent') LIMIT 1" );
		$risk = (int) Db::value( "SELECT id FROM {findings} WHERE type = 'cannibalization' AND status = 'open' AND check_id IN ('exact_duplicate','near_duplicate','potential_competition') LIMIT 1" );
		$this->assertGreaterThan( 0, $info );
		$this->assertGreaterThan( 0, $risk );
		Db::query( 'UPDATE {findings} SET score = 0.999 WHERE id = %d', array( $info ) );
		Db::query( 'UPDATE {findings} SET score = 0.001 WHERE id = %d', array( $risk ) );

		$items = Api::cannibalization( array( 'limit' => 100 ) )['items'];
		$ids   = array_map( 'intval', array_column( $items, 'id' ) );
		$this->assertNotSame( $info, $ids[0], 'the highest score does not jump ahead of competition' );
		$this->assertContains( $risk, $ids );
		$this->assertGreaterThan( 5, count( $items ) );
		$tiers = array();
		foreach ( $items as $f ) {
			$tiers[] = self::tier( $f );
		}
		$this->assertGreaterThan( 1, count( array_unique( $tiers ) ), 'the fixture spans several tiers' );
		$sorted = $tiers;
		sort( $sorted );
		$this->assertSame( $sorted, $tiers, 'tiers never go back' );
		$this->assertSame( 0, $tiers[0], 'competition first' );
		foreach ( $items as $i => $f ) {
			if ( $i > 0 && $tiers[ $i ] === $tiers[ $i - 1 ] ) {
				$this->assertLessThanOrEqual( (float) $items[ $i - 1 ]['score'], (float) $f['score'], 'by score within a tier' );
			}
		}
	}

	/**
	 * Review tier of a finding.
	 *
	 * @param array $f Finding.
	 * @return int
	 */
	private static function tier( array $f ) {
		if ( in_array( $f['check_id'], array( 'exact_duplicate', 'near_duplicate', 'potential_competition', 'translation_mapping_conflict' ), true ) ) {
			return 0;
		}
		if ( 'distinct_facet' === $f['check_id'] || ( 'manual_review' === $f['check_id'] && 'low' !== $f['confidence'] ) ) {
			return 1;
		}
		return 'manual_review' === $f['check_id'] ? 2 : 3;
	}
}
