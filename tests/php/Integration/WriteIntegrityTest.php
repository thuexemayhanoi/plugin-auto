<?php
/**
 * Integrity of the write path: applying a link changes post_content and nothing else (slug, URL,
 * status, terms and the bytes around the link stay as they are); an edit committed by someone else
 * before or after the write is never overwritten; a change whose URL would move is undone; a
 * rollback cannot run twice; a change interrupted by a dead process is recovered.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Approval\ChangeExecutor;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Links\AnchorFinder;
use SemanticSEO\Rollback\RollbackService;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

/**
 * @group changes
 */
final class WriteIntegrityTest extends TestCase {

	const TEXT = '<p>Dịch vụ thuê xe máy Hà Nội giá rẻ cho khách du lịch.</p>';

	/**
	 * One-shot SQL interceptor used to commit a concurrent edit at an exact point.
	 *
	 * @var callable|null
	 */
	private $on_query = null;

	public function tear_down() {
		$this->on_query = null;
		remove_all_filters( 'query' );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Document row for a post, as the inventory stores it.
	 *
	 * @param int $post_id Post.
	 * @return int Document ID.
	 */
	private function doc_row( $post_id ) {
		$url = get_permalink( $post_id );
		return DocumentRepository::upsert(
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'status'      => 'publish',
				'indexable'   => 1,
				'excluded'    => 0,
				'url'         => $url,
				'url_hash'    => DocumentRepository::url_hash( $url ),
				'title'       => get_the_title( $post_id ),
			)
		);
	}

