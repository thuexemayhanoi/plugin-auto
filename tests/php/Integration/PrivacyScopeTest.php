<?php
/**
 * Content that is not public is never shown to agent accounts: titles and URLs of unpublished
 * posts that link to a page, and the text of password-protected posts.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

/**
 * @group security
 */
final class PrivacyScopeTest extends TestCase {

	/**
	 * Logs in an agent account.
	 *
	 * @param string $role Agent role.
	 * @return void
	 */
	private function as_agent( $role ) {
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_agents_do_not_see_unpublished_posts_linking_to_a_page() {
		Settings::update( array( 'include_drafts' => true ) );
		$target = self::factory()->post->create(
			array(
				'post_title'   => 'Bảng giá thuê xe máy',
				'post_content' => '<p>' . str_repeat( 'Bảng giá thuê xe máy theo ngày. ', 20 ) . '</p>',
			)
		);
		$href   = get_permalink( $target );
		$link   = '<p>' . str_repeat( 'Hướng dẫn thuê xe. ', 20 ) . '<a href="' . esc_url( $href ) . '">bảng giá</a></p>';
		$public = self::factory()->post->create(
			array(
				'post_title'   => 'Bài công khai',
				'post_content' => $link,
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Kế hoạch giảm giá nội bộ chưa công bố',
				'post_status'  => 'private',
				'post_content' => $link,
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Bản nháp khuyến mãi tháng sau',
				'post_status'  => 'draft',
				'post_content' => $link,
			)
		);
		$this->run_full_pipeline();

		$this->as_agent( 'sseo_agent_reader' );
		$res = Api::url_analysis( array( 'post_id' => $target ) );
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$titles = array_column( $res['facts']['links_in'], 'title' );
		$this->assertContains( 'Bài công khai', $titles );
		$this->assertNotContains( 'Kế hoạch giảm giá nội bộ chưa công bố', $titles );
		$this->assertNotContains( 'Bản nháp khuyến mãi tháng sau', $titles );
		$this->assertStringNotContainsString( 'nội bộ', wp_json_encode( $res ) );

		wp_set_current_user( $this->admin );
		$res = Api::url_analysis( array( 'post_id' => $target ) );
		$this->assertContains( 'Kế hoạch giảm giá nội bộ chưa công bố', array_column( $res['facts']['links_in'], 'title' ), 'editors still see them' );
		unset( $public );
	}

	public function test_the_text_of_a_password_protected_post_is_never_stored() {
		$secret = 'Mã giảm giá nội bộ XK-7781 chỉ dành cho đối tác';
		$post   = self::factory()->post->create(
			array(
				'post_title'    => 'Ưu đãi đối tác',
				'post_password' => 'mat-khau',
				'post_content'  => '<p>' . $secret . '. ' . str_repeat( 'Thuê xe máy giá ưu đãi cho đối tác. ', 20 ) . '</p>',
				'post_excerpt'  => $secret,
			)
		);
		$this->run_full_pipeline();

		$doc = Db::row( "SELECT * FROM {documents} WHERE object_type = 'post' AND object_id = %d", array( $post ) );
		$this->assertNotNull( $doc, 'the URL is still in the inventory' );
		$this->assertSame( 1, (int) $doc['excluded'], 'kept out of suggestions' );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM {chunks} WHERE text LIKE %s', array( '%XK-7781%' ) ), 'no chunk holds the protected text' );
		$this->assertStringNotContainsString( 'XK-7781', (string) Db::value( 'SELECT GROUP_CONCAT(signals) FROM {documents} WHERE id = %d', array( (int) $doc['id'] ) ) );

		$this->as_agent( 'sseo_agent_reader' );
		$res = Api::url_analysis( array( 'post_id' => $post ) );
		$this->assertStringNotContainsString( 'XK-7781', wp_json_encode( $res ) );

		// Protecting a post later removes its stored text.
		wp_set_current_user( $this->admin );
		$open = self::factory()->post->create(
			array(
				'post_title'   => 'Bài sẽ được khóa',
				'post_content' => '<p>Mã nội bộ ZQ-5512. ' . str_repeat( 'Thuê xe máy giá ưu đãi. ', 20 ) . '</p>',
			)
		);
		$this->run_full_pipeline();
		$this->assertGreaterThan( 0, (int) Db::value( 'SELECT COUNT(*) FROM {chunks} WHERE text LIKE %s', array( '%ZQ-5512%' ) ) );
		wp_update_post(
			array(
				'ID'            => $open,
				'post_password' => 'mat-khau',
			)
		);
		$job = \SemanticSEO\Jobs\JobRepository::create( 'inventory', array(), $this->admin, 'test' );
		\SemanticSEO\Jobs\JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM {chunks} WHERE text LIKE %s', array( '%ZQ-5512%' ) ) );
	}
}
