<?php
/**
 * Constants for static analysis.
 *
 * @package WPInterface\Travel
 */

define( 'WPITRAVEL_VERSION', '0.0.0' );
define( 'WPITRAVEL_DB_VERSION', 8 );
define( 'WPITRAVEL_FILE', __DIR__ . '/../wpinterface-travel.php' );
define( 'WPITRAVEL_PATH', dirname( __DIR__ ) . '/' );
define( 'WPITRAVEL_URL', 'https://example.org/wp-content/plugins/wpinterface-travel/' );
define( 'WPITRAVEL_BASENAME', 'wpinterface-travel/wpinterface-travel.php' );
define( 'WPITRAVEL_MIN_PHP', '7.4' );
define( 'WPITRAVEL_MIN_WP', '6.7' );

// Polylang's API, for static analysis (the plugin checks it exists first).
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
if ( ! function_exists( 'pll_get_post' ) ) {
	/**
	 * Polylang: a post's translation.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code.
	 * @return int|false|null
	 */
	function pll_get_post( $post_id, $lang = '' ) {
		return null;
	}

	/**
	 * Polylang: the default language.
	 *
	 * @param string $field Field.
	 * @return string|false
	 */
	function pll_default_language( $field = 'slug' ) {
		return false;
	}
}
// phpcs:enable
