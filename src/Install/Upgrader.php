<?php
/**
 * Install and upgrade routines.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Install;

use WPInterface\Travel\Content\TripIndex;

use WPInterface\Travel\Frontend\AccountPage;
use WPInterface\Travel\Options;
use WPInterface\Travel\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Runs migrations when the stored DB version is behind WPITRAVEL_DB_VERSION.
 *
 * Runs on every `plugins_loaded` (cheap: one autoloaded option read) because
 * the activation hook does not fire on plugin updates.
 */
final class Upgrader {

	public const VERSION_OPTION    = 'wpitravel_version';
	public const DB_VERSION_OPTION = 'wpitravel_db_version';
	private const LOCK_OPTION      = 'wpitravel_upgrade_lock';
	private const LOCK_TTL         = 600;

	/**
	 * Upgrades the current site when needed.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed < WPITRAVEL_DB_VERSION && self::acquire_lock() ) {
			try {
				self::upgrade( $installed );
			} finally {
				delete_option( self::LOCK_OPTION );
			}
		}

		if ( get_option( self::VERSION_OPTION ) !== WPITRAVEL_VERSION ) {
			update_option( self::VERSION_OPTION, WPITRAVEL_VERSION, true );
		}
	}

	/**
	 * Runs table updates and every migration newer than `$from`.
	 *
	 * @param int $from Installed DB version (0 on a fresh install).
	 */
	private static function upgrade( int $from ): void {
		Schema::install();

		foreach ( self::migrations() as $version => $callback ) {
			if ( $version > $from && $version <= WPITRAVEL_DB_VERSION ) {
				call_user_func( $callback );
			}
		}

		update_option( self::DB_VERSION_OPTION, WPITRAVEL_DB_VERSION, true );

		/**
		 * Fires after the plugin database was upgraded.
		 *
		 * @param int $from Previous DB version.
		 * @param int $to   New DB version.
		 */
		do_action( 'wpitravel_upgraded', $from, WPITRAVEL_DB_VERSION );
	}

	/**
	 * Migrations keyed by the DB version they upgrade to.
	 *
	 * @return array<int, callable>
	 */
	private static function migrations(): array {
		return array(
			1 => array( self::class, 'migrate_1' ),
			2 => array( self::class, 'migrate_2' ),
			3 => array( self::class, 'migrate_3' ),
			4 => array( self::class, 'migrate_4' ),
			5 => array( self::class, 'migrate_5' ),
			6 => array( self::class, 'migrate_6' ),
			7 => array( self::class, 'migrate_7' ),
			8 => array( self::class, 'migrate_8' ),
		);
	}

	/**
	 * DB version 8: a `meta` column on bookings (added by Schema::install())
	 * for data added by Pro and add-ons (deposit plan, invoice number…).
	 */
	public static function migrate_8(): void {
	}

	/**
	 * DB version 7: holds remember who made them (added by Schema::install()),
	 * so one visitor cannot hold every seat. Nothing else to migrate.
	 */
	public static function migrate_7(): void {
	}

	/**
	 * DB version 6: numeric price/days/rating copies for trip filters and sorting.
	 */
	public static function migrate_6(): void {
		TripIndex::rebuild();
	}

	/**
	 * DB version 5: "My bookings" page for customers.
	 */
	public static function migrate_5(): void {
		AccountPage::ensure();
	}

	/**
	 * DB version 4: payment reference/parent and booking payment method
	 * columns (added by Schema::install()). Nothing else to migrate.
	 */
	public static function migrate_4(): void {
	}

	/**
	 * DB version 3: hold selection and booking time/option columns (added by
	 * Schema::install()), and the hourly clean-up of expired holds.
	 */
	public static function migrate_3(): void {
		Cron::schedule();
	}

	/**
	 * DB version 2: trip post type capabilities and its rewrite rules.
	 */
	public static function migrate_2(): void {
		Capabilities::add_to_roles();
		update_option( 'wpitravel_flush_rewrite_rules', 1, true );
	}

	/**
	 * DB version 1: initial install (tables are created by Schema::install()).
	 */
	public static function migrate_1(): void {
		Capabilities::add_to_roles();

		if ( false === get_option( Options::OPTION ) ) {
			add_option( Options::OPTION, array(), '', true );
		}

		update_option( 'wpitravel_flush_rewrite_rules', 1, true );
	}

	/**
	 * Prevents concurrent upgrades. Stale locks expire after LOCK_TTL seconds.
	 */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $locked_at && time() - $locked_at > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, time(), false );

			return true;
		}

		return false;
	}
}
