<?php
/**
 * PHPUnit bootstrap (WordPress test suite, e.g. inside wp-env's tests-cli).
 *
 * @package WPInterface\Travel
 */

$wpitravel_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wpitravel_tests_dir ) {
	$wpitravel_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $wpitravel_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found in {$wpitravel_tests_dir}. Run the tests via `npm run test:php` (wp-env).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output.
	exit( 1 );
}

$wpitravel_polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( file_exists( $wpitravel_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( $wpitravel_polyfills ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core test-suite constant.
}

require_once $wpitravel_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// Elementor, for ElementorTest: WPITRAVEL_TESTS_ELEMENTOR=1 with Elementor installed next to the plugin.
		$elementor = dirname( __DIR__, 3 ) . '/elementor/elementor.php';
		if ( getenv( 'WPITRAVEL_TESTS_ELEMENTOR' ) && file_exists( $elementor ) ) {
			require $elementor;
		}

		require dirname( __DIR__, 2 ) . '/wpinterface-travel.php';
	}
);

// The wp-env test site runs on "localhost", so WordPress's default sender
// (wordpress@localhost) fails PHPMailer's address check and no email is sent.
tests_add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );

// Each test runs in a transaction; nested START TRANSACTION would commit it, so use savepoints.
tests_add_filter( 'wpitravel_db_use_savepoint', '__return_true' );

require $wpitravel_tests_dir . '/includes/bootstrap.php';
