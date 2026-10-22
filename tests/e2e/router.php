<?php
/**
 * Router for `php -S` on disposable test sites: serve existing files/directories as-is,
 * route everything else to WordPress (pretty permalinks, /wp-json/).
 *
 * @package SemanticSEO
 */

$sseo_path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ); // phpcs:ignore
if ( '/' !== $sseo_path && file_exists( __DIR__ . $sseo_path ) ) {
	return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
