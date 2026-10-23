<?php
/**
 * Dashboard data: counts equal the database, the priority queue is ordered, explained, filterable
 * and exportable, the REST endpoints are readable by authorised users only and never cached.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\PriorityQueue;
use SemanticSEO\Analysis\ScanHistory;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Services\HealthReport;

/**
 * @group admin
 */
final class DashboardDataTest extends TestCase {

	public function test_counts_equal_the_database() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$c = HealthReport::counts();
		$this->assertSame( (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND excluded = 0" ), $c['inventoried'] );
		$this->assertSame( $c['inventoried'], $c['analyzed'], 'a full pipeline analyzes every published page' );
		$this->assertSame( $c['inventoried'], array_sum( $c['by_language'] ) );
		$this->assertArrayHasKey( 'vi', $c['by_language'] );
		$this->assertArrayHasKey( 'en', $c['by_language'] );
		$this->assertSame( (int) Db::value( "SELECT COUNT(DISTINCT document_id) FROM {findings} WHERE status = 'open' AND type = 'qa' AND check_id = 'orphan'" ), $c['orphans'] );
		$this->assertSame( (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open' AND label = 'verified_error'" ), $c['technical_errors'] );
		$this->assertGreaterThan( 0, $c['overlap_pages'] );
		$this->assertSame( array_sum( $c['priorities'] ), PriorityQueue::items( array( 'limit' => 1 ) )['total'] );

		$o = HealthReport::overview();
		$this->assertSame( 'ok', $o['health'] );
		$this->assertTrue( $o['read_only'] );
		$this->assertNotEmpty( $o['permissions']['allowed'] );
		$this->assertContains( 'Create 301 redirects', $o['permissions']['not_allowed'] );
		// The browser runtime is bundled by the release build (npm run build:vendor), not in every test checkout.
		$bundled = file_exists( SSEO_DIR . 'assets/vendor/transformers/transformers.min.js' );
		$this->assertSame( $bundled, $o['qwen']['runtime_bundled'] );
		$this->assertSame( $bundled ? 'not_enabled' : 'unavailable', $o['qwen']['state'] );
	}

	public function test_priority_queue_is_ordered_explained_and_filterable() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$res   = PriorityQueue::items( array( 'limit' => 500 ) );
		$items = $res['items'];
		$this->assertSame( $res['total'], count( $items ) );
		$prev = 0;
		foreach ( $items as $i ) {
			$this->assertGreaterThanOrEqual( $prev, $i['priority'], 'priority order' );
			$prev = $i['priority'];
			$this->assertNotSame( '', $i['problem'] );
			$this->assertNotSame( '', $i['action'] );
			$this->assertNotSame( '', $i['url'], $i['check_id'] . ' has a page URL' );
			$this->assertContains( $i['confidence'], array( 'high', 'medium', 'low', 'manual_review' ) );
		}
		$by_check = array();
		foreach ( $items as $i ) {
			$by_check[ $i['type'] . ':' . $i['check_id'] ] = $i;
		}
		$broken = $by_check['qa:broken_internal_link'];
		$this->assertSame( 1, $broken['priority'] );
		$this->assertSame( 'verified', $broken['finding_kind'] );
		$this->assertStringContainsString( 'bài đã bị xóa', $broken['evidence'], 'the evidence names the anchor of the broken link' );
		$link = $by_check['link:internal_link'];
		$this->assertNotSame( '', $link['target_url'] );
		$this->assertStringContainsString( 'Anchor', $link['evidence'] );
		$this->assertArrayHasKey( 'cluster:cluster_without_pillar', $by_check, 'topic groups without a confirmed pillar are proposed' );
		$this->assertArrayNotHasKey( 'cannibalization:distinct_facet', $by_check, 'informational overlap is not a task' );
		$this->assertArrayNotHasKey( 'cannibalization:pillar_supporting', $by_check );

		// Filters.
		foreach ( PriorityQueue::items( array( 'category' => 'links', 'limit' => 500 ) )['items'] as $i ) {
			$this->assertSame( 'links', $i['category'] );
		}
		foreach ( PriorityQueue::items( array( 'locale' => 'en', 'limit' => 500 ) )['items'] as $i ) {
			$this->assertSame( 'en', $i['locale'] );
		}
		$p1 = PriorityQueue::items( array( 'priority' => 1 ) );
		$this->assertSame( PriorityQueue::counts()[1], $p1['total'] );
		$search = PriorityQueue::items( array( 'search' => $broken['page_title'] ) );
		$this->assertGreaterThan( 0, $search['total'] );
		foreach ( $search['items'] as $i ) {
			$this->assertTrue( false !== strpos( $i['page_title'] . $i['target_title'], $broken['page_title'] ) );
		}
		$this->assertLessThan( $res['total'], PriorityQueue::items( array( 'search' => '%' ) )['total'], 'LIKE wildcards are escaped' );
	}

	public function test_priority_csv_export_is_complete_and_formula_safe() {
		$this->load_corpus();
		self::factory()->post->create(
			array(
				'post_title'   => '=HYPERLINK("https://evil.example","x")',
				'post_content' => '<p>' . str_repeat( 'Một bài không có liên kết đến. ', 40 ) . '</p>',
			)
		);
		$this->run_full_pipeline();
		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$n   = PriorityQueue::csv( $out, array() );
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->assertSame( PriorityQueue::items( array( 'limit' => 1 ) )['total'], $n );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv, 'BOM for Excel' );
		$rows = array_map(
			static function ( $line ) {
				return str_getcsv( $line, ',', '"', '\\' ); // Explicit escape: PHP 8.4 deprecates the default.
			},
			array_filter( explode( "\n", trim( substr( $csv, 3 ) ) ) )
		);
		$this->assertSame( 'priority', $rows[0][0] );
		$this->assertStringNotContainsString( ',=HYPERLINK', $csv, 'a cell never starts with a formula' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}

	public function test_pages_that_are_not_published_stay_hidden_from_readers() {
		\SemanticSEO\Settings::update( array( 'include_drafts' => true ) );
		$draft = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Bản nháp chưa công bố Zeta',
				'post_content' => '<p>' . str_repeat( 'Nội dung nháp về thuê xe máy. ', 30 ) . '</p>',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'   => 'Bài công khai trỏ tới bản nháp',
				'post_content' => '<p>' . str_repeat( 'Hướng dẫn thuê xe máy ở Hà Nội. ', 30 ) . '<a href="' . esc_url( home_url( '/?p=' . $draft ) ) . '">xem thêm</a></p>',
			)
		);
		$this->load_corpus();
		$this->run_full_pipeline();

		$all = wp_json_encode( PriorityQueue::items( array( 'limit' => 500 ) )['items'] );
		$this->assertStringContainsString( 'Zeta', $all, 'scenario: an editor sees the draft named as the link target' );
		$public = PriorityQueue::items(
			array(
				'limit'       => 500,
				'public_only' => true,
			)
		);
		$this->assertStringNotContainsString( 'Zeta', wp_json_encode( $public['items'] ) );
		$this->assertSame(
			0,
			PriorityQueue::items(
				array(
					'search'      => 'Zeta',
					'public_only' => true,
				)
			)['total'],
			'the title of a draft cannot be probed through search'
		);
		$this->assertSame( array_sum( PriorityQueue::counts( array( 'public_only' => true ) ) ), $public['total'] );

		// Over REST, a reader without the right to edit others' posts gets the public view.
		do_action( 'rest_api_init' );
		$reader = self::factory()->user->create( array( 'role' => 'author' ) );
		( new \WP_User( $reader ) )->add_cap( Capabilities::READ );
		wp_set_current_user( $reader );
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/semantic-seo/v1/priorities' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringNotContainsString( 'Zeta', wp_json_encode( $res->get_data() ) );
		wp_set_current_user( $this->admin );
	}

