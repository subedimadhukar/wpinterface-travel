<?php
/**
 * Seat holds.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WP_Error;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Departures\DepartureRepository;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Reserves seats while a customer is in checkout.
 *
 * Creating a hold locks the departure row (SELECT … FOR UPDATE), so two
 * checkouts can never take the last seat at the same time. A hold carries
 * the customer's selection; the booking is priced again from it.
 */
final class Holds {

	public const MAX_HELD_SEATS = 20;

	/**
	 * Departure rows.
	 *
	 * @var DepartureRepository
	 */
	private DepartureRepository $departures;

	/**
	 * Constructor.
	 *
	 * @param DepartureRepository|null $departures Departure rows.
	 */
	public function __construct( ?DepartureRepository $departures = null ) {
		$this->departures = $departures ?? new DepartureRepository();
	}

	/**
	 * Holds the seats of a quote.
	 *
	 * One visitor (see RateLimiter::client_key()) may hold at most
	 * MAX_HELD_SEATS seats at a time across all trips, so nobody can make
	 * departures look sold out by holding seats they never book. Pass an
	 * empty client for staff bookings.
	 *
	 * @param array<string, mixed> $quote  Quote from QuoteCalculator.
	 * @param string               $client Who holds the seats ('' = not limited).
	 * @return array{hold_key: string, expires_at: string, departure_id: int}|WP_Error
	 */
	public function create( array $quote, string $client = '' ) {
		$trip_id = (int) $quote['trip_id'];
		$client  = '' !== $client ? md5( $client ) : '';

		if ( '' !== $client ) {
			global $wpdb;

			/**
			 * Filters how many seats one visitor may hold at once (0 = no limit).
			 *
			 * @param int $seats Seats.
			 */
			$max  = (int) apply_filters( 'wpitravel_max_held_seats', self::MAX_HELD_SEATS );
			$held = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(seats), 0) FROM %i WHERE client = %s AND expires_at > %s', Schema::table( 'holds' ), $client, Db::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

			if ( $max > 0 && $held + (int) $quote['pax_total'] > $max ) {
				return new WP_Error( 'wpitravel_too_many_held', __( 'You are holding too many seats. Finish or cancel your other bookings first, or contact us for a group booking.', 'wpinterface-travel' ), array( 'status' => 429 ) );
			}
		}
		$row = $this->departures->ensure( $trip_id, $quote['date'], $quote['time'], (string) $quote['source'] );

		if ( ! $row ) {
			return new WP_Error( 'wpitravel_hold_failed', __( 'Seats could not be reserved. Try again.', 'wpinterface-travel' ), array( 'status' => 500 ) );
		}

		$minutes = max( 5, (int) wpitravel()->options()->get( 'booking.hold_minutes', 15 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $minutes * MINUTE_IN_SECONDS );
		$key     = Db::random_key();

		$result = Db::transaction(
			function () use ( $row, $quote, $trip_id, $expires, $key, $client ) {
				global $wpdb;

				$check = $this->lock_and_check( $row['id'], $trip_id, (int) $quote['pax_total'] );

				if ( is_wp_error( $check ) ) {
					return $check;
				}

				$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
					Schema::table( 'holds' ),
					array(
						'hold_key'     => $key,
						'departure_id' => $row['id'],
						'trip_id'      => $trip_id,
						'seats'        => (int) $quote['pax_total'],
						'selection'    => wp_json_encode(
							array(
								'price_option' => $quote['price_option'],
								'pax'          => $quote['pax'],
								'extras'       => $quote['extras'] ?? array(),
							)
						),
						'expires_at'   => $expires,
						'client'       => $client,
						'created'      => Db::now(),
					)
				);

				return $inserted ? true : new WP_Error( 'wpitravel_hold_failed', __( 'Seats could not be reserved. Try again.', 'wpinterface-travel' ), array( 'status' => 500 ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'hold_key'     => $key,
			'expires_at'   => $expires,
			'departure_id' => $row['id'],
		);
	}

	/**
	 * Locks a departure row and checks there are enough free seats.
	 *
	 * Must run inside Db::transaction().
	 *
	 * @param int $departure_id   Departure.
	 * @param int $trip_id        Trip.
	 * @param int $seats          Seats wanted.
	 * @param int $ignore_hold_id A hold whose seats belong to the caller (0 for none).
	 * @return array<string, mixed>|WP_Error The locked row.
	 */
	public function lock_and_check( int $departure_id, int $trip_id, int $seats, int $ignore_hold_id = 0 ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; row lock.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', Schema::table( 'departures' ), $departure_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) || 'open' !== $row['status'] ) {
			return new WP_Error( 'wpitravel_date_unavailable', __( 'This date is not available. Choose another date.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$schedule = Schedule::sanitize( get_post_meta( $trip_id, TripMeta::SCHEDULE, true ) );
		$capacity = null !== $row['capacity'] ? (int) $row['capacity'] : ( $schedule['capacity'] > 0 ? (int) $schedule['capacity'] : null );

		if ( null === $capacity ) {
			return $row;
		}

		$held = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare(
				'SELECT COALESCE(SUM(seats), 0) FROM %i WHERE departure_id = %d AND expires_at > %s AND id <> %d',
				Schema::table( 'holds' ),
				$departure_id,
				Db::now(),
				$ignore_hold_id
			)
		);

		$left = $capacity - (int) $row['seats_booked'] - $held;

		if ( $seats > $left ) {
			return new WP_Error(
				'wpitravel_not_enough_seats',
				$left > 0
					/* translators: %d: seats left. */
					? sprintf( _n( 'Only %d seat is left on this date.', 'Only %d seats are left on this date.', $left, 'wpinterface-travel' ), $left )
					: __( 'This date is sold out.', 'wpinterface-travel' ),
				array(
					'status'     => 409,
					'seats_left' => max( 0, $left ),
				)
			);
		}

		return $row;
	}

	/**
	 * An unexpired hold by key.
	 *
	 * @param string $key Hold key.
	 * @return array<string, mixed>|null
	 */
	public function find( string $key ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{32}\z/', $key ) ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE hold_key = %s AND expires_at > %s', Schema::table( 'holds' ), $key, Db::now() ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$selection = json_decode( (string) $row['selection'], true );

		return array(
			'id'           => (int) $row['id'],
			'hold_key'     => (string) $row['hold_key'],
			'departure_id' => (int) $row['departure_id'],
			'trip_id'      => (int) $row['trip_id'],
			'seats'        => (int) $row['seats'],
			'selection'    => is_array( $selection ) ? $selection : array(),
			'expires_at'   => (string) $row['expires_at'],
		);
	}

	/**
	 * Releases a hold.
	 *
	 * @param string $key Hold key.
	 */
	public function release( string $key ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'holds' ), array( 'hold_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Deletes expired holds.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_expired(): int {
		global $wpdb;

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', Schema::table( 'holds' ), Db::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}
}
