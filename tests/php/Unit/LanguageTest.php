<?php
/**
 * Unit tests: Unicode normalization, Vietnamese accent handling, language detection, tokenizer.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SemanticSEO\Language\LanguageDetector;
use SemanticSEO\Language\Stopwords;
use SemanticSEO\Language\TextNormalizer;
use SemanticSEO\Lexical\Tokenizer;

/**
 * @covers \SemanticSEO\Language\TextNormalizer
 * @covers \SemanticSEO\Language\LanguageDetector
 * @covers \SemanticSEO\Lexical\Tokenizer
 */
final class LanguageTest extends TestCase {

	public function test_nfc_composes_decomposed_vietnamese() {
		$decomposed = "thue\u{0302}\u{0301} xe ma\u{0301}y Ha\u{0300} No\u{0302}\u{0323}i"; // thuế xe máy Hà Nội.
		$this->assertSame( 'thuế xe máy Hà Nội', TextNormalizer::nfc( $decomposed ) );
	}

	public function test_fold_removes_all_vietnamese_marks() {
		$this->assertSame( 'thue xe may o Ha Noi, duong pho', TextNormalizer::fold( 'thuê xe máy ở Hà Nội, đường phố' ) );
		$this->assertSame( 'DUONG', TextNormalizer::fold( 'ĐƯỜNG' ) );
		$this->assertSame( 'aaaaaaaaaaaaaaaaaa', TextNormalizer::fold( 'aàáảãạăằắẳẵặâầấẩẫậ' ) );
	}

	public function test_normalize_lowercases_and_collapses_whitespace() {
		$this->assertSame( 'thuê xe máy', TextNormalizer::normalize( "  THUÊ   Xe\nMÁY " ) );
	}

	public function test_detects_vietnamese_english_and_mixed() {
		$vi = LanguageDetector::detect( 'Thuê xe máy ở Hà Nội là lựa chọn phổ biến của du khách. Giá thuê theo ngày và theo tháng được niêm yết rõ ràng cho những người cần di chuyển trong thành phố.' );
		$this->assertSame( 'vi', $vi['lang'] );
		$en = LanguageDetector::detect( 'Renting a motorbike in Hanoi is a popular choice for travelers. The daily and monthly prices are listed clearly for everyone who needs to move around the city.' );
		$this->assertSame( 'en', $en['lang'] );
		$short = LanguageDetector::detect( 'Xe' );
		$this->assertSame( 'unknown', $short['lang'] );
	}

	public function test_tokenizer_builds_accent_aware_bigrams() {
		$tf = Tokenizer::term_frequencies( 'Thuê xe máy ở Hà Nội', 1.0, array(), 'vi' );
		$this->assertArrayHasKey( 'thuê', $tf );
		$this->assertArrayHasKey( 'xe_máy', $tf );
		$this->assertArrayHasKey( 'hà_nội', $tf );
		$this->assertArrayHasKey( '~may', $tf, 'Folded variant indexed for unaccented queries.' );
		$this->assertArrayNotHasKey( 'ở', $tf, 'Vietnamese stopword removed.' );
		$this->assertArrayNotHasKey( 'máy_hà', $tf, 'Bigram across a stopword is not created.' );
		// Distinct accented syllables are never merged.
		$tf2 = Tokenizer::term_frequencies( 'mua múa mùa', 1.0, array(), 'vi' );
		$this->assertArrayHasKey( 'mua', $tf2 );
		$this->assertArrayHasKey( 'múa', $tf2 );
		$this->assertArrayHasKey( 'mùa', $tf2 );
	}

	public function test_english_stopword_collisions_are_kept_in_vietnamese_text() {
		$this->assertFalse( Stopwords::is_stopword( 'an', 'vi' ) );
		$this->assertFalse( Stopwords::is_stopword( 'an', '' ) );
		$this->assertTrue( Stopwords::is_stopword( 'an', 'en' ) );
		$tf = Tokenizer::term_frequencies( 'Lái xe an toàn', 1.0, array(), 'vi' );
		$this->assertArrayHasKey( 'an_toàn', $tf );
	}

	public function test_unaccented_query_matches_folded_terms() {
		$q = Tokenizer::query_terms( 'thue xe may ha noi' );
		$this->assertArrayHasKey( '~thue', $q );
		$this->assertArrayHasKey( '~may', $q );
		$this->assertArrayHasKey( 'xe', $q );
	}

	public function test_english_plural_folding() {
		$this->assertSame( 'motorbike', Tokenizer::stem( 'motorbikes' ) );
		$this->assertSame( 'city', Tokenizer::stem( 'cities' ) );
		$this->assertSame( 'bus', Tokenizer::stem( 'bus' ) );
	}
}
