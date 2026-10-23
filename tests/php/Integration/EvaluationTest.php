<?php
/**
 * Relevance evaluation on the LABELLED TEST FIXTURE corpus (tests/fixtures/corpus.php).
 *
 * Measures precision@k / recall@k of internal-link suggestions and related content against the
 * human labels, the false-positive rate of overlap reports on "must not compete" controls, recall
 * on expected overlaps, and that translations are never compared. Results are written to
 * tests/e2e/artifacts/evaluation.json. These are fixture numbers (lexical tier, 28 documents),
 * not a claim about any real site or about SEO outcomes.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

/**
 * @group evaluation
 */
final class EvaluationTest extends TestCase {

	use RelevanceMetrics;

	public function test_relevance_on_labelled_fixture() {
		$corpus = $this->load_corpus();
		$this->run_full_pipeline();
		$this->index_fixture_docs();
		$labels = $corpus['labels'];
		$report = array_merge(
			array(
				'source'  => 'LABELLED TEST FIXTURE (tests/fixtures/corpus.php), lexical tier, no model',
				'docs'    => count( $this->key_by_doc ),
				'sources' => count( $labels['relevant'] ),
			),
			$this->measure( $labels )
		);
		self::write_artifact( 'evaluation.json', $report );
		fwrite( STDERR, "\nEVALUATION " . wp_json_encode( array( 'links' => $report['links'], 'related' => $report['related'], 'overlap' => $report['overlap'] ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Regression floors (set below the measured values; they guard against silent quality loss).
		$this->assertSame( 0, $report['overlap']['false_positives_on_controls'], 'No false positives on must-not-compete controls.' );
		$this->assertSame( count( $labels['expected_overlap'] ), $report['overlap']['expected_overlaps_found'], 'All expected overlaps found.' );
		$this->assertSame( 0, $report['overlap']['cross_locale_pairs_compared'], 'Translations / other locales are never compared.' );
		// Measured on this fixture (2026-10-23): links P_returned 0.786, R@3 0.244; related P@3 0.604, R@5 0.794.
		$this->assertGreaterThanOrEqual( 0.70, $report['links']['precision_of_returned_items'] );
		$this->assertGreaterThanOrEqual( 0.20, $report['links']['recall@3'] );
		$this->assertGreaterThanOrEqual( 0.55, $report['related']['precision@3'] );
		$this->assertGreaterThanOrEqual( 0.75, $report['related']['recall@5'] );
	}
}
