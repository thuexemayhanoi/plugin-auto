<?php
/**
 * TEST ONLY — never install on a real site.
 *
 * Must-use plugin for disposable e2e sites: points the embedding profile at the TINY RANDOM
 * FIXTURE model in tests/fixtures/tiny-qwen3 (NOT Qwen3-Embedding) served by
 * tests/e2e/static-server.mjs, via the plugin's documented `sseo_model_profile` filter.
 * Requires SSEO_FIXTURE_PROFILE (path to expected.json) and SSEO_FIXTURE_HOST in wp-config.php.
 *
 * @package SemanticSEO
 */

add_filter(
	'sseo_model_profile',
	static function ( $profile ) {
		if ( ! defined( 'SSEO_FIXTURE_PROFILE' ) || ! defined( 'SSEO_FIXTURE_HOST' ) || ! is_readable( SSEO_FIXTURE_PROFILE ) ) {
			return $profile;
		}
		$fixture = json_decode( (string) file_get_contents( SSEO_FIXTURE_PROFILE ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $fixture ) ) {
			return $profile;
		}
		$p                  = $fixture['profile'];
		$p['host']          = SSEO_FIXTURE_HOST;
		$p['reference']     = $fixture['reference'];
		$p['license']       = 'test-fixture';
		$p['base_model']    = 'none (random test fixture)';
		$p['base_revision'] = '';
		unset( $p['model_key'] );
		return array_merge( $profile, $p );
	}
);
