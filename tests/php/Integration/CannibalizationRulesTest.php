<?php
/**
 * Overlap classification rules found on the read-only copy of the blog: different places are
 * different searches, different intents are complementary, vector-only similarity is the weakest
 * hypothesis — while duplicates, title evidence and the language gate are unchanged.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Cannibalization\CannibalizationAnalyzer;
use SemanticSEO\Language\Places;

/**
 * @group cannibalization
 */
final class CannibalizationRulesTest extends TestCase {

	/**
	 * Minimal page profile.
	 *
	 * @param int    $id     ID.
	 * @param string $title  Title.
	 * @param string $locale Locale.
	 * @return array
	 */
	private static function page( $id, $title, $locale = 'vi' ) {
		return array(
			'id'                => $id,
			'object_type'       => 'post',
			'status'            => 'publish',
			'excluded'          => 0,
			'locale'            => $locale,
			'translation_group' => '',
			'title'             => $title,
			'url'               => 'https://example.test/' . $id . '/',
			'cluster_id'        => 0,
			'cluster_role'      => 'supporting',
		);
	}

	/**
	 * Pair features (defaults: high semantic similarity, nothing else in common).
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private static function features( array $over = array() ) {
		return array_merge(
			array(
				'exact_duplicate'   => false,
				'near_duplicate'    => 0.1,
				'lexical'           => 0.05,
				'semantic'          => 0.80,
				'calibration'       => array(
					'floor'   => 0.40,
					'ceiling' => 0.90,
					'source'  => 'model',
				),
				'topic'             => 0.40,
				'topic_calibrated'  => 0.38,
				'title_overlap'     => 0.1,
				'title_containment' => 0.2,
				'heading_overlap'   => 0.05,
				'title_shared'      => array( 'xe' ),
				'title_only_a'      => array( 'a' ),
				'title_only_b'      => array( 'b' ),
				'focus_same'        => false,
				'gsc_overlap'       => 0,
				'gsc_available'     => false,
				'same_cluster'      => false,
				'same_intent'       => true,
				'intents'           => array( 'informational', 'informational' ),
				'intent_conf'       => array( 'medium', 'medium' ),
				'shared_terms'      => array(),
			),
			$over
		);
	}

	public function test_place_names_are_found_in_vietnamese_and_english_titles() {
		$this->assertSame( array( 'ha giang' ), Places::in( 'Kinh nghiệm phượt Hà Giang bằng xe máy' ) );
		$this->assertSame( array( 'ha giang' ), Places::in( 'Ha Giang Loop by motorbike: 4-day guide' ) );
		$this->assertSame( array( 'ha noi', 'hoan kiem' ), Places::in( 'Thuê xe máy Hoàn Kiếm, Hà Nội' ) );
		$this->assertSame( array( 'ha long' ), Places::in( 'Đi Vịnh Hạ Long bằng xe máy' ), '"vịnh" (bay) is not a place' );
		$this->assertSame( array(), Places::in( 'Lăng Chủ tịch Hồ Chí Minh mở cửa mấy giờ' ), 'the mausoleum is not the city' );
		$this->assertSame( array( 'ho chi minh' ), Places::in( 'Rent a scooter in Saigon' ) );
		$this->assertSame( array( array( 'ha giang' ), array( 'sa pa' ) ), Places::distinct( 'Phượt Hà Giang', 'Phượt Sa Pa' ) );
		$this->assertNull( Places::distinct( 'Thuê xe máy Hà Nội', 'Thuê xe máy Hoàn Kiếm Hà Nội' ), 'only one side names another place: not distinct' );
	}

	public function test_different_places_are_distinct_facets() {
		$f = CannibalizationAnalyzer::classify( self::page( 1, 'Kinh nghiệm phượt Hà Giang' ), self::page( 2, 'Kinh nghiệm phượt Sa Pa' ), self::features() );
		$this->assertSame( 'distinct_facet', $f['check_id'] );
		$this->assertSame( array( array( 'ha giang' ), array( 'sa pa' ) ), $f['evidence']['places'] );
	}

	public function test_places_never_hide_duplicates_or_title_competition() {
		$dup = CannibalizationAnalyzer::classify( self::page( 1, 'Thuê xe máy Hoàn Kiếm' ), self::page( 2, 'Thuê xe máy Long Biên' ), self::features( array( 'near_duplicate' => 0.92 ) ) );
		$this->assertSame( 'near_duplicate', $dup['check_id'], 'copied local pages are still duplicates' );

		$focus = CannibalizationAnalyzer::classify( self::page( 1, 'Thuê xe máy Hoàn Kiếm' ), self::page( 2, 'Thuê xe máy Long Biên' ), self::features( array( 'focus_same' => true ) ) );
		$this->assertSame( 'potential_competition', $focus['check_id'], 'same declared focus keyword outranks place names' );
	}

	public function test_different_intents_are_information_not_risk() {
		$f = CannibalizationAnalyzer::classify(
			self::page( 1, 'Giá thuê xe máy' ),
			self::page( 2, 'Kinh nghiệm thuê xe máy' ),
			self::features(
				array(
					'same_intent' => false,
					'intents'     => array( 'transactional', 'informational' ),
				)
			)
		);
		$this->assertSame( 'same_topic_different_intent', $f['check_id'] );
		$this->assertSame( 'info', $f['label'] );
	}

	public function test_vector_only_similarity_is_the_weakest_hypothesis() {
		$f = CannibalizationAnalyzer::classify( self::page( 1, 'Bảo dưỡng xe ga' ), self::page( 2, 'Mẹo đi đường đèo' ), self::features() );
		$this->assertSame( 'manual_review', $f['check_id'] );
		$this->assertSame( 'low', $f['confidence'] );
		$this->assertSame( 'semantic_only', $f['evidence']['basis'] );
		$this->assertSame( 'risk_hypothesis', $f['label'], 'still reported, never hidden' );

		// With title evidence it stays an ordinary ambiguous pair.
		$g = CannibalizationAnalyzer::classify( self::page( 1, 'Bảo dưỡng xe ga' ), self::page( 2, 'Bảo dưỡng xe số' ), self::features( array( 'title_overlap' => 0.4 ) ) );
		$this->assertSame( 'manual_review', $g['confidence'] );
	}

	public function test_languages_are_never_compared() {
		$this->assertNull( CannibalizationAnalyzer::classify( self::page( 1, 'Thuê xe máy Hà Nội' ), self::page( 2, 'Rent a motorbike in Hanoi', 'en' ), self::features( array( 'near_duplicate' => 0.95 ) ) ) );
	}
}
