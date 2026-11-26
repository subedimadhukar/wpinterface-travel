<?php
/**
 * Uninstall routine.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Install;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Options;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes plugin data only when "Delete all data when the plugin is deleted"
 * is enabled on that site. Bookings are business records, so the default is
 * to keep everything.
 */
final class Uninstaller {

	/**
	 * Runs for every site on multisite, otherwise for the current site.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Cleans up the current site.
	 */
	private static function uninstall_site(): void {
		foreach ( Deactivator::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$settings = get_option( Options::OPTION, array() );

		if ( empty( $settings['general']['delete_data_on_uninstall'] ) ) {
			return;
		}

		global $wpdb;

		self::delete_content();
		Schema::drop();
		Capabilities::remove_from_roles();

		$option_like    = $wpdb->esc_like( 'wpitravel_' ) . '%';
		$transient_like = $wpdb->esc_like( '_transient_wpitravel_' ) . '%';
		$timeout_like   = $wpdb->esc_like( '_transient_timeout_wpitravel_' ) . '%';
		$meta_like      = $wpdb->esc_like( '_wpitravel_' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Bulk cleanup on uninstall; no API exists for prefix deletes.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $option_like, $transient_like, $timeout_like ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $meta_like ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $meta_like ) );
		// phpcs:enable

		wp_cache_flush();
	}

	/**
	 * Deletes trips (with their meta, revisions and attachments' links) and
	 * the plugin's taxonomy terms. Media files themselves are kept.
	 */
	private static function delete_content(): void {
		// Uninstall runs without booting the plugin; the types must exist for the APIs below.
		PostTypes::register_all( array() );

		global $wpdb;

		// Every trip in every status and language, without query filters
		// (translation plugins would otherwise hide some).
		$trip_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", PostTypes::TRIP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall.

		foreach ( $trip_ids as $trip_id ) {
			wp_delete_post( (int) $trip_id, true );
		}

		foreach ( PostTypes::taxonomies() as $taxonomy ) {
			$term_ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( ! is_array( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}
}
