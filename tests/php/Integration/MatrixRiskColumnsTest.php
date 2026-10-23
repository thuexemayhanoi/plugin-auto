<?php
/**
 * SEO Matrix overlap columns: a document's risk is the highest open overlap finding on either side
 * of a pair; the `has_risk` filter and the `risk` sort agree with it.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Matrix\MatrixQuery;

/**
 * @group matrix
 */
final class MatrixRiskColumnsTest extends TestCase {

	public function test_risk_columns_cover_both_sides_of_a_pair() {
		$this->load_corpus();
		$this->run_full_pipeline();

		$expected = array();
		$groups   = array();
		foreach ( Db::rows( "SELECT document_id, target_id, score, label, group_key FROM {findings} WHERE type = 'cannibalization' AND status = 'open'" ) as $f ) {
			foreach ( array( (int) $f['document_id'], (int) $f['target_id'] ) as $doc ) {
				if ( 'risk_hypothesis' === $f['label'] ) {
					$expected[ $doc ] = max( isset( $expected[ $doc ] ) ? $expected[ $doc ] : 0.0, (float) $f['score'] );
				}
				if ( 0 === strpos( $f['group_key'], 'cg-' ) ) {
					$groups[ $doc ][] = $f['group_key'];
				}
			}
		}
		$this->assertNotEmpty( $expected, 'the fixture has overlap risks' );
		$targets_only = array_diff( array_map( 'intval', Db::col( "SELECT DISTINCT target_id FROM {findings} WHERE type = 'cannibalization' AND status = 'open' AND label = 'risk_hypothesis'" ) ), array_map( 'intval', Db::col( "SELECT DISTINCT document_id FROM {findings} WHERE type = 'cannibalization' AND status = 'open' AND label = 'risk_hypothesis'" ) ) );
		$this->assertNotEmpty( $targets_only, 'some documents are only ever the target of a pair' );

		$rows = MatrixQuery::query( array( 'per_page' => 100 ) )['items'];
		$this->assertGreaterThan( count( $expected ), count( $rows ) );
		foreach ( $rows as $r ) {
			$doc = (int) $r['document_id'];
			if ( isset( $expected[ $doc ] ) ) {
				$this->assertEqualsWithDelta( round( $expected[ $doc ], 3 ), $r['overlap_risk'], 1e-9, "risk of document $doc" );
			} else {
				$this->assertNull( $r['overlap_risk'], "no risk for document $doc" );
			}
			if ( isset( $groups[ $doc ] ) ) {
				$this->assertContains( $r['overlap_group'], $groups[ $doc ] );
			} else {
				$this->assertSame( '', $r['overlap_group'] );
			}
		}

		$risky = MatrixQuery::query(
			array(
				'has_risk' => 1,
				'per_page' => 100,
			)
		);
		$ids   = array_map( 'intval', array_column( $risky['items'], 'document_id' ) );
		sort( $ids );
		$want = array_keys( $expected );
		sort( $want );
		$this->assertSame( $want, $ids, 'has_risk lists exactly the documents on either side of a risk' );

		$sorted = MatrixQuery::query(
			array(
				'sort'     => 'risk',
				'order'    => 'desc',
				'per_page' => count( $expected ),
			)
		)['items'];
		$scores = array_column( $sorted, 'overlap_risk' );
		$this->assertNotContains( null, $scores, 'documents with a risk come first' );
		$copy = $scores;
		rsort( $copy );
		$this->assertSame( $copy, $scores );
	}
}
