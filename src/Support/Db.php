<?php
/**
 * Database transactions.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Support;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a callback inside a transaction, so seat counts, holds, coupon usage
 * and bookings change together or not at all.
 *
 * The test suite already wraps each test in a transaction, and MySQL commits
 * it on a nested START TRANSACTION, so tests switch to savepoints with the
 * `wpitravel_db_use_savepoint` filter.
 */
final class Db {

	/**
	 * Runs $callback in a transaction. Rolls back when it returns a WP_Error
	 * or false, or throws; commits otherwise.
	 *
	 * @template T
	 * @param callable(): T $callback Work to do.
	 * @return T
	 * @throws Throwable Re-thrown after rollback.
	 */
	public static function transaction( callable $callback ) {
		$savepoint = (bool) apply_filters( 'wpitravel_db_use_savepoint', false );

		self::begin( $savepoint );

		try {
			$result = $callback();
		} catch ( Throwable $e ) {
			self::rollback( $savepoint );
			throw $e;
		}

		if ( is_wp_error( $result ) || false === $result ) {
			self::rollback( $savepoint );
		} else {
			self::commit( $savepoint );
		}

		return $result;
	}

	/**
	 * Current UTC time in MySQL format.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Random 32-character hex key for public references (bookings, holds).
	 */
	public static function random_key(): string {
		do {
			$key = bin2hex( random_bytes( 16 ) );
		} while ( ctype_digit( $key ) ); // Never all digits, so it can't be mistaken for a numeric ID.

		return $key;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Transaction control statements.

	/**
	 * Starts a transaction or savepoint.
	 *
	 * @param bool $savepoint Use a savepoint.
	 */
	private static function begin( bool $savepoint ): void {
		global $wpdb;

		if ( $savepoint ) {
			$wpdb->query( 'SAVEPOINT wpitravel' );
		} else {
			$wpdb->query( 'START TRANSACTION' );
		}
	}

	/**
	 * Rolls back.
	 *
	 * @param bool $savepoint Use a savepoint.
	 */
	private static function rollback( bool $savepoint ): void {
		global $wpdb;

		if ( $savepoint ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT wpitravel' );
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * Commits.
	 *
	 * @param bool $savepoint Use a savepoint.
	 */
	private static function commit( bool $savepoint ): void {
		global $wpdb;

		if ( $savepoint ) {
			$wpdb->query( 'RELEASE SAVEPOINT wpitravel' );
		} else {
			$wpdb->query( 'COMMIT' );
		}
	}

	// phpcs:enable
}
