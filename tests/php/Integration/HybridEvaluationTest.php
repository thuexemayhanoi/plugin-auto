<?php
/**
 * Hybrid (lexical + REAL Qwen3-Embedding-0.6B) evaluation on the LABELLED TEST FIXTURE corpus.
 *
 * Runs only when SSEO_EMBED_CMD points to tests/real-model/embed_items.py with verified model files
 * (the "Real Qwen3-Embedding-0.6B" GitHub Actions workflow); skipped everywhere else. The vectors go
 * through the plugin's own queue (claim → complete, lease tokens, model_key checks), then the
 * analysis is re-run for a grid of semantic weights and measured exactly like EvaluationTest.
 * Writes tests/e2e/artifacts/evaluation-hybrid.json. Fixture numbers, not claims about a real site.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Capabilities;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Semantic\EmbeddingQueue;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\VectorMath;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

/**
 * @group real-model
 */
final class HybridEvaluationTest extends TestCase {

	use RelevanceMetrics;

	/**
	 * Summary statistics of a list of numbers.
	 *
	 * @param float[] $xs Values.
	 * @return array|null
	 */
	private static function stats( array $xs ) {
		if ( array() === $xs ) {
			return null;
		}
		return array(
			'n'    => count( $xs ),
			'min'  => round( min( $xs ), 4 ),
			'mean' => round( array_sum( $xs ) / count( $xs ), 4 ),
			'max'  => round( max( $xs ), 4 ),
		);
	}

	/**
	 * Re-runs the full analysis with the current settings.
	 *
	 * @return void
	 */
	private function reanalyze() {
		wp_set_current_user( $this->admin );
		$job = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		$this->assertIsArray( $job );
		$res = JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'], 'analysis: ' . wp_json_encode( $res ) );
	}

