<?php
/**
 * *Max documents*: the inventory stops adding new objects at the limit, says so, keeps refreshing
 * the documents it already holds, and completes once the limit is raised.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

/**
 * @group inventory
 */
final class InventoryLimitTest extends TestCase {

	public function test_limit_is_enforced_reported_and_lifted() {
		$this->load_corpus();
		Settings::update( array( 'max_documents' => 10 ) );
		$this->assertSame( 10, InventoryScanner::max_documents() );

		$job = $this->run_inventory();
		$this->assertSame( 10, $this->stored(), 'no more documents than the limit' );
		$in_scope = InventoryScanner::scope_counts()['total'];
		$this->assertGreaterThan( 10, $in_scope );
		$this->assertSame( $in_scope - 10, (int) $job['state']['capped'], 'every object beyond the limit is counted' );

		$limit = Api::status()['inventory_limit'];
		$this->assertTrue( $limit['reached'] );
		$this->assertSame( 10, $limit['max_documents'] );
		$this->assertSame( $in_scope - 10, $limit['not_inventoried'] );

		// The overview page warns the administrator.
		wp_set_current_user( $this->admin );
		$this->as_admin_screen();
		ob_start();
		\SemanticSEO\Admin\OverviewPage::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Max documents limit', $html );

		// A stored document is still refreshed at the limit; a new object is not added.
		$stored_post = (int) Db::value( "SELECT object_id FROM {documents} WHERE object_type = 'post' ORDER BY id LIMIT 1" );
		wp_update_post(
			array(
				'ID'         => $stored_post,
				'post_title' => 'Thuê xe máy Hà Nội — cập nhật',
			)
		);
		$doc = InventoryScanner::index_post( get_post( $stored_post ), true );
		$this->assertGreaterThan( 0, $doc );
		$this->assertSame( 'Thuê xe máy Hà Nội — cập nhật', DocumentRepository::get( $doc )['title'] );
		$new = self::factory()->post->create(
			array(
				'post_title'   => 'Bài mới ngoài giới hạn',
				'post_content' => '<p>Nội dung.</p>',
			)
		);
		InventoryScanner::begin_batch();
		$this->assertSame( 0, InventoryScanner::index_post( get_post( $new ), true ) );
		$this->assertNull( DocumentRepository::by_object( 'post', $new ) );
		$this->assertSame( 1, InventoryScanner::capped_in_batch() );

		// Raised limit: the next inventory includes everything and the warning disappears.
		Settings::update( array( 'max_documents' => 5000 ) );
		$job = $this->run_inventory();
		$this->assertSame( 0, (int) $job['state']['capped'] );
		$this->assertSame( InventoryScanner::scope_counts()['total'], $this->stored() );
		$this->assertFalse( Api::status()['inventory_limit']['reached'] );
	}

	/**
	 * Runs a full inventory job and returns the finished job.
	 *
	 * @return array
	 */
	private function run_inventory() {
		$job = JobRepository::create( 'inventory', array(), $this->admin, 'test' );
		$this->assertIsArray( $job );
		$res = JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'], wp_json_encode( $res ) );
		return JobRepository::get( (int) $job['id'] );
	}

	/**
	 * Documents stored.
	 *
	 * @return int
	 */
	private function stored() {
		return (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status <> 'deleted'" );
	}
}
