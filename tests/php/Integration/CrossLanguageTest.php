<?php
/**
 * Cross-language "possible translation" suggestions (mutual nearest pages across languages).
 *
 * Synthetic vectors here test the selection rules only; the real-model behaviour is measured in
 * HybridEvaluationTest with Qwen3-Embedding vectors.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\AnalysisRunner;
use SemanticSEO\Database\Db;
use SemanticSEO\Semantic\CrossLanguage;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\VectorMath;

/**
 * @group embeddings
 */
final class CrossLanguageTest extends TestCase {

	/**
	 * Unit vector along axis $i (+ small noise on axis $j).
	 *
	 * @param int   $i     Axis.
	 * @param int   $j     Noise axis.
	 * @param float $noise Noise.
	 * @return float[]
	 */
	private static function v( $i, $j = 0, $noise = 0.0 ) {
		$v       = array_fill( 0, 8, 0.0 );
		$v[ $i ] = 1.0;
		$v[ $j ] += $noise;
		return VectorMath::normalize( $v );
	}

	public function test_mutual_best_threshold_and_margin() {
		$vi    = array(
			1 => self::v( 0, 1, 0.2 ),
			2 => self::v( 2, 3, 0.2 ),
			3 => self::v( 4 ),
			4 => self::v( 6, 7, 1.0 ),
		);
		$en    = array(
			11 => self::v( 0 ),
			12 => self::v( 2 ),
			13 => self::v( 5 ),
			14 => self::v( 6 ),
			15 => self::v( 7 ),
		);
		$pairs = CrossLanguage::mutual_best( $vi, $en, 0.70, 0.02 );
		$found = array();
		foreach ( $pairs as $p ) {
			$found[ $p['a'] ] = $p['b'];
		}
		$this->assertSame( 11, $found[1] ?? null, 'clear translation found' );
		$this->assertSame( 12, $found[2] ?? null );
		$this->assertArrayNotHasKey( 3, $found, 'below the threshold: nothing suggested' );
		$this->assertArrayNotHasKey( 4, $found, 'tie between two pages (no margin): nothing suggested' );
	}

	public function test_findings_only_for_unlinked_pages_and_never_written_to_posts() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$profile = ModelProfile::active();
		// Synthetic summary vectors: every fixture translation group shares one direction.
		$groups = array();
		$axis   = 0;
		foreach ( Db::rows( "SELECT id, translation_group FROM {documents} WHERE object_type = 'post' AND status = 'publish'" ) as $r ) {
			$g = (string) $r['translation_group'];
			$k = '' === $g ? 'solo-' . $r['id'] : $g;
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = $axis++;
			}
			$vec = array_fill( 0, (int) $profile['dimension'], 0.0 );
			$vec[ $groups[ $k ] % (int) $profile['dimension'] ] = 1.0;
			$vec[ ( $groups[ $k ] + 7 ) % (int) $profile['dimension'] ] = 0.1 * ( (int) $r['id'] % 3 );
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
				VectorMath::normalize( $vec ),
				$profile,
				'worker'
			);
		}
		$before = Db::col( "SELECT post_modified_gmt FROM {$GLOBALS['wpdb']->posts} ORDER BY ID" );

		// All fixture translations are linked: measured with include_linked, none suggested.
		$all = CrossLanguage::candidates( $profile['model_key'], true );
		$this->assertNotEmpty( $all['pairs'] );
		foreach ( $all['pairs'] as $p ) {
			$this->assertTrue( $p['linked'], 'every mutual pair is a known translation' );
		}
		$this->assertSame( array(), CrossLanguage::findings( $profile['model_key'] ) );

		// Unlink one pair (as on a site where the language plugin was never told): it is suggested.
		$vi = $this->doc( 'vi-thang' );
		$en = $this->doc( 'en-monthly' );
		Db::query( "UPDATE {documents} SET translation_group = '' WHERE id IN (%d, %d)", array( $vi, $en ) );
		$f = CrossLanguage::findings( $profile['model_key'] );
		$this->assertCount( 1, $f );
		$this->assertSame( 'possible_translation_pair', $f[0]['check_id'] );
		$this->assertSame( 'suggestion', $f[0]['label'] );
		$this->assertEqualsCanonicalizing( array( $vi, $en ), array( $f[0]['document_id'], $f[0]['target_id'] ) );

		// The full analysis stores it as a QA suggestion; posts are untouched.
		AnalysisRunner::step(
			array(
				'id'     => 4242,
				'params' => array(),
				'state'  => array(
					'phase'  => 'finalize',
					'cursor' => 0,
				),
			),
			10
		);
		$this->assertSame( 1, (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE check_id = 'possible_translation_pair' AND status = 'open'" ) );
		$this->assertSame( $before, Db::col( "SELECT post_modified_gmt FROM {$GLOBALS['wpdb']->posts} ORDER BY ID" ) );
	}
}
