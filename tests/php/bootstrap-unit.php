<?php
/**
 * Bootstrap for pure unit tests (no WordPress, no database).
 *
 * @package SemanticSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/includes/Autoloader.php';
\SemanticSEO\Autoloader::register();
