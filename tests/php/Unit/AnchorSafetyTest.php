<?php
/**
 * Unit tests: where a link may be inserted. Apostrophes outside attribute quotes, embed and bare
 * URLs, blocks whose markup is rebuilt from attributes, capitalised names and characters whose
 * lowercase form has another length must never yield an anchor in the wrong place.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SemanticSEO\Extractor\HtmlScanner;
use SemanticSEO\Links\AnchorFinder;

/**
 * @covers \SemanticSEO\Extractor\HtmlScanner
 * @covers \SemanticSEO\Links\AnchorFinder
 */
final class AnchorSafetyTest extends TestCase {

	/**
	 * Anchor for one phrase.
	 *
	 * @param string $html   Content.
	 * @param string $phrase Phrase.
	 * @return array|null
	 */
	private function find( $html, $phrase ) {
		return AnchorFinder::find(
			$html,
			array(
				array(
					'phrase'  => $phrase,
					'source'  => 'focus_keyword',
					'quality' => 1.0,
				),
			)
		);
	}

	public function test_an_apostrophe_outside_attribute_quotes_does_not_hide_an_open_link() {
		$html = "<p><img alt='Hanoi's bikes' src=\"a.jpg\"> Xem <a href=\"/x/\" title='Bob's'>thuê xe máy Hà Nội</a> ngay.</p>";
		$scan = HtmlScanner::scan( $html );
		$this->assertCount( 1, $scan['links'], 'the existing link is seen' );
		$this->assertSame( 'thuê xe máy Hà Nội', $scan['links'][0]['anchor'] );
		foreach ( $scan['segments'] as $seg ) {
			if ( 'thuê xe máy Hà Nội' === $seg['raw'] ) {
				$this->assertTrue( $seg['protected'], 'text of an existing link is protected' );
			}
		}
		$this->assertNull( $this->find( $html, 'thuê xe máy hà nội' ), 'no link inside a link' );
	}

	public function test_quoted_values_still_hide_angle_brackets() {
		$html = '<p><span data-x="a>b" title=\'c>d\'>thuê xe máy Hà Nội</span></p>';
		$a    = $this->find( $html, 'thuê xe máy hà nội' );
		$this->assertNotNull( $a );
		$this->assertSame( 'thuê xe máy Hà Nội', substr( $html, $a['offset'], $a['length'] ) );
	}

	public function test_text_of_an_embed_block_is_never_an_anchor() {
		$html = "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=honda\",\"type\":\"video\",\"providerNameSlug\":\"youtube\"} -->\n"
			. "<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=honda\n</div></figure>\n<!-- /wp:embed -->";
		$this->assertNull( $this->find( $html, 'honda' ) );
	}

	public function test_a_bare_url_is_never_an_anchor() {
		$html = "<p>Xem video:</p>\n\nhttps://rentbikehanoi.com/xe-honda/\n\n<p>Thuê xe honda ở phố cổ.</p>";
		$a    = $this->find( $html, 'honda' );
		$this->assertNotNull( $a, 'the word in the text is still found' );
		$this->assertGreaterThan( strpos( $html, '<p>Thuê' ), $a['offset'], 'not the one in the URL' );
		$this->assertNull( $this->find( '<p>Liên hệ honda@example.com hoặc www.honda-hanoi.vn</p>', 'honda' ) );
	}

	public function test_text_of_blocks_rebuilt_from_attributes_is_protected() {
		$faq = '<!-- wp:yoast/faq-block {"questions":[{"id":"faq-1","question":["Giá thuê xe máy Hà Nội?"],"answer":["Thuê xe máy Hà Nội từ 150.000đ/ngày."],"jsonQuestion":"Giá thuê xe máy Hà Nội?","jsonAnswer":"Thuê xe máy Hà Nội từ 150.000đ/ngày."}]} -->'
			. '<div class="schema-faq wp-block-yoast-faq-block"><div class="schema-faq-section" id="faq-1"><strong class="schema-faq-question">Giá thuê xe máy Hà Nội?</strong> <p class="schema-faq-answer">Thuê xe máy Hà Nội từ 150.000đ/ngày.</p> </div></div>'
			. '<!-- /wp:yoast/faq-block -->';
		$this->assertNull( $this->find( $faq, 'thuê xe máy hà nội' ), 'FAQ block HTML is regenerated from its JSON' );

		$html = '<!-- wp:custom/html-box --><div>Thuê xe máy Hà Nội</div><!-- /wp:custom/html-box -->'
			. "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Dịch vụ thuê xe máy Hà Nội uy tín.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
		$a    = $this->find( $html, 'thuê xe máy hà nội' );
		$this->assertNotNull( $a, 'paragraph blocks stay eligible' );
		$this->assertGreaterThan( strpos( $html, '<!-- wp:paragraph' ), $a['offset'] );
		$scan = HtmlScanner::scan( $html );
		$this->assertSame( 'core/paragraph', $scan['segments'][ count( $scan['segments'] ) - 1 ]['block'] );

		$classic = '<p>Dịch vụ thuê xe máy Hà Nội uy tín.</p>';
		$this->assertNotNull( $this->find( $classic, 'thuê xe máy hà nội' ), 'classic content stays eligible' );
		$void = '<!-- wp:separator /--><p>Dịch vụ thuê xe máy Hà Nội uy tín.</p>';
		$this->assertNotNull( $this->find( $void, 'thuê xe máy hà nội' ), 'a self-closing block opens nothing' );
	}

	public function test_a_capitalised_name_is_not_cut() {
		$this->assertNull( $this->find( '<p>Dịch vụ thuê xe máy Hà Đông giá rẻ.</p>', 'thuê xe máy hà' ), '"Hà" of "Hà Đông"' );
		$this->assertNull( $this->find( '<p>Khách ở Hồ Hoàn Kiếm thường đi bộ.</p>', 'hoàn kiếm thường' ) );
		$a = $this->find( '<p>Thuê xe máy Hà Nội giá rẻ.</p>', 'xe máy hà nội' );
		$this->assertNotNull( $a, 'a complete name is fine' );
		$this->assertNotNull( $this->find( '<p>Ở Hà Nội. Thuê xe máy rất dễ.</p>', 'thuê xe máy' ), 'sentence start after a full stop is fine' );
	}

	public function test_offsets_stay_exact_when_lowercasing_changes_the_length() {
		$html = '<p>İİ thuê xe máy Hà Nội</p>';
		$a    = $this->find( $html, 'thuê xe máy' );
		$this->assertNotNull( $a );
		$this->assertSame( 'thuê xe máy', $a['text'] );
		$this->assertSame( 'thuê xe máy', substr( $html, $a['offset'], $a['length'] ) );
	}
}
