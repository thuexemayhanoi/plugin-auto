<?php
/**
 * Bootstrap for WordPress integration tests (real WordPress + MariaDB/MySQL test database).
 *
 * @package SemanticSEO
 */

$sseo_root = dirname( __DIR__, 2 );
require_once $sseo_root . '/vendor/autoload.php';

$sseo_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/opt/wp/wpdev-7.1.3/tests/phpunit';
if ( ! file_exists( $sseo_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$sseo_tests_dir}. Run bin/install-wp-tests.sh <version> and export the printed variables.\n" ); // phpcs:ignore
	exit( 1 );
}
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $sseo_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $sseo_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $sseo_root ) {
		require $sseo_root . '/semantic-seo-engine-vi-en.php';
		\SemanticSEO\Plugin::activate();
	}
);

require $sseo_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/Integration/TestCase.php';
require_once __DIR__ . '/Integration/RelevanceMetrics.php';
