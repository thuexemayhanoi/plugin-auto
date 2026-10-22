<?php
/**
 * Integration: REST API permissions, schema validation, pagination, rate limits and abuse cases.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\REST\RestController;
use SemanticSEO\Settings;

/**
 * @group rest
 * @group security
 */
final class RestApiTest extends TestCase {

	/**
	 * REST server.
	 *
	 * @var \WP_REST_Server
	 */
	private $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		parent::tear_down();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route (without namespace).
	 * @param array  $params Params.
	 * @return \WP_REST_Response
	 */
	private function call( $method, $route, array $params = array() ) {
		$req = new \WP_REST_Request( $method, '/' . RestController::NS . $route );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Validates a response against the route's declared schema.
	 *
	 * @param string            $route Route.
	 * @param \WP_REST_Response $res   Response.
	 * @return void
	 */
	private function assert_matches_schema( $route, \WP_REST_Response $res ) {
		$routes  = $this->server->get_routes();
		$handler = $routes[ '/' . RestController::NS . $route ][0];
		$this->assertArrayHasKey( 'schema', $handler, $route . ' declares a schema' );
		$schema = call_user_func( $handler['schema'] );
		$data   = json_decode( wp_json_encode( $res->get_data() ), true );
		$valid  = rest_validate_value_from_schema( $data, $schema, 'response' );
		$this->assertTrue( true === $valid, $route . ': ' . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
	}

	public function test_routes_registered_without_approve_endpoint() {
		$routes = array_keys( $this->server->get_routes() );
		foreach ( array( '/status', '/inventory', '/analysis', '/suggestions/links', '/cannibalization', '/clusters', '/qa', '/jobs', '/changes/preview', '/changes/apply', '/changes/rollback', '/audit', '/embeddings/claim' ) as $r ) {
			$this->assertContains( '/' . RestController::NS . $r, $routes );
		}
		foreach ( $routes as $r ) {
			$this->assertStringNotContainsString( 'approve', $r, 'Approvals are never exposed over REST.' );
		}
		foreach ( $this->server->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/' . RestController::NS . '/' ) ) {
				continue; // The namespace index route is core-generated.
			}
			foreach ( $handlers as $h ) {
				$this->assertArrayHasKey( 'permission_callback', $h, $route );
				$this->assertNotSame( '__return_true', $h['permission_callback'], $route );
			}
		}
	}

	public function test_guest_and_subscriber_are_denied_everywhere() {
		wp_set_current_user( 0 );
		foreach ( array( '/status', '/inventory', '/analysis', '/suggestions/links', '/qa', '/audit', '/jobs' ) as $r ) {
			$res = $this->call( 'GET', $r );
			$this->assertSame( 401, $res->get_status(), 'guest ' . $r );
		}
		$this->assertSame( 401, $this->call( 'POST', '/jobs', array( 'type' => 'analyze' ) )->get_status() );
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		foreach ( array( '/status', '/inventory', '/qa' ) as $r ) {
			$this->assertSame( 403, $this->call( 'GET', $r )->get_status(), 'subscriber ' . $r );
		}
		$this->assertSame( 403, $this->call( 'POST', '/changes/apply', array( 'proposal_id' => 1, 'approval_id' => 1, 'idempotency_key' => 'abcdefgh' ) )->get_status() );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->call( 'GET', '/status' )->get_status(), 'Editors get nothing unless granted.' );
	}

	public function test_admin_responses_match_schemas_and_paginate() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$res = $this->call( 'GET', '/status' );
		$this->assertSame( 200, $res->get_status() );
		$this->assert_matches_schema( '/status', $res );

		$page1 = $this->call( 'GET', '/inventory', array( 'limit' => 5 ) );
		$this->assertSame( 200, $page1->get_status() );
		$this->assert_matches_schema( '/inventory', $page1 );
		$d1 = $page1->get_data()['data'];
		$this->assertCount( 5, $d1['items'] );
		$this->assertNotNull( $d1['next_cursor'] );
		$page2 = $this->call(
			'GET',
			'/inventory',
			array(
				'limit'  => 5,
				'cursor' => $d1['next_cursor'],
			)
		);
		$d2    = $page2->get_data()['data'];
		$this->assertEmpty( array_intersect( array_column( $d1['items'], 'document_id' ), array_column( $d2['items'], 'document_id' ) ), 'Pages do not overlap.' );

