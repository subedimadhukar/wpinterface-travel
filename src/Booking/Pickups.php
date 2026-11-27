<?php
/**
 * Pickup points.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WP_Error;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * A trip's pickup points (name, time, note). When a trip has any, customers
 * choose one at checkout; the booking keeps a copy of it, so later changes
 * to the trip do not change past bookings.
 */
final class Pickups {

	/**
	 * Booking meta key of the chosen point.
	 */
	public const META = 'pickup';

	/**
	 * Most points per trip.
	 */
	private const MAX = 50;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'wpitravel_validate_booking', array( $this, 'validate' ), 10, 3 );
		add_action( 'wpitravel_booking_created', array( $this, 'save' ), 5, 3 );
	}

	/**
	 * Cleans the trip meta: a list of points, each with a stable key.
	 *
	 * @param mixed $value Raw value.
	 * @return list<array{key: string, name: string, time: string, note: string}>
	 */
	public static function sanitize( $value ): array {
		$points = array();
		$keys   = array();

		foreach ( array_slice( is_array( $value ) ? $value : array(), 0, self::MAX ) as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $point['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$time = trim( (string) ( $point['time'] ?? '' ) );
			$time = preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)\z/', $time, $parts ) ? sprintf( '%02d:%s', (int) $parts[1], $parts[2] ) : '';
			$key  = sanitize_key( (string) ( $point['key'] ?? '' ) );
			$key  = '' !== $key ? $key : substr( sanitize_key( sanitize_title( $name ) ), 0, 40 );
			$key  = '' !== $key ? $key : 'pickup';
			$base = $key;

			for ( $n = 2; isset( $keys[ $key ] ); $n++ ) {
				$key = $base . '-' . $n;
			}

			$keys[ $key ] = true;
			$points[]     = array(
				'key'  => $key,
				'name' => $name,
				'time' => $time,
				'note' => sanitize_text_field( (string) ( $point['note'] ?? '' ) ),
			);
		}

		return $points;
	}

	/**
	 * A trip's points (translations use their original trip's list for
	 * checking; see BookingWidget for the names shown).
	 *
	 * @param int $trip_id Trip.
	 * @return list<array{key: string, name: string, time: string, note: string}>
	 */
	public static function for_trip( int $trip_id ): array {
		return self::sanitize( get_post_meta( $trip_id, TripMeta::PICKUPS, true ) );
	}

	/**
	 * One point of a trip by key, or null.
	 *
	 * @param int    $trip_id Trip.
	 * @param string $key     Key.
	 * @return array{key: string, name: string, time: string, note: string}|null
	 */
	public static function find( int $trip_id, string $key ): ?array {
		foreach ( self::for_trip( Multilingual::original_trip_id( $trip_id ) ) as $point ) {
			if ( $point['key'] === $key ) {
				return $point;
			}
		}

		return null;
	}

	/**
	 * The point chosen for a booking, or null.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array{name: string, time: string, note: string}|null
	 */
	public static function of_booking( array $booking ): ?array {
		$point = $booking['meta'][ self::META ] ?? null;

		return is_array( $point ) && '' !== (string) ( $point['name'] ?? '' ) ? array(
			'name' => (string) $point['name'],
			'time' => (string) ( $point['time'] ?? '' ),
			'note' => (string) ( $point['note'] ?? '' ),
		) : null;
	}

	/**
	 * "Hotel Yak, 06:30" or "".
	 *
	 * @param array<string, string>|null $point Point.
	 */
	public static function label( ?array $point ): string {
		if ( ! $point ) {
			return '';
		}

		return '' !== $point['time'] ? sprintf( '%1$s, %2$s', $point['name'], $point['time'] ) : $point['name'];
	}

	/**
	 * A trip with pickup points needs one chosen. Staff entering a booking
	 * may leave it empty.
	 *
	 * @param true|WP_Error        $valid Result so far.
	 * @param array<string, mixed> $input Booking input.
	 * @param array<string, mixed> $quote Quote.
	 * @return true|WP_Error
	 */
	public function validate( $valid, $input, $quote ) {
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$trip_id = (int) ( $quote['trip_id'] ?? 0 );
		$key     = sanitize_key( (string) ( $input['pickup'] ?? '' ) );

		if ( ! self::for_trip( Multilingual::original_trip_id( $trip_id ) ) ) {
			return $valid;
		}

		if ( '' === $key ) {
			return current_user_can( Capabilities::MANAGE_BOOKINGS ) ? $valid : new WP_Error( 'wpitravel_pickup_required', __( 'Choose a pickup point.', 'wpinterface-travel' ), array( 'status' => 400 ) );
		}

		return self::find( $trip_id, $key ) ? $valid : new WP_Error( 'wpitravel_pickup_invalid', __( 'That pickup point is not available. Choose another.', 'wpinterface-travel' ), array( 'status' => 400 ) );
	}

	/**
	 * Keeps a copy of the chosen point on the booking.
	 *
	 * @param int                  $booking_id Booking.
	 * @param array<string, mixed> $quote      Quote.
	 * @param array<string, mixed> $input      Booking input.
	 */
	public function save( $booking_id, $quote, $input ): void {
		$key   = sanitize_key( (string) ( $input['pickup'] ?? '' ) );
		$point = '' !== $key ? self::find( (int) ( $quote['trip_id'] ?? 0 ), $key ) : null;

		if ( $point ) {
			( new BookingRepository() )->update_meta(
				(int) $booking_id,
				self::META,
				array(
					'name' => $point['name'],
					'time' => $point['time'],
					'note' => $point['note'],
				)
			);
		}
	}
}
