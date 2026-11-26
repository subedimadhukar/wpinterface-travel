<?php
/**
 * Booking rows.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Currencies;
use WPInterface\Travel\Support\Db;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes bookings with their items, travelers, customers and log.
 */
final class BookingRepository {

	public const STATUSES = array( 'pending', 'confirmed', 'completed', 'cancel_requested', 'cancelled', 'failed' );

	/**
	 * Statuses that do not occupy seats.
	 */
	public const RELEASED_STATUSES = array( 'cancelled', 'failed' );

	/**
	 * One booking by ID.
	 *
	 * @param int $id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'bookings' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * One booking by its public key.
	 *
	 * @param string $key Booking key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_key( string $key ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_key = %s', Schema::table( 'bookings' ), $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * Line items of a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<array<string, mixed>>
	 */
	public function items( int $booking_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d ORDER BY id', Schema::table( 'booking_items' ), $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array_map(
			static function ( array $row ): array {
				$row['id']       = (int) $row['id'];
				$row['quantity'] = (int) $row['quantity'];
				$row['meta']     = json_decode( (string) $row['meta'], true ) ?: array(); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Empty meta becomes [].

				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Travelers of a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<array<string, mixed>>
	 */
	public function travelers( int $booking_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d ORDER BY is_lead DESC, id', Schema::table( 'travelers' ), $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * The contact person of a booking.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array{email: string, first_name: string, last_name: string, phone: string, country: string, user_id: int}
	 */
	public function customer( array $booking ): array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'customers' ), (int) $booking['customer_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array(
			'email'      => (string) ( $row['email'] ?? '' ),
			'first_name' => (string) ( $row['first_name'] ?? '' ),
			'last_name'  => (string) ( $row['last_name'] ?? '' ),
			'phone'      => (string) ( $row['phone'] ?? '' ),
			'country'    => (string) ( $row['country'] ?? '' ),
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
		);
	}

	/**
	 * Bookings of a WordPress user, newest departure first.
	 *
	 * @param int $user_id User ID.
	 * @return list<array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY start_date DESC, id DESC LIMIT 200', Schema::table( 'bookings' ), $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	/**
	 * Attaches a guest booking to a user account.
	 *
	 * Only call this after the user proved they read the booking email (the
	 * signed link) and their account email matches the booking email.
	 * Matching emails alone is not enough: some sites let people register
	 * with any address without confirming it.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 */
	public function assign_user( int $booking_id, int $user_id ): void {
		$this->update( $booking_id, array( 'user_id' => $user_id ) );
	}

	/**
	 * Creates the customer for an email. An existing customer's details only
	 * change when their own logged-in account books (or a field is empty):
	 * anyone can type any email at checkout, and must not be able to rewrite
	 * the name and phone staff see on that customer's other bookings. Each
	 * booking keeps the details given with it on its lead traveler.
	 *
	 * @param array<string, string> $customer email, first_name, last_name, phone, country.
	 * @param int                   $user_id  Logged-in user, or 0.
	 * @return int Customer ID.
	 */
	public function upsert_customer( array $customer, int $user_id ): int {
		global $wpdb;

		$now   = Db::now();
		$table = Schema::table( 'customers' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; atomic upsert on the unique email.
			$wpdb->prepare(
				'INSERT INTO %i (user_id, email, first_name, last_name, phone, country, created, updated) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
				first_name = IF((user_id > 0 AND user_id = VALUES(user_id)) OR first_name = \'\', VALUES(first_name), first_name),
				last_name = IF((user_id > 0 AND user_id = VALUES(user_id)) OR last_name = \'\', VALUES(last_name), last_name),
				phone = IF((user_id > 0 AND user_id = VALUES(user_id)) OR phone = \'\', VALUES(phone), phone),
				country = IF((user_id > 0 AND user_id = VALUES(user_id)) OR country = \'\', IF(VALUES(country) = \'\', country, VALUES(country)), country),
				user_id = IF(user_id = 0, VALUES(user_id), user_id), updated = VALUES(updated)',
				$table,
				$user_id,
				strtolower( $customer['email'] ),
				$customer['first_name'],
				$customer['last_name'],
				$customer['phone'],
				$customer['country'],
				$now,
				$now
			)
		);

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', $table, strtolower( $customer['email'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Inserts a row into one of the booking tables.
	 *
	 * @param string               $table Table key (bookings, booking_items, travelers).
	 * @param array<string, mixed> $data  Columns.
	 * @return int Inserted ID, 0 on failure.
	 */
	public function insert( string $table, array $data ): int {
		global $wpdb;

		return $wpdb->insert( Schema::table( $table ), $data ) ? (int) $wpdb->insert_id : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Updates booking columns.
	 *
	 * @param int                  $id   Booking ID.
	 * @param array<string, mixed> $data Columns.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated'] = Db::now();

		return false !== $wpdb->update( Schema::table( 'bookings' ), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Appends to the booking's history.
	 *
	 * @param int                  $booking_id Booking ID.
	 * @param string               $event      Short code, e.g. status_changed.
	 * @param string               $message    Human-readable text.
	 * @param array<string, mixed> $context    Extra data.
	 */
	public function log( int $booking_id, string $event, string $message = '', array $context = array() ): void {
		$this->insert(
			'booking_log',
			array(
				'booking_id' => $booking_id,
				'event'      => $event,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'actor_id'   => get_current_user_id(),
				'created'    => Db::now(),
			)
		);
	}

	/**
	 * History of a booking, oldest first.
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<array<string, mixed>>
	 */
	public function history( int $booking_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d ORDER BY id', Schema::table( 'booking_log' ), $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * One value from a booking's meta.
	 *
	 * @param int    $booking_id    Booking.
	 * @param string $key           Key (prefix your own, e.g. "pro_deposit").
	 * @param mixed  $default_value Returned when missing.
	 * @return mixed
	 */
	public function get_meta( int $booking_id, string $key, $default_value = null ) {
		$booking = $this->find( $booking_id );

		return $booking && array_key_exists( $key, $booking['meta'] ) ? $booking['meta'][ $key ] : $default_value;
	}

	/**
	 * Sets (or with null, removes) one value in a booking's meta.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $key        Key.
	 * @param mixed  $value      JSON-serialisable value, or null to remove.
	 */
	public function update_meta( int $booking_id, string $key, $value ): bool {
		$booking = $this->find( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		$meta = $booking['meta'];

		if ( null === $value ) {
			unset( $meta[ $key ] );
		} else {
			$meta[ $key ] = $value;
		}

		return $this->update( $booking_id, array( 'meta' => $meta ? wp_json_encode( $meta ) : null ) );
	}

	/**
	 * Display number, e.g. TRV-1042.
	 *
	 * @param int $id Booking ID.
	 */
	public static function number( int $id ): string {
		return (string) wpitravel()->options()->get( 'booking.number_prefix', 'TRV-' ) . $id;
	}

	/**
	 * Casts columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		foreach ( array( 'id', 'trip_id', 'departure_id', 'customer_id', 'user_id', 'pax' ) as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}

		// Stored with 4 decimals; shown with the booking currency's decimals.
		$decimals = Currencies::decimals( (string) $row['currency'] );

		foreach ( array( 'subtotal', 'discount_total', 'tax_total', 'total', 'amount_paid' ) as $field ) {
			$row[ $field ] = Money::normalize( (string) $row[ $field ], $decimals );
		}

		$row['balance'] = Money::from_minor( max( 0, Money::to_minor( $row['total'], $decimals ) - Money::to_minor( $row['amount_paid'], $decimals ) ), $decimals );

		$row['number'] = self::number( $row['id'] );
		$row['meta']   = is_string( $row['meta'] ?? null ) ? (array) json_decode( $row['meta'], true ) : array();

		return $row;
	}
}
