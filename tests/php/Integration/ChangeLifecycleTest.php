<?php
/**
 * Integration: PROPOSE → APPROVE → APPLY → VERIFY → ROLLBACK, including negative security cases.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

/**
 * @group changes
 * @group security
 */
final class ChangeLifecycleTest extends TestCase {

	/**
	 * Cleans the simulated Application Password context.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Prepares the corpus and returns a link finding with an anchor.
	 *
	 * @return array Finding row.
	 */
	private function link_finding() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$row = Db::row( "SELECT * FROM {findings} WHERE type = 'link' AND status = 'open' AND evidence LIKE %s ORDER BY score DESC LIMIT 1", array( '%"offset"%' ) );
		$this->assertNotNull( $row, 'At least one anchored link suggestion exists.' );
		return $row;
	}

	/**
	 * Creates a previewed proposal.
	 *
	 * @param int $finding_id Finding ID.
	 * @return array
	 */
	private function preview( $finding_id ) {
		$res = Api::create_change_preview( array( 'finding_ids' => array( $finding_id ) ) );
		$this->assertIsArray( $res );
		$this->assertArrayHasKey( 'proposal', $res['previews'][0], wp_json_encode( $res ) );
		return $res['previews'][0]['proposal'];
	}

	/**
	 * Simulates an agent account.
	 *
	 * @param string $role Agent role.
	 * @return int User ID.
	 */
	private function agent( $role ) {
		Capabilities::ensure_agent_roles();
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	public function test_full_lifecycle_human_with_exact_readback_and_rollback() {
		$f       = $this->link_finding();
		$post_id = (int) Db::value( 'SELECT object_id FROM {documents} WHERE id = %d', array( (int) $f['document_id'] ) );
		$before  = get_post_field( 'post_content', $post_id, 'raw' );
		$title   = get_post_field( 'post_title', $post_id );

		$p = $this->preview( (int) $f['id'] );
		$this->assertSame( 'previewed', $p['status'] );
		$this->assertSame( $before, get_post_field( 'post_content', $post_id, 'raw' ), 'Preview writes nothing.' );
		$this->assertSame( 64, strlen( $p['diff_hash'] ) );
		$this->assertContains( 'headings', $p['diff']['unchanged'] );

		$this->as_admin_screen();
		$approval = ApprovalService::approve( $p['id'], 'looks good' );
		$this->assertIsArray( $approval, is_wp_error( $approval ) ? $approval->get_error_message() : '' );
		$this->assertSame( 'active', $approval['status'] );

		$key    = 'apply-' . wp_generate_password( 10, false );
		$change = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $approval['id'],
				'idempotency_key' => $key,
			)
		);
		$this->assertIsArray( $change, is_wp_error( $change ) ? $change->get_error_message() : '' );
		$this->assertSame( 'applied', $change['status'] );
		$this->assertTrue( $change['verify']['readback'] );
		$after = get_post_field( 'post_content', $post_id, 'raw' );
		$this->assertSame( $change['after_hash'], Util::sha256( $after ) );
		$this->assertSame( 1, substr_count( $after, $p['diff']['inserted'] ), 'Exactly one link inserted.' );
		$this->assertSame( strlen( $p['diff']['inserted'] ) - strlen( wp_strip_all_tags( $p['diff']['inserted'] ) ), strlen( $after ) - strlen( $before ), 'Only the <a> markup bytes were added.' );
		$this->assertSame( $title, get_post_field( 'post_title', $post_id ), 'Title untouched.' );
		$this->assertNotEmpty( wp_get_post_revisions( $post_id ), 'WordPress revision kept as an extra safety net.' );

		// Idempotent replay: same key → same change, no second insertion.
		$replay = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $approval['id'],
				'idempotency_key' => $key,
			)
		);
		$this->assertTrue( $replay['replay'] );
		$this->assertSame( $change['change_id'], $replay['change_id'] );
		$this->assertSame( $after, get_post_field( 'post_content', $post_id, 'raw' ) );

		// Approval cannot be reused with another key (double apply).
		$again = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $approval['id'],
				'idempotency_key' => 'other-key-123',
			)
		);
		$this->assertWPError( $again );

		// Human rollback: exact restore.
		$rb = Api::rollback_change( array( 'change_id' => $change['change_id'] ) );
		$this->assertIsArray( $rb, is_wp_error( $rb ) ? $rb->get_error_message() : '' );
		$this->assertSame( 'rolled_back', $rb['status'] );
		$this->assertSame( 'exact', $rb['verify']['rollback_mode'] );
		$this->assertSame( $before, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertGreaterThan( 0, (int) Db::value( "SELECT COUNT(*) FROM {audit} WHERE action = 'change.rolled_back'" ) );
	}

	public function test_apply_without_or_with_foreign_approval_is_rejected() {
		$f = $this->link_finding();
		$p = $this->preview( (int) $f['id'] );
		$res = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => 999,
				'idempotency_key' => 'abcdefgh1',
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_not_approved', $res->get_error_code() );

		// Forged approval row (wrong diff hash) inserted straight into the table.
		Db::query( "UPDATE {proposals} SET status = 'approved' WHERE id = %d", array( $p['id'] ) );
		$forged = Db::insert(
			'approvals',
			array(
				'proposal_id'     => $p['id'],
				'action'          => 'apply',
				'approver_id'     => $this->admin,
				'scope'           => wp_json_encode( array( 'object_type' => 'post', 'object_ids' => array( (int) $p['object_id'] ) ) ),
				'diff_hash'       => str_repeat( 'a', 64 ),
				'action_category' => $p['type'],
				'status'          => 'active',
				'expires_at'      => Util::now( 3600 ),
				'created_at'      => Util::now(),
			)
		);
		$res = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $forged,
				'idempotency_key' => 'abcdefgh2',
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_approval_invalid', $res->get_error_code() );
	}

	public function test_expired_approval_is_rejected() {
		$f = $this->link_finding();
		$p = $this->preview( (int) $f['id'] );
		$this->as_admin_screen();
		$a = ApprovalService::approve( $p['id'] );
		Db::query( 'UPDATE {approvals} SET expires_at = %s WHERE id = %d', array( Util::now( -60 ), $a['id'] ) );
		$res = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => 'expired-1',
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_approval_expired', $res->get_error_code() );
	}

	public function test_changed_source_document_makes_approval_stale() {
		$f       = $this->link_finding();
		$p       = $this->preview( (int) $f['id'] );
		$this->as_admin_screen();
		$a       = ApprovalService::approve( $p['id'] );
		$post_id = (int) $p['object_id'];
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => get_post_field( 'post_content', $post_id, 'raw' ) . '<p>Editor added text after approval.</p>',
			)
		);
		$edited = get_post_field( 'post_content', $post_id, 'raw' );
		$res    = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => 'stale-123',
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_stale', $res->get_error_code() );
		$this->assertSame( $edited, get_post_field( 'post_content', $post_id, 'raw' ), 'Nothing written on stale approval.' );
		$this->assertSame( 'invalidated', ApprovalService::get( $a['id'] )['status'] );
	}

	public function test_agents_and_app_passwords_cannot_approve() {
		$f = $this->link_finding();
		$p = $this->preview( (int) $f['id'] );

		// Agent account inside WP Admin.
		$this->agent( 'sseo_agent_operator' );
		$this->as_admin_screen();
		$this->assertFalse( current_user_can( Capabilities::REVIEW ) );
		$res = ApprovalService::approve( $p['id'] );
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_human_required', $res->get_error_code() );

		// Administrator authenticated with an Application Password (simulated REST context).
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_application_password_uuid'] = 'test-uuid';
		$this->assertFalse( current_user_can( Capabilities::REVIEW ), 'App-password sessions never hold review.' );
		$this->assertFalse( current_user_can( Capabilities::APPLY ), 'Apply is off for agents by default.' );
		$res = ApprovalService::approve( $p['id'] );
		$this->assertWPError( $res );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM {approvals}' ) );
		$this->assertGreaterThan( 0, (int) Db::value( "SELECT COUNT(*) FROM {audit} WHERE action LIKE %s AND result = 'denied'", array( 'denied.approve%' ) ) );
	}

	public function test_agent_apply_requires_admin_opt_in_and_human_approval() {
		$f = $this->link_finding();
		$p = $this->preview( (int) $f['id'] );
		$this->as_admin_screen();
		$a = ApprovalService::approve( $p['id'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->agent( 'sseo_agent_operator' );
		$args = array(
			'proposal_id'     => $p['id'],
			'approval_id'     => $a['id'],
			'idempotency_key' => 'agent-apply-1',
		);
		$res  = Api::apply_approved_change( $args );
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_forbidden', $res->get_error_code() );

		Settings::update( array( 'agents_may_apply' => true ) );
		$res = Api::apply_approved_change( $args );
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( 'applied', $res['status'] );
		$this->assertStringStartsWith( 'agent:', $res['actor_via'] );

		// Rollback by an agent needs a separate human-issued rollback approval.
		$rb = Api::rollback_change( array( 'change_id' => $res['change_id'] ) );
		$this->assertWPError( $rb );
		$this->assertSame( 'sseo_rollback_authorization', $rb->get_error_code() );

		wp_set_current_user( $this->admin );
		$this->as_admin_screen();
		$ra = ApprovalService::approve_rollback( $res['change_id'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->agent( 'sseo_agent_operator' );
		$rb = Api::rollback_change(
			array(
				'change_id'   => $res['change_id'],
				'approval_id' => $ra['id'],
			)
		);
		$this->assertIsArray( $rb, is_wp_error( $rb ) ? $rb->get_error_message() : '' );
		$this->assertSame( 'rolled_back', $rb['status'] );
		// Replay of the rollback approval fails.
		$again = Api::rollback_change(
			array(
				'change_id'   => $res['change_id'],
				'approval_id' => $ra['id'],
			)
		);
		$this->assertWPError( $again );
	}

	public function test_read_only_agent_cannot_use_privileged_operations() {
		$this->link_finding();
		$this->agent( 'sseo_agent_reader' );
		$this->assertIsArray( Api::status() );
		$this->assertIsArray( Api::list_urls( array( 'limit' => 5 ) ) );
		foreach ( array(
			Api::start_analysis( array( 'type' => 'analyze' ) ),
			Api::rebuild_index(
				array(
					'scope'   => 'lexical',
					'confirm' => true,
				)
			),
			Api::create_change_preview( array( 'finding_ids' => array( 1 ) ) ),
			Api::apply_approved_change(
				array(
					'proposal_id'     => 1,
					'approval_id'     => 1,
					'idempotency_key' => 'reader-key-1',
				)
			),
			Api::rollback_change( array( 'change_id' => 1 ) ),
			Api::embedding_claim( array() ),
			Api::review_finding( 1, 'dismissed' ),
		) as $res ) {
			$this->assertWPError( $res );
			$this->assertSame( 403, $res->get_error_data()['status'] );
		}
		// The agent sees only its own audit entries.
		$audit = Api::audit_log( array() );
		foreach ( $audit['items'] as $row ) {
			$this->assertSame( get_current_user_id(), (int) $row['user_id'] );
		}
		// Agents cannot change roles or other users.
		$this->assertFalse( current_user_can( 'promote_users' ) );
		$this->assertFalse( current_user_can( 'edit_users' ) );
	}

	public function test_surgical_rollback_and_manual_recovery() {
		$f = $this->link_finding();
		$p = $this->preview( (int) $f['id'] );
		$this->as_admin_screen();
		$a       = ApprovalService::approve( $p['id'] );
		$change  = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => 'surgical-1',
			)
		);
		$post_id = (int) $p['object_id'];
		// Editor edits elsewhere after the change.
		$extra = '<p>Đoạn bổ sung của biên tập viên.</p>';
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => get_post_field( 'post_content', $post_id, 'raw' ) . $extra,
			)
		);
		$rb = Api::rollback_change( array( 'change_id' => $change['change_id'] ) );
		$this->assertSame( 'surgical', $rb['verify']['rollback_mode'] );
		$now = get_post_field( 'post_content', $post_id, 'raw' );
		$this->assertStringNotContainsString( $p['diff']['inserted'], $now );
		$this->assertStringContainsString( $extra, $now, 'Later edits are preserved.' );

		// Second change whose link the editor already removed → manual recovery, nothing written.
		$f2 = Db::row( "SELECT id FROM {findings} WHERE type = 'link' AND status = 'open' AND id <> %d AND evidence LIKE %s ORDER BY score DESC LIMIT 1", array( (int) $f['id'], '%"offset"%' ) );
		if ( null === $f2 ) {
			$this->markTestIncomplete( 'Fixture produced only one anchored suggestion.' );
		}
		$p2 = $this->preview( (int) $f2['id'] );
		$a2 = ApprovalService::approve( $p2['id'] );
		$c2 = Api::apply_approved_change(
			array(
				'proposal_id'     => $p2['id'],
				'approval_id'     => $a2['id'],
				'idempotency_key' => 'surgical-2',
			)
		);
		$this->assertIsArray( $c2, is_wp_error( $c2 ) ? $c2->get_error_message() : '' );
		$pid2    = (int) $p2['object_id'];
		$content = str_replace( $p2['diff']['inserted'], 'removed by editor', get_post_field( 'post_content', $pid2, 'raw' ) );
		wp_update_post(
			array(
				'ID'           => $pid2,
				'post_content' => $content,
			)
		);
		$snapshot = get_post_field( 'post_content', $pid2, 'raw' );
		$rb2      = Api::rollback_change( array( 'change_id' => $c2['change_id'] ) );
		$this->assertWPError( $rb2 );
		$this->assertSame( 'sseo_manual_recovery', $rb2->get_error_code() );
		$this->assertSame( $snapshot, get_post_field( 'post_content', $pid2, 'raw' ) );
	}

	public function test_locked_url_cannot_receive_proposals() {
		$f = $this->link_finding();
		$this->as_admin_screen();
		$obj = Db::value( 'SELECT object_id FROM {documents} WHERE id = %d', array( (int) $f['document_id'] ) );
		$res = Api::add_lock( 'document', 'post:' . $obj, 'lock', 'priority URL' );
		$this->assertIsArray( $res );
		LockService::flush();
		$prev = Api::create_change_preview( array( 'finding_ids' => array( (int) $f['id'] ) ) );
		$this->assertSame( 'sseo_locked', $prev['previews'][0]['error'] );
	}

	public function test_assign_existing_category_apply_and_rollback() {
		$this->load_corpus();
		$uncat = self::factory()->post->create(
			array(
				'post_title'   => 'Thuê xe máy giá tốt cho sinh viên ở Hà Nội',
				'post_content' => '<p>Sinh viên có thể thuê xe máy theo tháng với giá thuê ưu đãi, tiền đặt cọc rõ ràng và hợp đồng thuê xe minh bạch. Bảng giá thuê xe máy gồm Honda Wave và Honda Vision.</p>',
			)
		);
		PolylangDouble::set_post_language( $uncat, 'vi', 'g-sv' );
		$this->run_full_pipeline();
		$doc = $this->doc_for( $uncat );
		$f   = Db::row( "SELECT * FROM {findings} WHERE type = 'classification' AND document_id = %d", array( $doc ) );
		$this->assertNotNull( $f, 'Uncategorized post gets an existing-category suggestion.' );
		$before = wp_get_post_categories( $uncat );
		$p      = $this->preview( (int) $f['id'] );
		$this->assertSame( 'assign_category', $p['type'] );
		$this->as_admin_screen();
		$a      = ApprovalService::approve( $p['id'] );
		$terms  = (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		$change = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => 'cat-apply-1',
			)
		);
		$this->assertSame( 'applied', $change['status'] );
		$this->assertCount( count( $before ) + 1, wp_get_post_categories( $uncat ) );
		$this->assertSame( $terms, (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ), 'No term created.' );
		$rb = Api::rollback_change( array( 'change_id' => $change['change_id'] ) );
		$this->assertSame( 'rolled_back', $rb['status'] );
		$after = wp_get_post_categories( $uncat );
		sort( $before );
		sort( $after );
		$this->assertSame( $before, $after );
	}

	public function test_proposals_state_url_post_id_and_risk_and_never_move_a_url() {
		$f = $this->link_finding();
		$p = $this->preview( (int) $f['id'] );
		$this->assertSame( (int) $p['object_id'], $p['diff']['post_id'] );
		$this->assertSame( get_permalink( (int) $p['object_id'] ), $p['diff']['url'] );
		$this->assertSame( 'low', $p['diff']['risk']['level'] );
		$this->assertNotEmpty( $p['diff']['risk']['reasons'] );

		// Category proposal: medium risk; refused when the taxonomy is part of the permalink.
		$uncat = self::factory()->post->create(
			array(
				'post_title'   => 'Thuê xe máy giá tốt cho sinh viên ở Hà Nội',
				'post_content' => '<p>Sinh viên có thể thuê xe máy theo tháng với giá thuê ưu đãi, tiền đặt cọc rõ ràng và hợp đồng thuê xe minh bạch. Bảng giá thuê xe máy gồm Honda Wave và Honda Vision.</p>',
			)
		);
		PolylangDouble::set_post_language( $uncat, 'vi', 'g-sv2' );
		$this->run_full_pipeline();
		$cf = Db::row( "SELECT * FROM {findings} WHERE type = 'classification' AND document_id = %d", array( $this->doc_for( $uncat ) ) );
		$this->assertNotNull( $cf );
		$cp = $this->preview( (int) $cf['id'] );
		$this->assertSame( 'medium', $cp['diff']['risk']['level'] );
		$this->set_permalink_structure( '/%category%/%postname%/' );
		$res = Api::create_change_preview( array( 'finding_ids' => array( (int) $cf['id'] ) ) );
		$this->assertSame( 'sseo_permalink_risk', $res['previews'][0]['error'], wp_json_encode( $res ) );

		// An approval given before the permalink changed cannot apply either.
		$this->as_admin_screen();
		$a   = ApprovalService::approve( $cp['id'] );
		$url = get_permalink( $uncat );
		$res = Api::apply_approved_change(
			array(
				'proposal_id'     => $cp['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => 'cat-permalink-1',
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_permalink_risk', $res->get_error_code() );
		$this->assertSame( $url, get_permalink( $uncat ), 'URL unchanged' );
	}

	/**
	 * Document ID for a post ID.
	 *
	 * @param int $post_id Post.
	 * @return int
	 */
	private function doc_for( $post_id ) {
		return (int) Db::value( "SELECT id FROM {documents} WHERE object_type = 'post' AND object_id = %d", array( $post_id ) );
	}
}
