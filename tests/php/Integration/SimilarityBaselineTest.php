<?php
/**
 * Site similarity baseline: the overlap gate counts only similarity above what this site's pages
 * usually share (one-topic sites), never below the model's own floor.
 *
 * Synthetic vectors test the rules; the real-model effect is measured in HybridEvaluationTest and
 * in the read-only pilot on a copy of the blog.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\Scorer;
use SemanticSEO\Database\Db;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\SimilarityBaseline;
use SemanticSEO\Semantic\VectorMath;

/**
 * @group embeddings
 */
final class SimilarityBaselineTest extends TestCase {

	public function test_quantile_and_deterministic_sample() {
		$this->assertEqualsWithDelta( 0.5, SimilarityBaseline::quantile( array( 0.0, 0.5, 1.0 ), 0.5 ), 1e-9 );
		$this->assertEqualsWithDelta( 0.25, SimilarityBaseline::quantile( array( 0.0, 0.5, 1.0 ), 0.25 ), 1e-9 );
		$this->assertSame( 0.0, SimilarityBaseline::quantile( array(), 0.5 ) );

		$vectors = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$v           = array_fill( 0, 8, 0.0 );
			$v[ $i ]     = 1.0;
			$vectors[]   = $v;
		}
		$this->assertCount( 28, SimilarityBaseline::sample( $vectors ), 'all pairs when there are few' );
		$many = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$many[] = VectorMath::normalize( array( 1.0, ( $i % 7 ) / 10, ( $i % 11 ) / 10 ) );
		}
		$a = SimilarityBaseline::sample( $many );
		$this->assertCount( SimilarityBaseline::MAX_PAIRS, $a, 'bounded sample' );
		$this->assertSame( $a, SimilarityBaseline::sample( $many ), 'same vectors, same sample' );
	}

	public function test_one_topic_site_raises_the_floor_and_diverse_site_keeps_it() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$profile = ModelProfile::active();
		$dim     = (int) $profile['dimension'];
		$docs    = Db::rows( "SELECT id, locale FROM {documents} WHERE object_type = 'post' AND status = 'publish' AND locale = 'vi' ORDER BY id" );
		$this->assertGreaterThanOrEqual( SimilarityBaseline::MIN_DOCS, count( $docs ) );

		// One-topic site: every page = 2 × shared direction + 1 × its own direction → cosine 0.8.
		$this->save_vectors(
			$docs,
			static function ( $i ) use ( $dim ) {
				$v           = array_fill( 0, $dim, 0.0 );
				$v[0]        = 2.0;
				$v[ 1 + $i ] = 1.0;
				return $v;
			}
		);
		$b = SimilarityBaseline::compute( $profile['model_key'] );
		$this->assertEqualsWithDelta( 0.8, $b['locales']['vi']['p50'], 1e-3 );
		$this->assertSame( count( $docs ), $b['locales']['vi']['documents'] );
		$cal = Scorer::calibration( 'vi' );
		$this->assertSame( 'site_median', $cal['source'] );
		$this->assertEqualsWithDelta( 0.80, $cal['floor'], 1e-9, 'median, capped 0.10 below the ceiling' );
		$this->assertEqualsWithDelta( 0.0, Scorer::calibrate( 0.8, $cal ), 1e-9, 'typical similarity of this site counts as nothing' );

		// No baseline for a language without vectors; model floor there.
		$this->assertSame( 'model', Scorer::calibration( 'en' )['source'] );

		// Disabled through the filter: the model floor.
		$off = static function () {
			return false;
		};
		add_filter( 'sseo_similarity_baseline_enabled', $off );
		$this->assertSame( 'model', Scorer::calibration( 'vi' )['source'] );
		$this->assertEqualsWithDelta( 0.40, Scorer::calibration( 'vi' )['floor'], 1e-9 );
		remove_filter( 'sseo_similarity_baseline_enabled', $off );

		// Measured with another model: ignored.
		$this->assertNull( SimilarityBaseline::median( 'vi', str_repeat( 'f', 40 ) ) );

		// Diverse site: unrelated directions → median 0, below the model floor → floor unchanged.
		$this->save_vectors(
			$docs,
			static function ( $i ) use ( $dim ) {
				$v           = array_fill( 0, $dim, 0.0 );
				$v[ 1 + $i ] = 1.0;
				return $v;
			}
		);
		SimilarityBaseline::compute( $profile['model_key'] );
		$cal = Scorer::calibration( 'vi' );
		$this->assertSame( 'model', $cal['source'] );
		$this->assertEqualsWithDelta( 0.40, $cal['floor'], 1e-9 );
	}

	public function test_site_wide_analysis_measures_the_baseline_and_findings_show_it() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$profile = ModelProfile::active();
		$dim     = (int) $profile['dimension'];
		$docs    = Db::rows( "SELECT id, locale FROM {documents} WHERE object_type = 'post' AND status = 'publish' ORDER BY id" );
		$this->save_vectors(
			$docs,
			static function ( $i ) use ( $dim ) {
				$v           = array_fill( 0, $dim, 0.0 );
				$v[0]        = 2.0;
				$v[ 1 + $i ] = 1.0;
				return $v;
			}
		);
		$this->run_analysis();
		$stored = get_option( SimilarityBaseline::OPTION );
		$this->assertSame( $profile['model_key'], $stored['model_key'] );
		$this->assertArrayHasKey( 'vi', $stored['locales'] );
		$f = Db::row( "SELECT evidence FROM {findings} WHERE type = 'cannibalization' AND status = 'open' LIMIT 1" );
		if ( null !== $f ) {
			$e = json_decode( $f['evidence'], true );
			$this->assertSame( 'site_median', $e['calibration']['source'] );
		}
	}

	/**
	 * Stores one summary vector per document.
	 *
	 * @param array    $docs Rows (id).
	 * @param callable $make Index → vector.
	 * @return void
	 */
	private function save_vectors( array $docs, $make ) {
		$profile = ModelProfile::active();
		foreach ( array_values( $docs ) as $i => $r ) {
			$chunk = Db::row( "SELECT chunk_index, content_hash FROM {chunks} WHERE document_id = %d AND kind = 'summary' LIMIT 1", array( (int) $r['id'] ) );
			if ( null === $chunk ) {
				continue;
			}
			EmbeddingRepository::save(
				array(
					'document_id'  => (int) $r['id'],
					'chunk_id'     => (int) $chunk['chunk_index'],
					'content_hash' => $chunk['content_hash'],
				),
				VectorMath::normalize( $make( $i ) ),
				$profile,
				'worker'
			);
		}
	}

	/**
	 * Runs a site-wide analysis job to completion.
	 *
	 * @return void
	 */
	private function run_analysis() {
		$job = \SemanticSEO\Jobs\JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		$this->assertIsArray( $job );
		$res = \SemanticSEO\Jobs\JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'], wp_json_encode( $res ) );
	}
}
