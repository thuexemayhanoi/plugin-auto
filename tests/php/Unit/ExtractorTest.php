<?php
/**
 * Unit tests: offset-preserving HTML scanning and content extraction.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SemanticSEO\Extractor\ContentExtractor;
use SemanticSEO\Extractor\HtmlScanner;

/**
 * @covers \SemanticSEO\Extractor\HtmlScanner
 * @covers \SemanticSEO\Extractor\ContentExtractor
 */
final class ExtractorTest extends TestCase {

	private function sample() {
		return "<!-- wp:heading -->\n<h2>Giá thuê xe máy 50cc</h2>\n<!-- /wp:heading -->\n"
			. "<!-- wp:paragraph -->\n<p>Bạn có thể <a href=\"https://example.test/thue-xe-theo-thang/\" rel=\"nofollow\">thuê xe theo tháng</a> với giá tốt. Xe tay ga 50cc phù hợp cho học sinh.</p>\n<!-- /wp:paragraph -->\n"
			. "<p>[gallery ids=\"1,2\"] Liên hệ để biết thêm &amp; đặt xe.</p><img src=\"a.jpg\"><img src=\"b.jpg\" alt=\"Xe Honda Vision\">"
			. "<script type=\"application/ld+json\">{\"@type\":\"FAQPage\"}</script>";
	}

	public function test_offsets_point_to_exact_bytes() {
		$html = $this->sample();
		$scan = HtmlScanner::scan( $html );
		foreach ( $scan['segments'] as $seg ) {
			$this->assertSame( $seg['raw'], substr( $html, $seg['start'], $seg['end'] - $seg['start'] ) );
		}
		$this->assertCount( 1, $scan['links'] );
		$link = $scan['links'][0];
		$this->assertSame( 'thuê xe theo tháng', $link['anchor'] );
		$this->assertSame( 'nofollow', $link['rel'] );
		$this->assertStringStartsWith( '<a href=', substr( $html, $link['start'], 8 ) );
		$this->assertSame( '</a>', substr( $html, $link['end'] - 4, 4 ) );
	}

	public function test_protected_contexts() {
		$scan  = HtmlScanner::scan( $this->sample() );
		$found = array();
		foreach ( $scan['segments'] as $seg ) {
			$found[ trim( $seg['raw'] ) ] = $seg['protected'];
		}
		$this->assertTrue( $found['Giá thuê xe máy 50cc'], 'Headings are protected.' );
		$this->assertTrue( $found['thuê xe theo tháng'], 'Existing link text is protected.' );
		$this->assertFalse( $found['với giá tốt. Xe tay ga 50cc phù hợp cho học sinh.'] );
		$this->assertTrue( $found['[gallery ids="1,2"] Liên hệ để biết thêm &amp; đặt xe.'], 'Segments overlapping shortcodes are protected.' );
	}

	public function test_headings_images_jsonld() {
		$scan = HtmlScanner::scan( $this->sample() );
		$this->assertSame( 'Giá thuê xe máy 50cc', $scan['headings'][0]['text'] );
		$this->assertSame( 2, $scan['headings'][0]['level'] );
		$this->assertCount( 2, $scan['images'] );
		$this->assertFalse( $scan['images'][0]['has_alt'] );
		$this->assertSame( 1, $scan['jsonld'] );
		$this->assertStringNotContainsString( 'FAQPage', $scan['text'] );
		$this->assertStringContainsString( 'Liên hệ để biết thêm & đặt xe.', $scan['text'] );
		$this->assertStringNotContainsString( '[gallery', $scan['text'] );
	}

	public function test_extract_signals() {
		$x = ContentExtractor::extract(
			array(
				'title'   => 'Thuê xe máy 50cc ở Hà Nội',
				'content' => $this->sample() . '<p>Hà Nội có nhiều điểm thuê xe. Hà Nội đông xe vào giờ cao điểm. Giá 150.000đ mỗi ngày.</p>',
			)
		);
		$this->assertGreaterThan( 20, $x['word_count'] );
		$this->assertSame( 1, $x['images_no_alt'] );
		$this->assertSame( 1, $x['jsonld_in_content'] );
		$texts = array_column( $x['entities'], 'text' );
		$this->assertContains( 'Hà Nội', $texts );
		$this->assertContains( '50cc', $texts );
		$this->assertContains( '150.000 đ', $x['attributes'] );
		$this->assertCount( ContentExtractor::MINHASH_SIZE, $x['minhash'] );
		$this->assertSame( 'summary', $x['chunks'][0]['kind'] );
		$this->assertStringContainsString( 'Thuê xe máy 50cc', $x['chunks'][0]['text'] );
		$this->assertSame( 'https://example.test/thue-xe-theo-thang/', $x['links'][0]['href'] );
	}

	public function test_minhash_similarity_orders_near_duplicates() {
		$a = 'xe máy tay ga honda vision đời mới tiết kiệm xăng phù hợp đi trong phố cổ hà nội và ngoại thành';
		$b = 'xe máy tay ga honda vision đời mới tiết kiệm xăng phù hợp đi trong phố cổ hà nội và các tỉnh lân cận';
		$c = 'kinh nghiệm du lịch sapa mùa lúa chín với lịch trình ba ngày hai đêm cho người đi lần đầu';
		$sa  = ContentExtractor::minhash( explode( ' ', $a ) );
		$sb  = ContentExtractor::minhash( explode( ' ', $b ) );
		$sc  = ContentExtractor::minhash( explode( ' ', $c ) );
		$this->assertGreaterThan( ContentExtractor::minhash_similarity( $sa, $sc ), ContentExtractor::minhash_similarity( $sa, $sb ) );
		$this->assertSame( 1.0, ContentExtractor::minhash_similarity( $sa, $sa ) );
	}

	public function test_malformed_html_does_not_break_offsets() {
		$html = '<p>Unclosed <b>bold <a href="/x">link <p>next < 5 > 3 & done';
		$scan = HtmlScanner::scan( $html );
		foreach ( $scan['segments'] as $seg ) {
			$this->assertSame( $seg['raw'], substr( $html, $seg['start'], $seg['end'] - $seg['start'] ) );
		}
		$this->assertCount( 1, $scan['links'] );
	}
}
