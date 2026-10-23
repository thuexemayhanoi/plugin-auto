<?php
/**
 * Exception safety of the write path: kses is always restored, failed writes are reverted and
 * reported honestly, and no other post field may change.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Database\Db;
use SemanticSEO\Services\Api;

/**
 * @group changes
 * @group security
 */
final class ChangeExecutorSafetyTest extends TestCase {

	/**
	 * Post of the proposal.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Restores kses state.
	 *
	 * @return void
	 */
	public function tear_down() {
		kses_remove_filters();
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * A previewed and approved link proposal.
	 *
	 * @return array{0: array, 1: array} Proposal, approval.
	 */
	private function approved_proposal() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$f = Db::row( "SELECT * FROM {findings} WHERE type = 'link' AND status = 'open' AND evidence LIKE %s ORDER BY score DESC LIMIT 1", array( '%"offset"%' ) );
		$this->assertNotNull( $f );
		$this->post_id = (int) Db::value( 'SELECT object_id FROM {documents} WHERE id = %d', array( (int) $f['document_id'] ) );
		$res           = Api::create_change_preview( array( 'finding_ids' => array( (int) $f['id'] ) ) );
		$p             = $res['previews'][0]['proposal'];
		$this->as_admin_screen();
		$a = ApprovalService::approve( $p['id'], 'ok' );
		$this->assertIsArray( $a );
		return array( $p, $a );
	}

	/**
	 * Applies a proposal.
	 *
	 * @param array $p Proposal.
	 * @param array $a Approval.
	 * @return array|\WP_Error
	 */
	private function apply( array $p, array $a ) {
		return Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => 'safety-' . wp_generate_password( 8, false ),
			)
		);
	}

	/**
	 * Asserts every post kses filter is active at its default priority.
	 *
	 * @return void
	 */
	private function assert_kses_active() {
		$this->assertSame( 10, has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'content kses restored' );
		$this->assertSame( 10, has_filter( 'title_save_pre', 'wp_filter_kses' ), 'title kses restored' );
		$this->assertSame( 10, has_filter( 'excerpt_save_pre', 'wp_filter_post_kses' ), 'excerpt kses restored' );
		$this->assertSame( 10, has_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' ), 'content_filtered kses restored' );
	}

	public function test_exception_after_the_database_write_restores_kses_and_reverts() {
		list( $p, $a ) = $this->approved_proposal();
		$before        = get_post_field( 'post_content', $this->post_id, 'raw' );
		kses_init_filters();
		$thrown = 0;
		$hook   = static function () use ( &$thrown ) {
			if ( 0 === $thrown++ ) {
				throw new \RuntimeException( 'another plugin failed in save_post' );
			}
		};
		add_action( 'save_post', $hook );
		$res = $this->apply( $p, $a );
		remove_action( 'save_post', $hook );

		$this->assertWPError( $res );
		$this->assertSame( 'sseo_apply_failed', $res->get_error_code() );
		$this->assert_kses_active();
		$this->assertSame( $before, get_post_field( 'post_content', $this->post_id, 'raw' ), 'reverted to the snapshot' );
		$c = Db::row( 'SELECT status, error FROM {changes} ORDER BY id DESC LIMIT 1' );
		$this->assertSame( 'failed', $c['status'] );
		$this->assertStringContainsString( 'RuntimeException', $c['error'] );
	}

	public function test_exception_before_the_database_write_changes_nothing() {
		list( $p, $a ) = $this->approved_proposal();
		$before        = get_post_field( 'post_content', $this->post_id, 'raw' );
		kses_init_filters();
		$thrown = 0;
		$filter = static function ( $data ) use ( &$thrown ) {
			if ( 0 === $thrown++ ) {
				throw new \LogicException( 'filter failed' );
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $filter );
		$res = $this->apply( $p, $a );
		remove_filter( 'wp_insert_post_data', $filter );

		$this->assertWPError( $res );
		$this->assert_kses_active();
		$this->assertSame( $before, get_post_field( 'post_content', $this->post_id, 'raw' ) );
		$this->assertSame( 'failed', Db::value( 'SELECT status FROM {changes} ORDER BY id DESC LIMIT 1' ) );
	}

	public function test_change_to_another_post_field_is_detected_and_reported() {
		list( $p, $a ) = $this->approved_proposal();
		$before        = get_post_field( 'post_content', $this->post_id, 'raw' );
		$title         = get_post_field( 'post_title', $this->post_id, 'raw' );
		$changed       = 0;
		$filter        = static function ( $data ) use ( &$changed ) {
			if ( 0 === $changed++ ) {
				$data['post_title'] = 'Changed by another plugin';
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $filter );
		$res = $this->apply( $p, $a );
		remove_filter( 'wp_insert_post_data', $filter );

		$this->assertWPError( $res );
		$this->assertSame( $before, get_post_field( 'post_content', $this->post_id, 'raw' ), 'content reverted' );
		$c = Db::row( 'SELECT status, error, verify FROM {changes} ORDER BY id DESC LIMIT 1' );
		// The content revert cannot restore a title another plugin changed: reported, never hidden.
		$this->assertSame( 'rollback_failed', $c['status'] );
		$this->assertStringContainsString( 'post_title', $c['error'] );
		$v = json_decode( $c['verify'], true );
		$this->assertContains( 'post_title', $v['other_fields_change'] );
		$this->assertStringContainsString( 'post_title', $v['manual_recovery'] );
		$this->assertNotSame( $title, get_post_field( 'post_title', $this->post_id, 'raw' ) );
	}

	public function test_exception_during_rollback_restores_kses_and_is_not_reported_as_success() {
		list( $p, $a ) = $this->approved_proposal();
		$res           = $this->apply( $p, $a );
		$this->assertIsArray( $res );
		$this->assertSame( 'applied', $res['status'] );
		kses_init_filters();
		$thrown = 0;
		$hook   = static function () use ( &$thrown ) {
			if ( 0 === $thrown++ ) {
				throw new \RuntimeException( 'failure during rollback' );
			}
		};
		add_action( 'save_post', $hook );
		$rb = Api::rollback_change( array( 'change_id' => $res['change_id'] ) );
		remove_action( 'save_post', $hook );

		$this->assertWPError( $rb );
		$this->assert_kses_active();
		$this->assertSame( 'rollback_failed', Db::value( 'SELECT status FROM {changes} WHERE id = %d', array( $res['change_id'] ) ) );
	}
}
