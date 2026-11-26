<?php
/**
 * Uninstall handler.
 *
 * Data is removed only if "Delete all data when the plugin is deleted" is
 * enabled in the plugin settings. Otherwise trips, bookings and settings are
 * kept so a reinstall picks up where the site left off.
 *
 * @package WPInterface\Travel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\WPInterface\Travel\Autoloader::register();

\WPInterface\Travel\Install\Uninstaller::run();