		foreach ( array( '/suggestions/links', '/cannibalization', '/qa' ) as $r ) {
			$res = $this->call( 'GET', $r, array( 'limit' => 3 ) );
			$this->assertSame( 200, $res->get_status(), $r );
			$this->assert_matches_schema( $r, $res );
		}
		$res = $this->call( 'GET', '/analysis', array( 'post_id' => $this->posts['vi-pillar'] ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assert_matches_schema( '/analysis', $res );
		$data = $res->get_data()['data'];
		$this->assertArrayHasKey( 'facts', $data );
		$this->assertArrayHasKey( 'inferences', $data );
		$this->assertSame( 'heuristic cue matching', $data['inferences']['intent']['source'] );

		$res = $this->call( 'GET', '/clusters', array( 'locale' => 'vi' ) );
		$this->assert_matches_schema( '/clusters', $res );
	}

	public function test_invalid_input_is_rejected_by_schema() {
		$this->assertSame( 400, $this->call( 'GET', '/inventory', array( 'limit' => 500 ) )->get_status(), 'Page size cap.' );
		$this->assertSame( 400, $this->call( 'GET', '/inventory', array( 'sort' => 'id; DROP TABLE x' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/inventory', array( 'locale' => '../../etc' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/jobs', array( 'type' => 'shell' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/jobs', array( 'post_ids' => range( 1, 201 ) ) )->get_status(), 'Bounded targets.' );
		$this->assertSame( 400, $this->call( 'POST', '/changes/apply', array( 'proposal_id' => 1 ) )->get_status(), 'Missing approval + key.' );
		$this->assertSame( 400, $this->call( 'POST', '/index/rebuild', array( 'scope' => 'lexical' ) )->get_status(), 'confirm required.' );
	}

	public function test_ssrf_and_sql_injection_attempts_are_harmless() {
		$this->load_corpus();
		$this->run_full_pipeline();
		foreach ( array( 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:22/', 'file:///etc/passwd', 'gopher://example.org/' ) as $url ) {
			$res = $this->call( 'GET', '/analysis', array( 'url' => $url ) );
			$this->assertSame( 400, $res->get_status(), $url );
		}
		$count = (int) Db::value( 'SELECT COUNT(*) FROM {documents}' );
		$res   = $this->call( 'GET', '/inventory', array( 'search' => "' OR 1=1; DROP TABLE wptests_sseo_documents; --" ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 0, $res->get_data()['data']['total'] );
		$this->assertSame( $count, (int) Db::value( 'SELECT COUNT(*) FROM {documents}' ), 'Table intact.' );
	}

	public function test_rate_limit_returns_429_with_retry_after() {
		Settings::update( array( 'rate_read' => 10 ) );
		$codes = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$codes[] = $this->call( 'GET', '/status' )->get_status();
		}
		$this->assertContains( 429, $codes );
		$res = $this->call( 'GET', '/status' );
		$this->assertSame( 429, $res->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $res->get_headers() );
		$this->assertSame( 'sseo_rate_limited', $res->get_data()['error']['code'] );
	}

	public function test_large_payload_rejected() {
		$res = $this->call(
			'POST',
			'/embeddings/release',
			array(
				'lease_token' => str_repeat( 'a', 48 ),
				'error'       => 'x',
				'padding'     => str_repeat( 'x', 600000 ),
			)
		);
		$this->assertSame( 413, $res->get_status() );
	}

	public function test_job_start_is_idempotent_and_deduplicated() {
		$this->load_corpus();
		$a = $this->call(
			'POST',
			'/jobs',
			array(
				'type'            => 'inventory',
				'idempotency_key' => 'inv-key-0001',
			)
		);
		$this->assertSame( 200, $a->get_status() );
		$b = $this->call(
			'POST',
			'/jobs',
			array(
				'type'            => 'inventory',
				'idempotency_key' => 'inv-key-0001',
			)
		);
		$this->assertSame( $a->get_data()['data']['id'], $b->get_data()['data']['id'] );
		$this->assertTrue( $b->get_data()['data']['reused'] );
		$c = $this->call( 'POST', '/jobs', array( 'type' => 'inventory' ) );
		$this->assertSame( 409, $c->get_status(), 'No overlapping duplicate jobs.' );
		$this->assertSame( 'sseo_job_exists', $c->get_data()['error']['code'] );
		$cancel = $this->call( 'POST', '/jobs/' . $a->get_data()['data']['id'] . '/cancel' );
		$this->assertSame( 'cancelled', $cancel->get_data()['data']['status'] );
	}

	public function test_secrets_never_leak_in_responses_or_audit() {
		$this->load_corpus();
		$this->run_full_pipeline();
		Capabilities::ensure_agent_roles();
		$worker = self::factory()->user->create( array( 'role' => 'sseo_worker' ) );
		wp_set_current_user( $worker );
		$job = \SemanticSEO\Jobs\JobRepository::create( 'embed', array(), $this->admin, 'test' );
		\SemanticSEO\Jobs\JobRunner::tick( 10, (int) $job['id'] );
		$claim = $this->call( 'POST', '/embeddings/claim', array( 'limit' => 2 ) );
		$this->assertSame( 200, $claim->get_status() );
		$token = $claim->get_data()['data']['lease_token'];
		$this->assertSame( 48, strlen( $token ) );
		$this->assertNull( Db::value( 'SELECT id FROM {queue} WHERE lease_hash = %s', array( $token ) ), 'Only the token hash is stored.' );
		$this->call(
			'POST',
			'/embeddings/release',
			array(
				'lease_token' => $token,
				'error'       => 'Bearer abc.def.ghi password=hunter2',
			)
		);
		$audit = Db::col( 'SELECT details FROM {audit}' );
		foreach ( $audit as $d ) {
			$this->assertStringNotContainsString( $token, $d );
		}
		$status = wp_json_encode( $this->call( 'GET', '/status' )->get_data() );
		foreach ( array( wp_salt( 'auth' ), wp_salt( 'nonce' ), wp_salt( 'logged_in' ), 'DB_PASSWORD', 'lease_hash' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $status );
		}
	}
}