	/**
	 * A published source post whose stored content is exactly $raw (written below the WordPress
	 * filters, as an import or an older editor would have stored it), a published target and a link
	 * finding whose anchor the real AnchorFinder located.
	 *
	 * @param string $raw         Stored content of the source.
	 * @param array  $source_args Extra post fields of the source.
	 * @return array{0:int,1:int,2:int} Source post, finding, target post.
	 */
	private function pair( $raw, array $source_args = array() ) {
		global $wpdb;
		$target = self::factory()->post->create(
			array(
				'post_title'  => 'Bảng giá thuê xe',
				'post_status' => 'publish',
			)
		);
		$source = self::factory()->post->create(
			array_merge(
				array(
					'post_title'  => 'Nguồn',
					'post_status' => 'publish',
				),
				$source_args
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_content' => $raw ), array( 'ID' => $source ) );
		clean_post_cache( $source );
		$anchor = AnchorFinder::find(
			get_post_field( 'post_content', $source, 'raw' ),
			array(
				array(
					'phrase'  => 'thuê xe máy hà nội',
					'source'  => 'focus_keyword',
					'quality' => 1.0,
				),
			)
		);
		$this->assertNotNull( $anchor, 'the fixture has an anchor' );
		$fid = FindingRepository::upsert(
			array(
				'type'        => 'link',
				'check_id'    => 'link_suggestion',
				'document_id' => $this->doc_row( $source ),
				'target_id'   => $this->doc_row( $target ),
				'label'       => 'suggestion',
				'confidence'  => 'high',
				'evidence'    => array(
					'anchor' => $anchor,
					'reason' => 'test',
				),
			),
			0
		);
		return array( $source, $fid, $target );
	}

	/**
	 * Preview and human approval of a finding.
	 *
	 * @param int $fid Finding.
	 * @return array{0:array,1:array} Proposal, approval.
	 */
	private function approve( $fid ) {
		$res = Api::create_change_preview( array( 'finding_ids' => array( $fid ) ) );
		$this->assertArrayHasKey( 'proposal', $res['previews'][0], wp_json_encode( $res ) );
		$p = $res['previews'][0]['proposal'];
		$this->as_admin_screen();
		$a = ApprovalService::approve( $p['id'] );
		$this->assertIsArray( $a );
		return array( $p, $a );
	}

	/**
	 * Applies an approved proposal.
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
				'idempotency_key' => 'integrity-' . wp_generate_password( 10, false ),
			)
		);
	}

	/**
	 * Preview, approve and apply; the change must succeed.
	 *
	 * @param int $fid Finding.
	 * @return array{0:array,1:array} Proposal, change.
	 */
	private function apply_ok( $fid ) {
		list( $p, $a ) = $this->approve( $fid );
		$c             = $this->apply( $p, $a );
		$this->assertIsArray( $c, is_wp_error( $c ) ? $c->get_error_code() . ': ' . $c->get_error_message() : '' );
		$this->assertSame( 'applied', $c['status'], wp_json_encode( $c ) );
		return array( $p, $c );
	}

	/**
	 * Expected content after the link is inserted (from the approved proposal).
	 *
	 * @param array $p Proposal.
	 * @return string
	 */
	private function expected_after( array $p ) {
		return (string) Db::value( 'SELECT after_value FROM {changes} WHERE proposal_id = %d ORDER BY id DESC LIMIT 1', array( (int) $p['id'] ) );
	}

	/**
	 * Routes SQL through the one-shot interceptor.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function intercept( $sql ) {
		if ( null !== $this->on_query ) {
			$fn  = $this->on_query;
			$out = $fn( $sql );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		return $sql;
	}

	public function test_a_category_less_post_and_its_tags_keep_their_terms() {
		list( $src, $fid ) = $this->pair( self::TEXT );
		wp_set_object_terms( $src, array(), 'category' );
		// A tag renamed after another tag took its old name, and a tag whose name has a quote.
		$old = wp_insert_term( 'Honda', 'post_tag' );
		wp_update_term( $old['term_id'], 'post_tag', array( 'name' => 'Honda Wave' ) );
		$new = wp_insert_term( 'Honda', 'post_tag' );
		$q   = wp_insert_term( "Hanoi's Old Quarter", 'post_tag' );
		wp_set_object_terms( $src, array( (int) $new['term_id'], (int) $q['term_id'] ), 'post_tag' );
		$tags = wp_get_object_terms( $src, 'post_tag', array( 'fields' => 'ids' ) );
		sort( $tags );

		$this->apply_ok( $fid );

		$this->assertSame( array(), wp_get_object_terms( $src, 'category', array( 'fields' => 'ids' ) ), 'no default category added' );
		$after = wp_get_object_terms( $src, 'post_tag', array( 'fields' => 'ids' ) );
		sort( $after );
		$this->assertSame( $tags, $after, 'the same tags, not re-resolved by name' );
	}

	public function test_b_a_non_canonical_slug_and_the_url_are_kept() {
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		list( $src, $fid ) = $this->pair( self::TEXT );
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'Thue-Xe-May.html' ), array( 'ID' => $src ) );
		clean_post_cache( $src );
		$url = get_permalink( $src );

		$this->apply_ok( $fid );

		clean_post_cache( $src );
		$this->assertSame( 'Thue-Xe-May.html', get_post_field( 'post_name', $src, 'raw' ) );
		$this->assertSame( $url, get_permalink( $src ) );
	}

	public function test_c_a_page_slug_shared_with_a_reparented_attachment_is_kept() {
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		list( $page, $fid ) = $this->pair(
			self::TEXT,
			array(
				'post_type' => 'page',
				'post_name' => 'bang-gia',
			)
		);
		// An image uploaded to a post that was later deleted: wp_delete_post() re-parents it to 0,
		// so it now has the page's slug and parent.
		$other = self::factory()->post->create( array( 'post_title' => 'tmp' ) );
		$att   = self::factory()->attachment->create(
			array(
				'post_title'     => 'bang-gia',
				'post_parent'    => $other,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'bang-gia' ), array( 'ID' => $att ) );
		wp_delete_post( $other, true );
		clean_post_cache( $att );
		$this->assertSame( 0, (int) get_post( $att )->post_parent );
		$url = get_permalink( $page );

		$this->apply_ok( $fid );

		clean_post_cache( $page );
		$this->assertSame( 'bang-gia', get_post_field( 'post_name', $page, 'raw' ) );
		$this->assertSame( $url, get_permalink( $page ) );
	}

	public function test_d_a_published_post_dated_in_the_future_stays_published() {
		global $wpdb;
		list( $src, $fid ) = $this->pair( self::TEXT );
		$future            = gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $future,
				'post_date_gmt' => $future,
			),
			array( 'ID' => $src )
		);
		clean_post_cache( $src );

		$this->apply_ok( $fid );

		$this->assertSame( 'publish', get_post_status( $src ) );
	}

	public function test_e_bytes_outside_the_link_are_kept_exactly_and_rollback_restores_them() {
		$raw               = self::TEXT . '<p>Giá 100k &#150; 150k.</p>';
		list( $src, $fid ) = $this->pair( $raw );

		list( $p, $c ) = $this->apply_ok( $fid );

		$stored = get_post_field( 'post_content', $src, 'raw' );
		$this->assertSame( $this->expected_after( $p ), $stored, 'exactly the approved result' );
		$this->assertStringContainsString( '&#150;', $stored, 'invalid entity elsewhere left as it was' );
		$rb = Api::rollback_change( array( 'change_id' => $c['change_id'] ) );
		$this->assertIsArray( $rb, is_wp_error( $rb ) ? $rb->get_error_message() : '' );
		$this->assertSame( 'rolled_back', $rb['status'] );
		$this->assertSame( $raw, get_post_field( 'post_content', $src, 'raw' ), 'byte-exact restore' );
	}

	public function test_f_an_edit_committed_just_before_the_write_is_not_overwritten() {
		global $wpdb;
		list( $src, $fid ) = $this->pair( self::TEXT );
		list( $p, $a )     = $this->approve( $fid );
		$editor            = self::TEXT . '<p>Biên tập viên vừa lưu đoạn này.</p>';
		$done              = false;
		// Another request (the editor's "Update") commits between the plugin's stale check and its
		// write to the posts table.
		$this->on_query = static function ( $sql ) use ( &$done, $src, $editor, $wpdb ) {
			if ( ! $done && preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $wpdb->posts, '/' ) . '`?\s+SET\s+.*post_content/is', $sql ) ) {
				$done = true;
				$wpdb->update( $wpdb->posts, array( 'post_content' => $editor ), array( 'ID' => $src ) );
			}
			return null;
		};
		add_filter( 'query', array( $this, 'intercept' ) );
		$res = $this->apply( $p, $a );
		remove_filter( 'query', array( $this, 'intercept' ) );

		$this->assertTrue( $done, 'the concurrent edit was committed' );
		clean_post_cache( $src );
		$this->assertSame( $editor, get_post_field( 'post_content', $src, 'raw' ), "the editor's text is kept" );
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_stale', $res->get_error_code() );
		$this->assertSame( 'failed', Db::value( 'SELECT status FROM {changes} ORDER BY id DESC LIMIT 1' ) );
		$this->assertSame( 'stale', Db::value( 'SELECT status FROM {proposals} WHERE id = %d', array( $p['id'] ) ), 'a new preview is needed' );
	}

	public function test_g_an_edit_committed_while_hooks_run_is_not_reverted() {
		global $wpdb;
		list( $src, $fid ) = $this->pair( self::TEXT );
		list( $p, $a )     = $this->approve( $fid );
		$editor            = self::TEXT . '<p>Biên tập viên vừa lưu đoạn này.</p>';
		$n                 = 0;
		$hook              = static function ( $post_id ) use ( &$n, $src, $editor, $wpdb ) {
			if ( (int) $post_id === $src && 0 === $n++ ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $editor ), array( 'ID' => $src ) );
			}
		};
		add_action( 'save_post', $hook, 1000 );
		$res = $this->apply( $p, $a );
		remove_action( 'save_post', $hook, 1000 );

		clean_post_cache( $src );
		$this->assertSame( $editor, get_post_field( 'post_content', $src, 'raw' ), "the editor's later save is not overwritten by a revert" );
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( 'applied', $res['status'], 'the verified write happened' );
		$this->assertTrue( $res['verify']['changed_after_write'], 'reported' );
	}

	public function test_h_rollback_keeps_a_slug_changed_after_apply() {
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		list( $src, $fid ) = $this->pair( self::TEXT );
		list( , $c )       = $this->apply_ok( $fid );
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'Thue-Xe-May.html' ), array( 'ID' => $src ) );
		clean_post_cache( $src );
		$url = get_permalink( $src );

		$rb = Api::rollback_change( array( 'change_id' => $c['change_id'] ) );

		$this->assertIsArray( $rb, is_wp_error( $rb ) ? $rb->get_error_message() : '' );
		$this->assertSame( 'rolled_back', $rb['status'] );
		clean_post_cache( $src );
		$this->assertSame( $url, get_permalink( $src ) );
		$this->assertSame( self::TEXT, get_post_field( 'post_content', $src, 'raw' ) );
	}

	public function test_i_apply_is_refused_when_the_source_or_target_left_publication() {
		list( $src, $fid, $target ) = $this->pair( self::TEXT );
		list( $p, $a )              = $this->approve( $fid );
		wp_trash_post( $target );
		$res = $this->apply( $p, $a );
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_invalid_target', $res->get_error_code() );
		$this->assertSame( self::TEXT, get_post_field( 'post_content', $src, 'raw' ), 'no link to a trashed page' );
		$this->assertSame( 'stale', Db::value( 'SELECT status FROM {proposals} WHERE id = %d', array( $p['id'] ) ) );

		list( $src2, $fid2 ) = $this->pair( self::TEXT );
		list( $p2, $a2 )     = $this->approve( $fid2 );
		wp_update_post(
			array(
				'ID'          => $src2,
				'post_status' => 'draft',
			)
		);
		$content = get_post_field( 'post_content', $src2, 'raw' );
		$res     = $this->apply( $p2, $a2 );
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_invalid_source', $res->get_error_code() );
		$this->assertSame( $content, get_post_field( 'post_content', $src2, 'raw' ) );
	}

	public function test_j_a_category_that_would_move_the_url_is_undone() {
		$this->set_permalink_structure( '/%postname%/' );
		register_taxonomy(
			'tour_cat',
			'tour',
			array(
				'public'       => true,
				'hierarchical' => true,
			)
		);
		register_post_type(
			'tour',
			array(
				'public'     => true,
				'taxonomies' => array( 'tour_cat' ),
				'rewrite'    => array( 'slug' => 'tour/%tour_cat%' ),
			)
		);
		// The theme builds tour URLs from the first tour category (lowest term ID).
		$link = static function ( $url, $post ) {
			if ( 'tour' !== $post->post_type ) {
				return $url;
			}
			$t = wp_get_object_terms( $post->ID, 'tour_cat', array( 'orderby' => 'term_id' ) );
			return str_replace( '%tour_cat%', $t ? $t[0]->slug : 'khac', $url );
		};
		add_filter( 'post_type_link', $link, 10, 2 );
		Settings::update(
			array(
				'taxonomies' => array( 'category', 'tour_cat' ),
				'post_types' => array( 'post', 'page', 'tour' ),
			)
		);
		$first  = wp_insert_term( 'Hà Giang', 'tour_cat' );
		$second = wp_insert_term( 'Bắc Bộ', 'tour_cat' );
		$tour   = self::factory()->post->create(
			array(
				'post_type'   => 'tour',
				'post_title'  => 'Tour Hà Giang',
				'post_name'   => 'ha-giang-loop',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $tour, array( (int) $second['term_id'] ), 'tour_cat' );
		$url = get_permalink( $tour );
		$this->assertStringContainsString( '/bac-bo/', $url );
		$fid = FindingRepository::upsert(
			array(
				'type'        => 'classification',
				'check_id'    => 'classification_suggestion',
				'document_id' => $this->doc_row( $tour ),
				'target_id'   => 0,
				'label'       => 'suggestion',
				'confidence'  => 'medium',
				'evidence'    => array(
					'suggested' => array(
						'taxonomy'   => 'tour_cat',
						'term_id'    => (int) $first['term_id'],
						'label'      => 'Hà Giang',
						'similarity' => 0.5,
					),
				),
			),
			0
		);
		list( $p, $a ) = $this->approve( $fid );

		$res = $this->apply( $p, $a );
		remove_filter( 'post_type_link', $link, 10 );

		$this->assertWPError( $res );
		$this->assertSame( 'sseo_apply_failed', $res->get_error_code() );
		add_filter( 'post_type_link', $link, 10, 2 );
		$this->assertSame( $url, get_permalink( $tour ), 'URL unchanged' );
		remove_filter( 'post_type_link', $link, 10 );
		$this->assertSame( array( (int) $second['term_id'] ), array_map( 'intval', wp_get_object_terms( $tour, 'tour_cat', array( 'fields' => 'ids' ) ) ) );
		$c = Db::row( 'SELECT status, error FROM {changes} ORDER BY id DESC LIMIT 1' );
		$this->assertSame( 'failed', $c['status'] );
		$this->assertStringContainsString( 'permalink', $c['error'] );
	}

	public function test_k_two_rollbacks_of_one_change_cannot_both_run() {
		global $wpdb;
		list( $src, $fid ) = $this->pair( self::TEXT );
		list( , $c )       = $this->apply_ok( $fid );
		$change_id         = (int) $c['change_id'];
		$first             = null;
		$fired             = false;
		$key               = 'sseo_mutex_object_post_' . $src;
		// Request 2 has read the change as "applied" and is about to take the object lock; request 1
		// runs completely at that moment.
		$this->on_query = function ( $sql ) use ( &$first, &$fired, $key, $change_id ) {
			if ( ! $fired && false !== strpos( $sql, 'INSERT IGNORE INTO' ) && false !== strpos( $sql, $key ) ) {
				$fired = true;
				$first = RollbackService::rollback( $change_id );
			}
			return null;
		};
		add_filter( 'query', array( $this, 'intercept' ) );
		$second = RollbackService::rollback( $change_id );
		remove_filter( 'query', array( $this, 'intercept' ) );

		$this->assertTrue( $fired );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$this->assertSame( 'rolled_back', $first['status'] );
		$this->assertWPError( $second );
		$this->assertSame( 'sseo_invalid_state', $second->get_error_code() );
		$this->assertSame( 'rolled_back', Db::value( 'SELECT status FROM {changes} WHERE id = %d', array( $change_id ) ), 'status does not go back to "applied"' );
		$this->assertSame( self::TEXT, get_post_field( 'post_content', $src, 'raw' ) );
		unset( $wpdb );
	}

	public function test_l_a_change_interrupted_after_the_write_is_recovered() {
		list( $src, $fid ) = $this->pair( self::TEXT );
		list( $p, $a )     = $this->approve( $fid );
		$key               = 'integrity-dead-1';
		$res               = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => $key,
			)
		);
		$this->assertSame( 'applied', $res['status'] );
		// The process died between the write and the status update.
		Db::query( "UPDATE {changes} SET status = 'applying', applied_at = NULL WHERE id = %d", array( $res['change_id'] ) );
		Db::query( "UPDATE {proposals} SET status = 'approved' WHERE id = %d", array( $p['id'] ) );

		$replay = Api::apply_approved_change(
			array(
				'proposal_id'     => $p['id'],
				'approval_id'     => $a['id'],
				'idempotency_key' => $key,
			)
		);
		$this->assertSame( 'applied', $replay['status'], 'the written change is recognised' );
		$this->assertTrue( $replay['reversible'] );
		$this->assertSame( 'applied', Db::value( 'SELECT status FROM {proposals} WHERE id = %d', array( $p['id'] ) ) );
		$rb = RollbackService::rollback( $res['change_id'] );
		$this->assertIsArray( $rb, is_wp_error( $rb ) ? $rb->get_error_message() : '' );
		$this->assertSame( self::TEXT, get_post_field( 'post_content', $src, 'raw' ) );

		// Died before the write: recognised as not written.
		list( $src2, $fid2 ) = $this->pair( self::TEXT );
		list( $p2, $a2 )     = $this->approve( $fid2 );
		$res2                = $this->apply( $p2, $a2 );
		$this->assertSame( 'applied', $res2['status'] );
		Db::query( "UPDATE {changes} SET status = 'applying', applied_at = NULL WHERE id = %d", array( $res2['change_id'] ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => self::TEXT ), array( 'ID' => $src2 ) );
		clean_post_cache( $src2 );
		$listed = ChangeExecutor::query( array( 'object_id' => $src2 ) );
		$this->assertSame( 'failed', $listed[0]['status'] );
	}
}
