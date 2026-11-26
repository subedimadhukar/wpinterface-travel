<?php
/**
 * Coupons.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Db;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Stores coupons and decides whether one applies to a booking.
 *
 * Codes are case-insensitive (stored upper-case). Dates are stored in UTC and
 * shown in the site timezone. Usage is counted when a booking is created and
 * given back when it is cancelled.
 */
final class Coupons {

	public const TYPES    = array( 'percent', 'fixed' );
	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * Booking statuses that do not use up a coupon.
	 */
	private const RELEASED_STATUSES = array( 'cancelled', 'failed' );

	/**
	 * One coupon by ID.
	 *
	 * @param int $id Coupon ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'coupons' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * One coupon by code (case-insensitive).
	 *
	 * @param string $code Code.
	 * @return array<string, mixed>|null
	 */
	public function find_by_code( string $code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE code = %s', Schema::table( 'coupons' ), self::normalize_code( $code ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * A page of coupons, newest first.
	 *
	 * @param int    $page     Page, from 1.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Code search.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function paginate( int $page = 1, int $per_page = 20, string $search = '' ): array {
		global $wpdb;

		$table  = Schema::table( 'coupons' );
		$like   = '%' . $wpdb->esc_like( self::normalize_code( $search ) ) . '%';
		$offset = max( 0, $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE code LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $like, $per_page, $offset ), ARRAY_A );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE code LIKE %s', $table, $like ) );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Creates or updates a coupon.
	 *
	 * @param array<string, mixed> $data Raw fields.
	 * @param int                  $id   Coupon to update; 0 to create.
	 * @return array<string, mixed>|WP_Error Saved coupon.
	 */
	public function save( array $data, int $id = 0 ) {
		global $wpdb;

		$existing = $id ? $this->find( $id ) : null;

		if ( $id && ! $existing ) {
			return new WP_Error( 'wpitravel_coupon_not_found', __( 'Coupon not found.', 'wpinterface-travel' ), array( 'status' => 404 ) );
		}

		$clean = self::sanitize( $data, $existing );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$same_code = $this->find_by_code( $clean['code'] );

		if ( $same_code && $same_code['id'] !== $id ) {
			return new WP_Error( 'wpitravel_coupon_exists', __( 'A coupon with this code already exists.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$clean['updated'] = Db::now();
		$table            = Schema::table( 'coupons' );

		if ( $existing ) {
			$wpdb->update( $table, $clean, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		} else {
			$clean['created'] = $clean['updated'];
			$wpdb->insert( $table, $clean ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$id = (int) $wpdb->insert_id;
		}

		return (array) $this->find( $id );
	}

	/**
	 * Deletes a coupon. Bookings keep the code they used.
	 *
	 * @param int $id Coupon ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'coupons' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Checks a code against a booking and returns the discount.
	 *
	 * @param string $code           Code typed by the customer.
	 * @param int    $trip_id        Trip.
	 * @param int    $subtotal_minor Subtotal in minor units.
	 * @param int    $decimals       Currency decimals.
	 * @param string $email          Customer email, for per-customer limits ('' to skip).
	 * @return array{coupon: array<string, mixed>, discount_minor: int}|WP_Error
	 */
	public function evaluate( string $code, int $trip_id, int $subtotal_minor, int $decimals, string $email = '' ) {
		$coupon = $this->find_by_code( $code );
		$fail   = static fn( string $message ): WP_Error => new WP_Error( 'wpitravel_invalid_coupon', $message, array( 'status' => 400 ) );

		if ( ! $coupon || 'active' !== $coupon['status'] ) {
			return $fail( __( 'This coupon code is not valid.', 'wpinterface-travel' ) );
		}

		$now = Db::now();

		if ( null !== $coupon['starts_at'] && $now < $coupon['starts_at'] ) {
			return $fail( __( 'This coupon is not valid yet.', 'wpinterface-travel' ) );
		}

		if ( null !== $coupon['expires_at'] && $now >= $coupon['expires_at'] ) {
			return $fail( __( 'This coupon has expired.', 'wpinterface-travel' ) );
		}

		if ( null !== $coupon['usage_limit'] && $coupon['usage_count'] >= $coupon['usage_limit'] ) {
			return $fail( __( 'This coupon has been used up.', 'wpinterface-travel' ) );
		}

		if ( ! empty( $coupon['trip_ids'] ) && ! in_array( $trip_id, $coupon['trip_ids'], true ) ) {
			return $fail( __( 'This coupon is not valid for this trip.', 'wpinterface-travel' ) );
		}

		$site_currency = (string) wpitravel()->options()->get( 'currency.code', 'USD' );

		if ( 'fixed' === $coupon['discount_type'] && '' !== $coupon['currency'] && $coupon['currency'] !== $site_currency ) {
			return $fail( __( 'This coupon is not valid in this currency.', 'wpinterface-travel' ) );
		}

		$minimum = Money::to_minor( $coupon['min_subtotal'], $decimals );

		if ( $minimum > 0 && $subtotal_minor < $minimum ) {
			return $fail(
				sprintf(
					/* translators: %s: minimum amount. */
					__( 'This coupon needs a booking of at least %s.', 'wpinterface-travel' ),
					Money::format( $coupon['min_subtotal'], (array) wpitravel()->options()->get( 'currency', array() ) )
				)
			);
		}

		if ( '' !== $email && null !== $coupon['per_customer_limit'] && $this->customer_uses( $coupon['code'], $email ) >= $coupon['per_customer_limit'] ) {
			return $fail( __( 'You have already used this coupon.', 'wpinterface-travel' ) );
		}

		if ( 'percent' === $coupon['discount_type'] ) {
			$discount = (int) round( $subtotal_minor * (float) $coupon['amount'] / 100 );
		} else {
			$discount = Money::to_minor( $coupon['amount'], $decimals );
		}

		return array(
			'coupon'         => $coupon,
			'discount_minor' => min( $subtotal_minor, max( 0, $discount ) ),
		);
	}

	/**
	 * Counts one use, unless the limit was reached meanwhile.
	 *
	 * @param int $id Coupon ID.
	 * @return bool False when the coupon is used up.
	 */
	public function use_once( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; atomic increment.
			$wpdb->prepare(
				'UPDATE %i SET usage_count = usage_count + 1 WHERE id = %d AND (usage_limit IS NULL OR usage_count < usage_limit)',
				Schema::table( 'coupons' ),
				$id
			)
		);
	}

	/**
	 * Gives back one use (booking cancelled).
	 *
	 * @param string $code Code.
	 */
	public function release_once( string $code ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; atomic decrement.
			$wpdb->prepare(
				'UPDATE %i SET usage_count = usage_count - 1 WHERE code = %s AND usage_count > 0',
				Schema::table( 'coupons' ),
				self::normalize_code( $code )
			)
		);
	}

	/**
	 * Bookings by one customer (email) with this code that still count.
	 *
	 * @param string $code  Code.
	 * @param string $email Email.
	 */
	public function customer_uses( string $code, string $email ): int {
		global $wpdb;

		$released     = self::RELEASED_STATUSES;
		$placeholders = implode( ',', array_fill( 0, count( $released ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables; one %s per status is built above.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i b INNER JOIN %i c ON c.id = b.customer_id WHERE b.coupon_code = %s AND c.email = %s AND b.status NOT IN ({$placeholders})",
				...array_merge( array( Schema::table( 'bookings' ), Schema::table( 'customers' ), self::normalize_code( $code ), strtolower( $email ) ), $released )
			)
		);
		// phpcs:enable
	}

	/**
	 * Upper-case code with only letters, digits, - and _.
	 *
	 * @param string $code Raw code.
	 */
	public static function normalize_code( string $code ): string {
		return (string) preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( trim( $code ) ) );
	}

	/**
	 * A coupon for the API: money as strings, dates in the site timezone.
	 *
	 * @param array<string, mixed> $coupon Coupon.
	 * @return array<string, mixed>
	 */
	public static function to_api( array $coupon ): array {
		foreach ( array( 'starts_at', 'expires_at' ) as $field ) {
			$coupon[ $field ] = null === $coupon[ $field ] ? '' : get_date_from_gmt( $coupon[ $field ], 'Y-m-d H:i' );
		}

		unset( $coupon['created'], $coupon['updated'] );

		return $coupon;
	}

	/**
	 * Validates and cleans coupon fields.
	 *
	 * @param array<string, mixed>      $data     Raw fields.
	 * @param array<string, mixed>|null $existing Coupon being updated.
	 * @return array<string, mixed>|WP_Error Columns to write.
	 */
	private static function sanitize( array $data, ?array $existing ) {
		$merged   = array_merge( $existing ? self::to_api( $existing ) : array(), $data );
		$fail     = static fn( string $field, string $message ): WP_Error => new WP_Error(
			'wpitravel_invalid_coupon_data',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
		$decimals = (int) wpitravel()->options()->get( 'currency.decimals', 2 );
		$code     = self::normalize_code( (string) ( $merged['code'] ?? '' ) );

		if ( strlen( $code ) < 3 || strlen( $code ) > 64 ) {
			return $fail( 'code', __( 'Use 3 to 64 letters, digits, dashes or underscores for the code.', 'wpinterface-travel' ) );
		}

		$type = (string) ( $merged['discount_type'] ?? 'percent' );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return $fail( 'discount_type', __( 'Choose a percentage or a fixed amount.', 'wpinterface-travel' ) );
		}

		$amount = Money::normalize( $merged['amount'] ?? '', 'percent' === $type ? 2 : $decimals );

		if ( '' === $amount || Money::to_minor( $amount ) <= 0 || ( 'percent' === $type && (float) $amount > 100 ) ) {
			return $fail( 'amount', __( 'Enter a discount above 0 (at most 100 for a percentage).', 'wpinterface-travel' ) );
		}

		$starts  = self::utc_datetime( $merged['starts_at'] ?? '' );
		$expires = self::utc_datetime( $merged['expires_at'] ?? '' );

		if ( false === $starts || false === $expires ) {
			return $fail( false === $starts ? 'starts_at' : 'expires_at', __( 'Use dates like 2026-12-31 or 2026-12-31 18:00.', 'wpinterface-travel' ) );
		}

		if ( null !== $starts && null !== $expires && $expires <= $starts ) {
			return $fail( 'expires_at', __( 'The end date must be after the start date.', 'wpinterface-travel' ) );
		}

		$status = (string) ( $merged['status'] ?? 'active' );

		return array(
			'code'               => $code,
			'description'        => sanitize_text_field( (string) ( $merged['description'] ?? '' ) ),
			'discount_type'      => $type,
			'amount'             => $amount,
			'currency'           => 'fixed' === $type ? (string) wpitravel()->options()->get( 'currency.code', 'USD' ) : '',
			'min_subtotal'       => '' !== Money::normalize( $merged['min_subtotal'] ?? '', $decimals ) ? Money::normalize( $merged['min_subtotal'], $decimals ) : '0',
			'usage_limit'        => self::limit( $merged['usage_limit'] ?? null ),
			'per_customer_limit' => self::limit( $merged['per_customer_limit'] ?? null ),
			'trip_ids'           => wp_json_encode( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $merged['trip_ids'] ?? array() ) ) ) ) ) ),
			'starts_at'          => $starts,
			'expires_at'         => $expires,
			'status'             => in_array( $status, self::STATUSES, true ) ? $status : 'active',
		);
	}

	/**
	 * A positive limit, or null for "no limit".
	 *
	 * @param mixed $value Raw value.
	 */
	private static function limit( $value ): ?int {
		$value = is_numeric( $value ) ? (int) $value : 0;

		return $value > 0 ? $value : null;
	}

	/**
	 * Site-time "Y-m-d" or "Y-m-d H:i" converted to UTC; null for empty; false when invalid.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null|false
	 */
	private static function utc_datetime( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		$format = strlen( $value ) === 10 ? '!Y-m-d' : '!Y-m-d H:i';
		$date   = DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );

		if ( ! $date || $date->format( 10 === strlen( $value ) ? 'Y-m-d' : 'Y-m-d H:i' ) !== $value ) {
			return false;
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Casts columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		$ids = json_decode( (string) $row['trip_ids'], true );

		return array(
			'id'                 => (int) $row['id'],
			'code'               => (string) $row['code'],
			'description'        => (string) $row['description'],
			'discount_type'      => (string) $row['discount_type'],
			'amount'             => rtrim( rtrim( (string) $row['amount'], '0' ), '.' ),
			'currency'           => (string) $row['currency'],
			'min_subtotal'       => rtrim( rtrim( (string) $row['min_subtotal'], '0' ), '.' ),
			'usage_limit'        => null === $row['usage_limit'] ? null : (int) $row['usage_limit'],
			'usage_count'        => (int) $row['usage_count'],
			'per_customer_limit' => null === $row['per_customer_limit'] ? null : (int) $row['per_customer_limit'],
			'trip_ids'           => is_array( $ids ) ? array_map( 'intval', $ids ) : array(),
			'starts_at'          => $row['starts_at'],
			'expires_at'         => $row['expires_at'],
			'status'             => (string) $row['status'],
			'created'            => (string) $row['created'],
			'updated'            => (string) $row['updated'],
		);
	}
}
