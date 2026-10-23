<?php
/**
 * Integration: inventory + analysis pipeline on the labelled VI/EN fixture corpus.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Inventory\InventoryScanner;

/**
 * @group pipeline
 */
final class PipelineTest extends TestCase {

	public function test_inventory_discovers_real_objects_and_locales() {
		add_filter(
			'sseo_detected_seo_plugins',
			static function () {
				return array( 'yoast' );
			}
		);
		$this->load_corpus();
		$scope = InventoryScanner::scope_counts();
		$this->run_full_pipeline();

		$published_posts = (int) Db::value( "SELECT COUNT(*) FROM {posts} WHERE post_type IN ('post','page') AND post_status = 'publish'" );
		$docs            = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE object_type = 'post' AND status = 'publish'" );
		$this->assertSame( $published_posts, $docs, 'Every published post/page is inventoried exactly once.' );
		$this->assertSame( $scope['posts'], $published_posts );

		$vi = DocumentRepository::by_object( 'post', $this->posts['vi-pillar'] );
		$en = DocumentRepository::by_object( 'post', $this->posts['en-pillar'] );
		$this->assertSame( 'vi', $vi['locale'] );
		$this->assertSame( 'polylang', $vi['locale_source'] );
		$this->assertSame( 'en', $en['locale'] );
		$this->assertNotSame( '', $vi['translation_group'] );
		$this->assertSame( $vi['translation_group'], $en['translation_group'], 'Translation pair mapped.' );
		$this->assertSame( 'thuê xe máy Hà Nội', $vi['focus_keyword'] );

		// Drafts are not in scope by default; trashed posts are not inventoried.
		$this->assertNull( DocumentRepository::by_object( 'post', $this->posts['vi-draft'] ) );
		$this->assertNull( DocumentRepository::by_object( 'post', $this->posts['vi-trashed'] ) );

		// Category archives are inventoried with their locale.
		$term = DocumentRepository::by_object( 'term', $this->cats['en-rental'] );
		$this->assertSame( 'en', $term['locale'] );
	}

	public function test_links_graph_orphans_and_broken_links() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$pillar = DocumentRepository::get( $this->doc( 'vi-pillar' ) );
		$this->assertSame( 1, (int) $pillar['inbound_count'], 'Only vi-ngay links to the pillar in the fixture.' );
		$this->assertSame( 2, (int) $pillar['outbound_count'] );

		$broken = FindingRepository::query(
			array(
				'type'        => 'qa',
				'check_id'    => 'broken_internal_link',
				'document_id' => $this->doc( 'vi-broken' ),
			)
		);
		$this->assertSame( 1, $broken['total'], 'Link to a trashed post is a verified error.' );
		$this->assertSame( 'verified_error', $broken['items'][0]['label'] );

		$unresolved = FindingRepository::query(
			array(
				'type'        => 'qa',
				'check_id'    => 'unresolved_internal_link',
				'document_id' => $this->doc( 'vi-broken' ),
			)
		);
		$this->assertSame( 1, $unresolved['total'] );
		$this->assertSame( 'warning', $unresolved['items'][0]['label'], 'Unverified unresolved links are warnings, not errors.' );

		$h1 = FindingRepository::query(
			array(
				'type'        => 'qa',
				'check_id'    => 'h1_in_content',
				'document_id' => $this->doc( 'vi-broken' ),
			)
		);
		$this->assertSame( 1, $h1['total'] );
		$alt = FindingRepository::query(
			array(
				'type'        => 'qa',
				'check_id'    => 'images_missing_alt',
				'document_id' => $this->doc( 'vi-broken' ),
			)
		);
		$this->assertSame( 1, $alt['total'] );