	public function test_hybrid_relevance_with_real_model() {
		$cmd = (string) getenv( 'SSEO_EMBED_CMD' );
		if ( '' === $cmd ) {
			$this->markTestSkipped( 'SSEO_EMBED_CMD not set (real model files are only available in the real-model CI workflow).' );
		}
		$dtype = getenv( 'SSEO_EMBED_DTYPE' ) ? (string) getenv( 'SSEO_EMBED_DTYPE' ) : 'q4f16';
		Settings::update( array( 'model_dtype' => $dtype ) );
		$profile = ModelProfile::active();
		$this->assertSame( $dtype, $profile['dtype'] );

		$corpus = $this->load_corpus();
		$this->run_full_pipeline();
		$this->index_fixture_docs();
		$labels   = $corpus['labels'];
		$baseline = $this->measure( $labels );

		// Embed every queued chunk with the real model through the plugin's queue.
		$job = JobRepository::create( 'embed', array(), $this->admin, 'test' );
		for ( $i = 0; $i < 6; $i++ ) {
			JobRunner::tick( 10, (int) $job['id'] );
		}
		$queued = array_sum( EmbeddingQueue::counts( (int) $job['id'] ) );
		$this->assertGreaterThan( 0, $queued );
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_worker' ) ) );

		$proc = proc_open( $cmd . ' --dtype ' . escapeshellarg( $dtype ) . ' --dimension ' . (int) $profile['dimension'], array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => STDERR ), $pipes ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
		$this->assertIsResource( $proc );
		$ready = json_decode( (string) fgets( $pipes[1] ), true );
		$this->assertTrue( is_array( $ready ) && true === $ready['ready'], 'model self-test: ' . wp_json_encode( $ready ) );

		$stored  = 0;
		$t0      = microtime( true );
		$roles   = array();
		$kinds   = array();
		$started = time();
		do {
			$claim = Api::embedding_claim( array( 'limit' => 16 ) );
			$this->assertIsArray( $claim );
			if ( array() === $claim['items'] ) {
				break;
			}
			$req = array( 'items' => array() );
			foreach ( $claim['items'] as $item ) {
				$req['items'][]  = array(
					'text' => $item['text'],
					'role' => $item['role'],
				);
				$roles[ $item['role'] ] = isset( $roles[ $item['role'] ] ) ? $roles[ $item['role'] ] + 1 : 1;
				$kinds[ $item['kind'] ] = isset( $kinds[ $item['kind'] ] ) ? $kinds[ $item['kind'] ] + 1 : 1;
			}
			fwrite( $pipes[0], wp_json_encode( $req ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$out = json_decode( (string) fgets( $pipes[1] ), true );
			$this->assertCount( count( $claim['items'] ), $out['vectors'] );
			$results = array();
			foreach ( $claim['items'] as $n => $item ) {
				$results[] = array(
					'queue_id' => $item['queue_id'],
					'vector'   => $out['vectors'][ $n ],
				);
			}
			$res     = Api::embedding_complete(
				array(
					'lease_token' => $claim['lease_token'],
					'model_key'   => $profile['model_key'],
					'results'     => $results,
					'source'      => 'worker',
				)
			);
			$this->assertIsArray( $res, wp_json_encode( $res ) );
			$this->assertSame( array(), $res['rejected'] );
			$stored += $res['stored'];
		} while ( time() - $started < 1800 );
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		proc_close( $proc );
		$seconds = round( microtime( true ) - $t0, 2 );
		$this->assertSame( $queued, $stored, 'every queued item stored' );
		wp_set_current_user( $this->admin );
		JobRunner::tick( 10, (int) $job['id'] );
		$this->assertSame( 'completed', JobRepository::get( (int) $job['id'] )['status'] );

		// Similarity distributions of the stored (document summary) vectors.
		$doc_ids = array_keys( $this->key_by_doc );
		$vecs    = EmbeddingRepository::summary_vectors( $doc_ids, $profile['model_key'] );
		$by_key  = array();
		foreach ( $vecs as $doc_id => $v ) {
			$by_key[ $this->key_by_doc[ $doc_id ] ] = $v;
		}
		$sim  = static function ( $a, $b ) use ( $by_key ) {
			return isset( $by_key[ $a ], $by_key[ $b ] ) ? VectorMath::dot( $by_key[ $a ], $by_key[ $b ] ) : null;
		};
		$lang = array();
		$grp  = array();
		foreach ( require dirname( __DIR__, 2 ) . '/fixtures/corpus.php' as $section => $rows ) {
			if ( 'docs' !== $section ) {
				continue;
			}
			foreach ( $rows as $d ) {
				$lang[ $d['key'] ] = $d['lang'];
				$grp[ $d['key'] ]  = isset( $d['group'] ) ? $d['group'] : '';
			}
		}
		$relevant = array();
		$other    = array();
		foreach ( $labels['relevant'] as $src => $rel ) {
			foreach ( array_keys( $by_key ) as $k ) {
				if ( $k === $src || $lang[ $k ] !== $lang[ $src ] || null === $sim( $src, $k ) ) {
					continue;
				}
				if ( in_array( $k, $rel, true ) ) {
					$relevant[] = $sim( $src, $k );
				} else {
					$other[] = $sim( $src, $k );
				}
			}
		}
		$controls = array();
		foreach ( $labels['not_competition'] as $pair ) {
			if ( null !== $sim( $pair[0], $pair[1] ) && $lang[ $pair[0] ] === $lang[ $pair[1] ] ) {
				$controls[] = $sim( $pair[0], $pair[1] );
			}
		}
		$overlaps = array();
		foreach ( $labels['expected_overlap'] as $exp ) {
			if ( null !== $sim( $exp[0], $exp[1] ) ) {
				$overlaps[] = $sim( $exp[0], $exp[1] );
			}
		}
		$translations = array();
		$cross_other  = array();
		foreach ( array_keys( $by_key ) as $a ) {
			foreach ( array_keys( $by_key ) as $b ) {
				if ( 'vi' !== $lang[ $a ] || 'en' !== $lang[ $b ] ) {
					continue;
				}
				if ( '' !== $grp[ $a ] && $grp[ $a ] === $grp[ $b ] ) {
					$translations[] = $sim( $a, $b );
				} else {
					$cross_other[] = $sim( $a, $b );
				}
			}
		}

		// Hybrid analysis for a grid of semantic weights (0 = lexical topic score with vectors stored).
		$grid = array();
		$by_w = array();
		foreach ( array( 0.0, 0.2, 0.35, 0.5, 0.7 ) as $w ) {
			Settings::update( array( 'w_semantic' => $w ) );
			$this->reanalyze();
			$m          = $this->measure( $labels );
			$grid[]     = array(
				'w_semantic' => $w,
				'links'      => $m['links'],
				'related'    => $m['related'],
				'overlap'    => array_diff_key( $m['overlap'], array( 'pairs' => 1 ) ),
				'pairs'      => $m['overlap']['pairs'],
			);
			$by_w[ (string) $w ] = $m;
		}
		Settings::update( array( 'w_semantic' => 0.35 ) );

		$report = array(
			'source'           => 'LABELLED TEST FIXTURE (tests/fixtures/corpus.php) + REAL Qwen3-Embedding-0.6B vectors (' . $profile['model_id'] . '@' . substr( $profile['revision'], 0, 12 ) . ', ' . $dtype . ', dim ' . $profile['dimension'] . ')',
			'docs'             => count( $this->key_by_doc ),
			'queued_items'     => $queued,
			'roles'            => $roles,
			'chunk_kinds'      => $kinds,
			'stored_vectors'   => $stored,
			'embed_seconds'    => $seconds,
			'model_selftest'   => $ready['selftest'],
			'lexical_baseline' => array(
				'links'   => $baseline['links'],
				'related' => $baseline['related'],
				'overlap' => array_diff_key( $baseline['overlap'], array( 'pairs' => 1 ) ),
			),
			'hybrid_grid'      => $grid,
			'similarity'       => array(
				'same_locale_relevant_pairs'      => self::stats( $relevant ),
				'same_locale_other_pairs'         => self::stats( $other ),
				'must_not_compete_controls'       => self::stats( $controls ),
				'expected_overlaps'               => self::stats( $overlaps ),
				'vi_en_translation_pairs'         => self::stats( $translations ),
				'vi_en_non_translation_pairs'     => self::stats( $cross_other ),
			),
		);
		self::write_artifact( 'evaluation-hybrid.json', $report );
		fwrite( STDERR, "\nHYBRID " . wp_json_encode( array_diff_key( $report, array( 'hybrid_grid' => 1 ) ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $grid as $g ) {
			fwrite( STDERR, 'w_semantic=' . $g['w_semantic'] . ' ' . wp_json_encode( array( 'links' => $g['links'], 'related' => $g['related'], 'overlap' => $g['overlap'] ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		// Product guarantees that must hold with real vectors at the default weight.
		$default = $by_w['0.35'];
		$this->assertSame( 0, $default['overlap']['cross_locale_pairs_compared'], 'Translations are never compared.' );
		$this->assertSame( 0, $default['overlap']['false_positives_on_controls'], 'No false positives on must-not-compete controls with real vectors.' );
	}
}
