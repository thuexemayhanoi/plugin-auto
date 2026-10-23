<?php
/**
 * Integration: WordPress Abilities API registration, schemas, permissions and opt-in exposure.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Abilities\AbilitiesRegistrar;
use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Settings;

/**
 * @group abilities
 */
final class AbilitiesTest extends TestCase {

	public function set_up() {
		parent::set_up();
		if ( ! Environment::abilities_available() ) {
			$this->markTestSkipped( 'Abilities API requires WordPress 6.9+ (version-gated).' );
		}
	}

	/**
	 * Re-registers abilities with current settings (the registry is a singleton).
	 *
	 * @return void
	 */
	private function reregister() {
		foreach ( array_keys( AbilitiesRegistrar::definitions() ) as $slug ) {
			if ( wp_has_ability( 'semantic-seo/' . $slug ) ) {
				wp_unregister_ability( 'semantic-seo/' . $slug );
			}
		}
		$registry = \WP_Abilities_Registry::get_instance();
		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		// Simulate the init action so wp_register_ability accepts registrations.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		AbilitiesRegistrar::register_abilities();
		array_pop( $wp_current_filter );
		unset( $registry );
	}

	public function test_all_fifteen_abilities_registered_private_by_default() {
		$this->reregister();
		$this->assertTrue( wp_has_ability_category( 'semantic-seo' ) );
		$names = array_keys( AbilitiesRegistrar::definitions() );
		$this->assertCount( 15, $names );
		foreach ( $names as $slug ) {
			$ability = wp_get_ability( 'semantic-seo/' . $slug );
			$this->assertInstanceOf( \WP_Ability::class, $ability, $slug );
			$meta = $ability->get_meta();
			$this->assertFalse( $meta['show_in_rest'], $slug . ' private by default' );
			$this->assertFalse( $meta['mcp']['public'], $slug . ' not exposed to MCP by default' );
			$this->assertNotEmpty( $ability->get_output_schema(), $slug );
		}
		$this->assertTrue( wp_get_ability( 'semantic-seo/get-site-status' )->get_meta()['annotations']['readonly'] );
		$this->assertTrue( wp_get_ability( 'semantic-seo/apply-approved-change' )->get_meta()['annotations']['destructive'] );
	}

	public function test_opt_in_exposure_rules() {
		Settings::update(
			array(
				'abilities_rest' => array( 'semantic-seo/get-site-status', 'semantic-seo/apply-approved-change' ),
				'mcp_enabled'    => true,
				'mcp_abilities'  => array( 'semantic-seo/get-site-status', 'semantic-seo/list-urls', 'semantic-seo/apply-approved-change' ),
			)
		);
		$this->assertSame( array( 'semantic-seo/get-site-status', 'semantic-seo/list-urls' ), Settings::get( 'mcp_abilities' ), 'Write abilities can never be selected for MCP.' );
		$this->reregister();
		$this->assertTrue( wp_get_ability( 'semantic-seo/get-site-status' )->get_meta()['show_in_rest'] );
		$this->assertTrue( wp_get_ability( 'semantic-seo/get-site-status' )->get_meta()['mcp']['public'] );
		$this->assertFalse( wp_get_ability( 'semantic-seo/list-urls' )->get_meta()['show_in_rest'] );
		$this->assertTrue( wp_get_ability( 'semantic-seo/list-urls' )->get_meta()['mcp']['public'] );
		$this->assertFalse( wp_get_ability( 'semantic-seo/apply-approved-change' )->get_meta()['mcp']['public'] );
		$this->assertFalse( wp_get_ability( 'semantic-seo/get-qa-issues' )->get_meta()['mcp']['public'] );
	}

	public function test_execute_validates_input_output_and_permissions() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$this->reregister();
		$status = wp_get_ability( 'semantic-seo/get-site-status' )->execute();
		$this->assertIsArray( $status, is_wp_error( $status ) ? $status->get_error_message() : '' );
		$this->assertSame( SSEO_VERSION, $status['plugin_version'] );

		$list = wp_get_ability( 'semantic-seo/list-urls' )->execute( array( 'limit' => 3, 'locale' => 'vi' ) );
		$this->assertIsArray( $list, is_wp_error( $list ) ? $list->get_error_message() : '' );
		$this->assertCount( 3, $list['items'] );

		foreach ( array( 'get-link-suggestions', 'get-cannibalization-report', 'get-qa-issues', 'get-topic-clusters' ) as $slug ) {
			$out = wp_get_ability( 'semantic-seo/' . $slug )->execute( array() );
			$this->assertIsArray( $out, $slug . ': ' . ( is_wp_error( $out ) ? $out->get_error_message() : '' ) );
		}
		$analysis = wp_get_ability( 'semantic-seo/get-url-analysis' )->execute( array( 'post_id' => $this->posts['en-pillar'] ) );
		$this->assertSame( 'en', $analysis['document']['locale'] );

		$bad = wp_get_ability( 'semantic-seo/list-urls' )->execute( array( 'limit' => 1000 ) );
		$this->assertWPError( $bad, 'Input schema enforced.' );
		$bad = wp_get_ability( 'semantic-seo/list-urls' )->execute( array( 'unknown' => 1 ) );
		$this->assertWPError( $bad, 'additionalProperties=false enforced.' );

		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$denied = wp_get_ability( 'semantic-seo/get-site-status' )->execute();
		$this->assertWPError( $denied );

		Capabilities::ensure_agent_roles();
		$reader = self::factory()->user->create( array( 'role' => 'sseo_agent_reader' ) );
		wp_set_current_user( $reader );
		$this->assertIsArray( wp_get_ability( 'semantic-seo/get-site-status' )->execute() );
		$this->assertWPError( wp_get_ability( 'semantic-seo/start-analysis' )->execute( array( 'type' => 'analyze' ) ) );
		$this->assertWPError(
			wp_get_ability( 'semantic-seo/apply-approved-change' )->execute(
				array(
					'proposal_id'     => 1,
					'approval_id'     => 1,
					'idempotency_key' => 'abcdefgh',
				)
			)
		);
	}

	public function test_abilities_rest_run_endpoint_respects_opt_in() {
		$this->load_corpus();
		$this->run_full_pipeline();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->reregister();
		do_action( 'rest_api_init', $wp_rest_server );
		$req = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/semantic-seo/get-site-status/run' );
		$res = rest_do_request( $req );
		$this->assertContains( $res->get_status(), array( 403, 404 ), 'Not reachable over REST before opt-in.' );

		Settings::update( array( 'abilities_rest' => array( 'semantic-seo/get-site-status' ) ) );
		$this->reregister();
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/semantic-seo/get-site-status/run' ) );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( SSEO_VERSION, $res->get_data()['plugin_version'] );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
