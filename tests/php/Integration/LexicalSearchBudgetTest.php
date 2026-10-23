<?php
/**
 * BM25 candidate search: the postings budget drops the least informative query terms only, and
 * changes nothing while the budget is not reached.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Lexical\LexicalIndex;

/**
 * @group lexical
 */
final class LexicalSearchBudgetTest extends TestCase {

	public function test_budget_keeps_the_strongest_terms_and_is_neutral_below_the_limit() {
		$this->load_corpus();
		$this->run_full_pipeline();

		// Eight terms of the Vietnamese pages with distinct query weights (no ties in the ranking).
		$terms = Db::col( "SELECT p.term FROM {postings} p JOIN {documents} d ON d.id = p.document_id WHERE d.status = 'publish' AND d.locale = 'vi' GROUP BY p.term HAVING COUNT(*) >= 2 ORDER BY COUNT(*) DESC, p.term LIMIT 8" );
		$this->assertCount( 8, $terms );
		$query = array();
		foreach ( $terms as $i => $t ) {
			$query[ $t ] = 1.0 + $i / 10;
		}

		$unbounded = static function () {
			return PHP_INT_MAX;
		};
		add_filter( 'sseo_search_postings_budget', $unbounded );
		$all = LexicalIndex::search( $query, 'vi', 50 );
		remove_filter( 'sseo_search_postings_budget', $unbounded );
		$this->assertNotEmpty( $all );
		$this->assertSame( $all, LexicalIndex::search( $query, 'vi', 50 ), 'default budget: same result while the limit is not reached' );

		// The five highest-weighted terms (query weight × IDF), as search() ranks them.
		$df       = LexicalIndex::df( array_keys( $query ), 'vi' );
		$n        = LexicalIndex::stats( 'vi' )['n'];
		$weighted = array();
		foreach ( $query as $t => $w ) {
			$weighted[ $t ] = $w * LexicalIndex::idf( $df[ $t ], $n );
		}
		arsort( $weighted );
		$top5 = array_intersect_key( $query, array_slice( $weighted, 0, 5, true ) );

		$tiny = static function () {
			return 1;
		};
		add_filter( 'sseo_search_postings_budget', $tiny );
		$bounded = LexicalIndex::search( $query, 'vi', 50 );
		remove_filter( 'sseo_search_postings_budget', $tiny );
		$this->assertNotEmpty( $bounded, 'the five strongest terms always stay' );
		$this->assertSame( LexicalIndex::search( $top5, 'vi', 50 ), $bounded, 'only the weakest terms are dropped' );
	}
}
