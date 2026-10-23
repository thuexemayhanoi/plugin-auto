<?php
/**
 * SEO logic regressions: query-level evidence of competition is never hidden by cluster roles, a
 * generic modifier ("giá rẻ") does not make a page a supporting page, competing pages are not
 * cross-linked with exact-match anchors, and links on unpublished pages do not count as inbound.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\Scorer;
use SemanticSEO\Cannibalization\CannibalizationAnalyzer;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Lexical\Tokenizer;
use SemanticSEO\Links\LinkSuggester;
use SemanticSEO\Settings;

/**
 * @group analysis
 */
final class SeoLogicTest extends TestCase {

	/**
	 * Profile of a fixture document.
	 *
	 * @param string $key   Fixture key.
	 * @param string $role  Cluster role to assume.
	 * @return array
	 */
	private function profile( $key, $role ) {
		$p                 = Scorer::profile( DocumentRepository::get( $this->doc( $key ) ) );
		$p['cluster_role'] = $role;
		return $p;
	}

	public function test_query_evidence_of_competition_is_not_hidden_by_cluster_roles() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$pillar  = $this->profile( 'vi-pillar', 'pillar' );
		$monthly = $this->profile( 'vi-thang', 'supporting' );
		$f       = Scorer::features( $pillar, $monthly );

		$f['same_cluster'] = true;
		$f['focus_same']   = true;
		$res               = CannibalizationAnalyzer::classify( $pillar, $monthly, $f );
		$this->assertSame( 'potential_competition', $res['check_id'], 'same declared focus keyword' );

		$f['focus_same']    = false;
		$f['gsc_overlap']   = 3;
		$f['gsc_available'] = true;
		$f['same_intent']   = true;
		$res                = CannibalizationAnalyzer::classify( $pillar, $monthly, $f );
		$this->assertSame( 'potential_competition', $res['check_id'], 'real shared Search Console queries' );
		$this->assertSame( 'high', $res['confidence'] );
	}

	public function test_a_generic_modifier_does_not_make_a_supporting_page() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$pillar = $this->profile( 'vi-pillar', 'pillar' );
		$cheap  = $this->profile( 'vi-gia-re', 'supporting' );
		// The head query as the pillar title, the same query + "giá rẻ" as the other title.
		$pillar['title']     = 'Thuê xe máy Hà Nội';
		$pillar['title_set'] = Tokenizer::significant_set( $pillar['title'], 'vi' );
		$f                   = Scorer::features( $pillar, $cheap );
		$this->assertSame( array( 'giá', 'rẻ' ), $f['title_only_b'] );

		$f['same_cluster'] = true;
		$res               = CannibalizationAnalyzer::classify( $pillar, $cheap, $f );
		$this->assertNotNull( $res );
		$this->assertNotSame( 'pillar_supporting', $res['check_id'], '"giá rẻ" only adds a generic modifier to the head query' );
		$this->assertSame( 'risk_hypothesis', $res['label'] );
	}

	public function test_competing_pages_are_not_cross_linked() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$a       = $this->profile( 'vi-pillar', '' );
		$b       = $this->profile( 'vi-gia-re', '' );
		$f       = Scorer::features( $b, $a );
		$overlap = CannibalizationAnalyzer::classify( $b, $a, $f );
		$this->assertSame( 'potential_competition', $overlap['check_id'], 'fixture: the two pages compete' );
		$content = (string) get_post_field( 'post_content', $b['object_id'], 'raw' );
		Settings::update( array( 'link_min_score' => 0.0 ) );

		$this->assertSame( array(), LinkSuggester::suggest( $b, array( array( $a, $f ) ), $content ), 'no exact-match link between competing pages' );
		$back = Scorer::features( $a, $b );
		$this->assertSame( array(), LinkSuggester::suggest( $a, array( array( $b, $back ) ), (string) get_post_field( 'post_content', $a['object_id'], 'raw' ) ) );

		// A link up to the pillar of the pair stays possible.
		$a['cluster_role'] = 'pillar';
		$b['cluster_role'] = 'supporting';
		$up                = LinkSuggester::suggest( $b, array( array( $a, Scorer::features( $b, $a ) ) ), $content );
		$this->assertLessThanOrEqual( 1, count( $up ) );
		$this->assertSame( array(), LinkSuggester::suggest( $a, array( array( $b, Scorer::features( $a, $b ) ) ), (string) get_post_field( 'post_content', $a['object_id'], 'raw' ) ), 'never down from the pillar' );
	}

	public function test_links_on_unpublished_pages_do_not_count_as_inbound() {
		Settings::update( array( 'include_drafts' => true ) );
		$target = self::factory()->post->create(
			array(
				'post_title'   => 'Kinh nghiệm thuê xe máy đi Hà Giang',
				'post_content' => '<p>' . str_repeat( 'Kinh nghiệm thuê xe máy đi Hà Giang an toàn. ', 20 ) . '</p>',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Bản nháp',
				'post_status'  => 'draft',
				'post_content' => '<p>' . str_repeat( 'Nháp. ', 30 ) . '<a href="' . esc_url( get_permalink( $target ) ) . '">Hà Giang</a></p>',
			)
		);
		$this->run_full_pipeline();
		$doc = Db::row( "SELECT id, inbound_count FROM {documents} WHERE object_type = 'post' AND object_id = %d", array( $target ) );
		$this->assertSame( 0, (int) $doc['inbound_count'], 'visitors cannot follow a link on a draft' );
		$this->assertSame( 1, (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE type = 'qa' AND check_id = 'orphan' AND document_id = %d", array( (int) $doc['id'] ) ), 'reported as orphan' );
	}
}
