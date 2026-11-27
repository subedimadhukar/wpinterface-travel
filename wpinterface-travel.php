<?php
/**
 * Plugin Name:       WPInterface Travel – Tour & Trip Booking
 * Plugin URI:        https://wpinterface.com/plugins/wpinterface-travel/
 * Description:       Tour and trip booking for travel agencies: itineraries, departures, pricing, online booking and payments.
 * Version:           0.9.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            WPInterface
 * Author URI:        https://wpinterface.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpinterface-travel
 * Domain Path:       /languages
 *
 * @package WPInterface\Travel
 */

defined( 'ABSPATH' ) || exit;

// This file must stay parseable by old PHP versions so the requirements notice can render.
define( 'WPITRAVEL_VERSION', '0.9.0' );
define( 'WPITRAVEL_DB_VERSION', 8 );
define( 'WPITRAVEL_FILE', __FILE__ );
define( 'WPITRAVEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPITRAVEL_URL', plugin_dir_url( __FILE__ ) );
define( 'WPITRAVEL_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPITRAVEL_MIN_PHP', '7.4' );
define( 'WPITRAVEL_MIN_WP', '6.9' );

/**
 * Whether the server meets the minimum PHP and WordPress versions.
 *
 * @return bool
 */
function wpitravel_requirements_met() {
	global $wp_version;

	return version_compare( PHP_VERSION, WPITRAVEL_MIN_PHP, '>=' )
		&& version_compare( $wp_version, WPITRAVEL_MIN_WP, '>=' );
}

/**
 * Admin notice shown when requirements are not met.
 *
 * @return void
 */
function wpitravel_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: required WordPress version. */
				__( 'WPInterface Travel requires PHP %1$s and WordPress %2$s or newer. The plugin is inactive until the server is updated.', 'wpinterface-travel' ),
				WPITRAVEL_MIN_PHP,
				WPITRAVEL_MIN_WP
			)
		)
	);
}

if ( ! wpitravel_requirements_met() ) {
	add_action( 'admin_notices', 'wpitravel_requirements_notice' );
	return;
}

require_once WPITRAVEL_PATH . 'src/Autoloader.php';
\WPInterface\Travel\Autoloader::register();
require_once WPITRAVEL_PATH . 'src/functions.php';

register_activation_hook( WPITRAVEL_FILE, array( \WPInterface\Travel\Install\Activator::class, 'activate' ) );
register_deactivation_hook( WPITRAVEL_FILE, array( \WPInterface\Travel\Install\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \WPInterface\Travel\Plugin::instance(), 'boot' ) );