	public function test_scan_comparison_is_fixed_when_the_scan_ends_and_hides_private_pages() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$this->run_full_pipeline();
		$diff = ScanHistory::diff( 50 );
		$this->assertNotNull( $diff['previous'] );
		$this->assertIsInt( $diff['new_count'] );

		// A later trial or partial analysis adds findings: the comparison of the completed scan
		// does not change.
		$doc = (int) Db::value( "SELECT id FROM {documents} WHERE status = 'publish' ORDER BY id LIMIT 1" );
		$id  = Db::insert(
			'findings',
			array(
				'type'        => 'qa',
				'check_id'    => 'missing_title',
				'document_id' => $doc,
				'fingerprint' => sha1( 'later-finding' ),
				'label'       => 'verified_error',
				'confidence'  => 'high',
				'status'      => 'open',
				'evidence'    => '{}',
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		$after = ScanHistory::diff( 50 );
		$this->assertSame( $diff['new_count'], $after['new_count'] );
		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( $after['new'], 'id' ) ) );

		// A finding of a page that is no longer published is listed only for editors.
		$hidden = (int) Db::value( 'SELECT document_id FROM {findings} WHERE id = %d', array( $id ) );
		Db::query( "UPDATE {documents} SET status = 'private' WHERE id = %d", array( $hidden ) );
		$all             = ScanHistory::all();
		$last            = count( $all ) - 1;
		$all[ $last ]['new_ids'] = array( $id );
		update_option( ScanHistory::OPTION, $all, false );
		$this->assertSame( array( $id ), array_map( 'intval', wp_list_pluck( ScanHistory::diff( 50 )['new'], 'id' ) ) );
		$this->assertSame( array(), ScanHistory::diff( 50, true )['new'] );
	}

	public function test_rest_endpoints_are_authorised_and_never_cached() {
		$this->load_corpus();
		$this->run_full_pipeline();
		do_action( 'rest_api_init' );
		foreach ( array( '/semantic-seo/v1/health', '/semantic-seo/v1/priorities', '/semantic-seo/v1/scans' ) as $route ) {
			wp_set_current_user( 0 );
			$res = rest_do_request( new \WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 401, $res->get_status(), $route . ' needs authentication' );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', $route ) )->get_status(), $route . ' needs sseo_read' );
			wp_set_current_user( $this->admin );
			$req = new \WP_REST_Request( 'GET', $route );
			$res = rest_get_server()->dispatch( $req );
			$res = apply_filters( 'rest_post_dispatch', $res, rest_get_server(), $req );
			$this->assertSame( 200, $res->get_status(), $route );
			$this->assertStringContainsString( 'no-store', $res->get_headers()['Cache-Control'] );
			$this->assertSame( 'no-cache', $res->get_headers()['X-LiteSpeed-Cache-Control'] );
		}
		$req = new \WP_REST_Request( 'GET', '/semantic-seo/v1/priorities' );
		$req->set_query_params(
			array(
				'category' => 'technical',
				'limit'    => 5,
			)
		);
		$data = rest_get_server()->dispatch( $req )->get_data()['data'];
		$this->assertLessThanOrEqual( 5, count( $data['items'] ) );
		foreach ( $data['items'] as $i ) {
			$this->assertSame( 'technical', $i['category'] );
			$this->assertStringContainsString( 'admin.php?page=sseo-', $i['detail_url'] );
		}
		$health = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/semantic-seo/v1/health' ) )->get_data()['data'];
		$this->assertTrue( $health['database']['ready'] );
		$this->assertSame( 16, $health['database']['tables_present'] );
		$this->assertNotEmpty( $health['system_check'] );
		$bad = new \WP_REST_Request( 'GET', '/semantic-seo/v1/priorities' );
		$bad->set_query_params( array( 'category' => 'drop table' ) );
		$this->assertSame( 400, rest_do_request( $bad )->get_status(), 'unknown filter values are rejected' );
		unset( $data, $health );
		$this->assertTrue( current_user_can( Capabilities::READ ) );
	}
}
