<?php
/**
 * Dashboard data: counts equal the database, the priority queue is ordered, explained, filterable
 * and exportable, the REST endpoints are readable by authorised users only and never cached.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Analysis\PriorityQueue;
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
		$this->assertSame( 'not_enabled', $o['qwen']['state'] );
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
		$rows = array_map( 'str_getcsv', array_filter( explode( "\n", trim( substr( $csv, 3 ) ) ) ) );
		$this->assertSame( 'priority', $rows[0][0] );
		$this->assertStringNotContainsString( ',=HYPERLINK', $csv, 'a cell never starts with a formula' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
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
