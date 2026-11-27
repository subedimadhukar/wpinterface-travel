<?php
/**
 * Availability engine.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Departures;

use DateTimeImmutable;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Pricing\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Combines a trip's schedule, its departure rows, active holds, the cut-off
 * and the booking window into a list of dates with seats and status.
 *
 * Statuses:
 * - open          bookable
 * - sold_out      no seats left (holds count as taken)
 * - closed        closed by hand
 * - cancelled     cancelled by hand
 * - past_cutoff   starts before now + cut-off hours
 * - not_yet_open  beyond the booking window
 * - unscheduled   has a row (e.g. bookings) but is no longer in the schedule
 */
final class Availability {

	/**
	 * Statuses customers see. Others are only shown to staff.
	 */
	public const PUBLIC_STATUSES = array( 'open', 'sold_out' );

	/**
	 * Departure rows.
	 *
	 * @var DepartureRepository
	 */
	private DepartureRepository $departures;

	/**
	 * Current time in the site timezone.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Constructor.
	 *
	 * @param DepartureRepository|null $departures Departure rows.
	 * @param DateTimeImmutable|null   $now        Current time, for tests.
	 */
	public function __construct( ?DepartureRepository $departures = null, ?DateTimeImmutable $now = null ) {
		$this->departures = $departures ?? new DepartureRepository();
		$this->now        = $now ? $now->setTimezone( wp_timezone() ) : new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Today's date in the site timezone.
	 */
	public function today(): string {
		return $this->now->format( 'Y-m-d' );
	}

	/**
	 * Departures of a trip between two dates.
	 *
	 * @param int    $trip_id     Trip ID.
	 * @param string $from        First date, Y-m-d.
	 * @param string $to          Last date, Y-m-d.
	 * @param bool   $include_all True for staff: include every status.
	 * @param string $option_key  Price option for the from-price; '' for the lowest.
	 * @return list<array<string, mixed>>
	 */
	public function for_trip( int $trip_id, string $from, string $to, bool $include_all = false, string $option_key = '' ): array {
		$schedule = Schedule::sanitize( get_post_meta( $trip_id, TripMeta::SCHEDULE, true ) );
		$decimals = (int) wpitravel()->options()->get( 'currency.decimals', 2 );
		$pricing  = Pricing::sanitize( get_post_meta( $trip_id, TripMeta::PRICING, true ), $decimals );
		$price    = TripMeta::on_request( $trip_id ) ? null : Pricing::from_price( $pricing, $option_key, $decimals );
		$days     = (int) ( TripMeta::sanitize_duration( get_post_meta( $trip_id, TripMeta::DURATION, true ) )['days'] );

		$slots = Schedule::slots( $schedule, $from, $to );
		$rows  = $this->departures->in_range( $trip_id, $from, $to );

		foreach ( $rows as $key => $row ) {
			if ( isset( $slots[ $key ] ) ) {
				continue;
			}

			// Dates added by hand are bookable; others are only kept for staff (they may have bookings).
			$slots[ $key ] = array(
				'date'        => $row['start_date'],
				'time'        => $row['start_time'],
				'source'      => $row['source'],
				'unscheduled' => 'manual' !== $row['source'],
			);
		}

		ksort( $slots );

		$held       = $this->departures->held_seats( array_column( $rows, 'id' ) );
		$cutoff     = $this->now->modify( '+' . (int) $schedule['cutoff_hours'] . ' hours' );
		$window_end = $schedule['advance_days'] > 0
			? $this->now->modify( '+' . (int) $schedule['advance_days'] . ' days' )->format( 'Y-m-d' )
			: '';
		$result     = array();

		foreach ( $slots as $key => $slot ) {
			$row      = $rows[ $key ] ?? null;
			$slot_cap = (int) ( $slot['capacity'] ?? 0 ) > 0 ? (int) $slot['capacity'] : ( $schedule['capacity'] > 0 ? (int) $schedule['capacity'] : null );
			$capacity = ( $row && null !== $row['capacity'] ) ? $row['capacity'] : $slot_cap;
			$booked   = $row ? $row['seats_booked'] : 0;
			$on_hold  = $row ? ( $held[ $row['id'] ] ?? 0 ) : 0;
			$left     = null === $capacity ? null : max( 0, $capacity - $booked - $on_hold );

			// A private departure is one group's: taken once booked or held.
			if ( $schedule['private'] && ( $booked > 0 || $on_hold > 0 ) ) {
				$left = 0;
			}
			$status = $this->status( $slot, $row, $left, $cutoff, $window_end );

			if ( ! $include_all && ! in_array( $status, self::PUBLIC_STATUSES, true ) ) {
				continue;
			}

			$item = array(
				'date'          => $slot['date'],
				'end_date'      => $days > 1 ? gmdate( 'Y-m-d', (int) strtotime( $slot['date'] . ' +' . ( $days - 1 ) . ' days' ) ) : $slot['date'],
				'time'          => $slot['time'],
				'departure_id'  => $row ? $row['id'] : 0,
				'source'        => $row ? $row['source'] : $slot['source'],
				'capacity'      => $capacity,
				'seats_booked'  => $booked,
				'seats_held'    => $on_hold,
				'seats_left'    => $left,
				'status'        => $status,
				'private'       => $schedule['private'],
				'bookable'      => 'open' === $status && null !== $price,
				'from_price'    => $price ? $price['price'] : null,
				'regular_price' => $price ? $price['regular'] : null,
				'sale'          => $price ? $price['sale'] : false,
			);

			/**
			 * Filters one departure, e.g. for seasonal pricing (Pro).
			 *
			 * @param array $item    Departure.
			 * @param int   $trip_id Trip ID.
			 */
			$result[] = (array) apply_filters( 'wpitravel_availability_slot', $item, $trip_id );
		}

		return $result;
	}

	/**
	 * Status of one slot.
	 *
	 * @param array<string, mixed>      $slot       Slot from the schedule.
	 * @param array<string, mixed>|null $row        Departure row.
	 * @param int|null                  $left       Seats left; null = unlimited.
	 * @param DateTimeImmutable         $cutoff     Earliest bookable start.
	 * @param string                    $window_end Last bookable date, '' = no limit.
	 */
	private function status( array $slot, ?array $row, ?int $left, DateTimeImmutable $cutoff, string $window_end ): string {
		if ( $row && 'open' !== $row['status'] ) {
			return $row['status'];
		}

		if ( ! empty( $slot['unscheduled'] ) ) {
			return 'unscheduled';
		}

		$start = DateTimeImmutable::createFromFormat(
			'Y-m-d H:i',
			$slot['date'] . ' ' . ( '' !== $slot['time'] ? $slot['time'] : '00:00' ),
			wp_timezone()
		);

		if ( ! $start || $start < $cutoff ) {
			return 'past_cutoff';
		}

		if ( '' !== $window_end && $slot['date'] > $window_end ) {
			return 'not_yet_open';
		}

		if ( 0 === $left ) {
			return 'sold_out';
		}

		return 'open';
	}
}