		$orphan = FindingRepository::query(
			array(
				'type'        => 'qa',
				'check_id'    => 'orphan',
				'document_id' => $this->doc( 'vi-sapa' ),
			)
		);
		$this->assertSame( 1, $orphan['total'], 'vi-sapa has no inbound links.' );
	}

	public function test_golden_cannibalization_cases() {
		$corpus = $this->load_corpus();
		$this->run_full_pipeline();
		$find = function ( $a, $b ) {
			$x = min( $this->doc( $a ), $this->doc( $b ) );
			$y = max( $this->doc( $a ), $this->doc( $b ) );
			return Db::row( "SELECT * FROM {findings} WHERE type = 'cannibalization' AND document_id = %d AND target_id = %d", array( $x, $y ) );
		};
		foreach ( $corpus['labels']['not_competition'] as $pair ) {
			$f = $find( $pair[0], $pair[1] );
			if ( null !== $f ) {
				$this->assertNotContains( $f['check_id'], array( 'potential_competition', 'near_duplicate', 'exact_duplicate' ), $pair[0] . ' vs ' . $pair[1] . ' wrongly flagged as ' . $f['check_id'] );
			}
		}
		foreach ( $corpus['labels']['expected_overlap'] as $exp ) {
			$f = $find( $exp[0], $exp[1] );
			$this->assertNotNull( $f, $exp[0] . ' vs ' . $exp[1] . ' should be flagged' );
			$this->assertContains( $f['check_id'], $exp[2], $exp[0] . ' vs ' . $exp[1] . ' got ' . $f['check_id'] );
		}
		// Cross-locale translations are never compared.
		$cross = (int) Db::value( "SELECT COUNT(*) FROM {findings} f JOIN {documents} a ON a.id = f.document_id JOIN {documents} b ON b.id = f.target_id WHERE f.type = 'cannibalization' AND a.locale <> b.locale" );
		$this->assertSame( 0, $cross );
	}

	public function test_link_suggestions_have_exact_anchor_locations() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$res = FindingRepository::query(
			array(
				'type'   => 'link',
				'status' => 'open',
				'limit'  => 100,
			)
		);
		$this->assertGreaterThan( 0, $res['total'] );
		foreach ( $res['items'] as $f ) {
			$src = DocumentRepository::get( (int) $f['document_id'] );
			$tgt = DocumentRepository::get( (int) $f['target_id'] );
			$this->assertSame( $src['locale'], $tgt['locale'], 'Same-locale only.' );
			$this->assertNotSame( (int) $f['document_id'], (int) $f['target_id'] );
			$a = $f['evidence']['anchor'];
			if ( null !== $a ) {
				$content = get_post_field( 'post_content', (int) $src['object_id'], 'raw' );
				$this->assertSame( $a['text'], substr( $content, $a['offset'], $a['length'] ), 'Recorded offset maps to the exact anchor bytes.' );
			}
		}
	}

	public function test_incremental_reindex_after_edit() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$doc_before = DocumentRepository::by_object( 'post', $this->posts['vi-sapa'] );
		wp_update_post(
			array(
				'ID'           => $this->posts['vi-sapa'],
				'post_content' => get_post_field( 'post_content', $this->posts['vi-sapa'] ) . '<p>Thêm đoạn mới về thuê xe máy ở Hà Nội trước khi đi Sapa.</p>',
			)
		);
		$this->assertSame( 1, (int) DocumentRepository::by_object( 'post', $this->posts['vi-sapa'] )['dirty'] );
		$job = \SemanticSEO\Jobs\JobRepository::create( 'incremental', array(), $this->admin, 'test' );
		$res = \SemanticSEO\Jobs\JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'] );
		$after = DocumentRepository::by_object( 'post', $this->posts['vi-sapa'] );
		$this->assertSame( 0, (int) $after['dirty'] );
		$this->assertNotSame( $doc_before['content_hash'], $after['content_hash'] );
		// A scoped analysis was chained for the touched document only.
		$next = \SemanticSEO\Jobs\JobRepository::get( \SemanticSEO\Jobs\JobRepository::next_runnable() );
		$this->assertSame( 'analyze', $next['type'] );
		$this->assertContains( (int) $after['id'], array_map( 'intval', $next['params']['doc_ids'] ) );
	}
}
