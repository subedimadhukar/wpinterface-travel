<?php
/**
 * Deactivation.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Removes scheduled events and rewrite rules. Never deletes data.
 */
final class Deactivator {

	/**
	 * Deactivation hook.
	 */
	public static function deactivate(): void {
		foreach ( self::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		delete_option( 'wpitravel_flush_rewrite_rules' );
		flush_rewrite_rules( false );
	}

	/**
	 * Cron hooks registered by the plugin.
	 *
	 * @return list<string>
	 */
	public static function cron_hooks(): array {
		/**
		 * Filters the plugin's cron hooks, cleared on deactivation and uninstall.
		 *
		 * @param list<string> $hooks Hook names.
		 */
		return array_values( (array) apply_filters( 'wpitravel_cron_hooks', array( 'wpitravel_hourly', 'wpitravel_daily' ) ) );
	}
}
