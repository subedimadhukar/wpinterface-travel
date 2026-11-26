<?php
/**
 * PSR-4 autoloader for the plugin namespace.
 *
 * The plugin has no runtime Composer dependencies, so it ships its own
 * autoloader instead of a vendor directory.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel;

defined( 'ABSPATH' ) || exit;

/**
 * Maps WPInterface\Travel\Foo\Bar to src/Foo/Bar.php.
 */
final class Autoloader {

	private const PREFIX = 'WPInterface\\Travel\\';

	/**
	 * Registers the autoloader once.
	 */
	public static function register(): void {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		spl_autoload_register( array( __CLASS__, 'load' ) );
		$registered = true;
	}

	/**
	 * Loads a class file if it belongs to the plugin namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
}
