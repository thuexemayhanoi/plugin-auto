<?php
/**
 * PSR-4 style autoloader for the SemanticSEO namespace (no Composer needed at runtime).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Maps SemanticSEO\Foo\Bar to includes/Foo/Bar.php and SemanticSEO\Admin\X to admin/controllers/X.php.
 */
final class Autoloader {

	/**
	 * Registers the autoloader once.
	 *
	 * @return void
	 */
	public static function register() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$prefix = 'SemanticSEO\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( preg_match( '/[^A-Za-z0-9_\\\\]/', $relative ) ) {
			return;
		}
		$base = dirname( __DIR__ ) . '/';
		if ( 0 === strpos( $relative, 'Admin\\' ) ) {
			$file = $base . 'admin/controllers/' . str_replace( '\\', '/', substr( $relative, 6 ) ) . '.php';
		} else {
			$file = $base . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		}
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
