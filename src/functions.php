<?php
/**
 * Public helper functions.
 *
 * @package WPInterface\Travel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the plugin instance.
 *
 * @return \WPInterface\Travel\Plugin
 */
function wpitravel() {
	return \WPInterface\Travel\Plugin::instance();
}

/**
 * Returns the full name of a plugin table for the current site.
 *
 * @param string $key Table key without prefixes, e.g. "bookings".
 * @return string
 */
function wpitravel_table( $key ) {
	return \WPInterface\Travel\Install\Schema::table( (string) $key );
}
