<?php
/**
 * Trip departure schedule.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Departures;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * When a trip departs.
 *
 * Stored in the `_wpitravel_departure_rules` meta:
 *
 *     fixed:        [ { date: "2026-12-05", time: "07:30" | "" } ]
 *     recurring:    [ { start, end, weekdays: [1..7], months: [1..12], times: ["08:00"] } ]
 *     excluded:     [ "2026-12-25" ]
 *     capacity:     seats per departure, 0 = unlimited
 *     cutoff_hours: bookings close this many hours before departure
 *     advance_days: how far ahead customers can book, 0 = no limit
 *
 * Weekdays are ISO (1 = Monday … 7 = Sunday). Empty weekdays/months mean
 * "every". A recurring rule with no end runs until the booking window ends.
 * All dates and times are in the site timezone.
 */
final class Schedule {

	private const MAX_FIXED     = 500;
	private const MAX_RULES     = 20;
	private const MAX_EXCLUDED  = 500;
	private const MAX_TIMES     = 12;
	public const DEFAULT_WINDOW = 365;

	/**
	 * Default (empty) schedule.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_value(): array {
		return array(
			'fixed'        => array(),
			'recurring'    => array(),
			'excluded'     => array(),
			'capacity'     => 0,
			'cutoff_hours' => 0,
			'advance_days' => self::DEFAULT_WINDOW,
		);
	}

	/**
	 * Cleans a schedule. Used as the meta sanitize callback.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$fixed = array();
		$seen  = array();

		foreach ( array_slice( (array) ( $value['fixed'] ?? array() ), 0, self::MAX_FIXED ) as $slot ) {
			$date = self::date( is_array( $slot ) ? ( $slot['date'] ?? '' ) : '' );
			$time = self::time( is_array( $slot ) ? ( $slot['time'] ?? '' ) : '' );

			if ( '' === $date || isset( $seen[ $date . '|' . $time ] ) ) {
				continue;
			}

			$seen[ $date . '|' . $time ] = true;
			$fixed[]                     = array(
				'date' => $date,
				'time' => $time,
			);
		}

		usort(
			$fixed,
			static fn( array $a, array $b ): int => strcmp( $a['date'] . $a['time'], $b['date'] . $b['time'] )
		);

		$recurring = array();

		foreach ( array_slice( (array) ( $value['recurring'] ?? array() ), 0, self::MAX_RULES ) as $rule ) {
			if ( is_array( $rule ) ) {
				$recurring[] = self::sanitize_rule( $rule );
			}
		}

		$excluded = array();

		foreach ( array_slice( (array) ( $value['excluded'] ?? array() ), 0, self::MAX_EXCLUDED ) as $date ) {
			$date = self::date( $date );

			if ( '' !== $date ) {
				$excluded[ $date ] = $date;
			}
		}

		sort( $excluded );

		return array(
			'fixed'        => $fixed,
			'recurring'    => $recurring,
			'excluded'     => $excluded,
			'capacity'     => absint( $value['capacity'] ?? 0 ),
			'cutoff_hours' => min( 8760, absint( $value['cutoff_hours'] ?? 0 ) ),
			'advance_days' => min( 3650, absint( $value['advance_days'] ?? self::DEFAULT_WINDOW ) ),
		);
	}

	/**
	 * Every scheduled date/time between two dates (inclusive), minus excluded dates.
	 *
	 * @param array<string, mixed> $schedule Sanitized schedule.
	 * @param string               $from     First date, Y-m-d.
	 * @param string               $to       Last date, Y-m-d.
	 * @return array<string, array{date: string, time: string, source: string}> Keyed by "date|time", sorted.
	 */
	public static function slots( array $schedule, string $from, string $to ): array {
		$slots    = array();
		$excluded = array_flip( (array) ( $schedule['excluded'] ?? array() ) );

		foreach ( (array) ( $schedule['fixed'] ?? array() ) as $slot ) {
			if ( $slot['date'] >= $from && $slot['date'] <= $to ) {
				$slots[ $slot['date'] . '|' . $slot['time'] ] = array(
					'date'   => $slot['date'],
					'time'   => $slot['time'],
					'source' => 'fixed',
				);
			}
		}

		$utc = new DateTimeZone( 'UTC' );

		foreach ( (array) ( $schedule['recurring'] ?? array() ) as $rule ) {
			$start = max( $from, '' !== $rule['start'] ? $rule['start'] : $from );
			$end   = min( $to, '' !== $rule['end'] ? $rule['end'] : $to );

			if ( $start > $end ) {
				continue;
			}

			$times = ! empty( $rule['times'] ) ? $rule['times'] : array( '' );
			$day   = new DateTimeImmutable( $start, $utc );
			$last  = new DateTimeImmutable( $end, $utc );
			$step  = new DateInterval( 'P1D' );

			while ( $day <= $last ) {
				$weekday_ok = empty( $rule['weekdays'] ) || in_array( (int) $day->format( 'N' ), $rule['weekdays'], true );
				$month_ok   = empty( $rule['months'] ) || in_array( (int) $day->format( 'n' ), $rule['months'], true );

				if ( $weekday_ok && $month_ok ) {
					$date = $day->format( 'Y-m-d' );

					foreach ( $times as $time ) {
						$key = $date . '|' . $time;

						if ( ! isset( $slots[ $key ] ) ) {
							$slots[ $key ] = array(
								'date'   => $date,
								'time'   => $time,
								'source' => 'recurring',
							);
						}
					}
				}

				$day = $day->add( $step );
			}
		}

		$slots = array_filter( $slots, static fn( array $slot ): bool => ! isset( $excluded[ $slot['date'] ] ) );
		ksort( $slots );

		return $slots;
	}

	/**
	 * Cleans one recurring rule.
	 *
	 * @param array<string, mixed> $rule Raw rule.
	 * @return array{start: string, end: string, weekdays: list<int>, months: list<int>, times: list<string>}
	 */
	private static function sanitize_rule( array $rule ): array {
		$start = self::date( $rule['start'] ?? '' );
		$end   = self::date( $rule['end'] ?? '' );

		if ( '' !== $start && '' !== $end && $end < $start ) {
			list( $start, $end ) = array( $end, $start );
		}

		$weekdays = self::int_set( (array) ( $rule['weekdays'] ?? array() ), 1, 7 );
		$months   = self::int_set( (array) ( $rule['months'] ?? array() ), 1, 12 );
		$times    = array();

		foreach ( array_slice( (array) ( $rule['times'] ?? array() ), 0, self::MAX_TIMES ) as $time ) {
			$time = self::time( $time );

			if ( '' !== $time ) {
				$times[ $time ] = $time;
			}
		}

		sort( $times );

		return array(
			'start'    => $start,
			'end'      => $end,
			'weekdays' => $weekdays,
			'months'   => $months,
			'times'    => $times,
		);
	}

	/**
	 * Unique integers within a range, sorted.
	 *
	 * @param array<mixed> $values Raw values.
	 * @param int          $min    Minimum.
	 * @param int          $max    Maximum.
	 * @return list<int>
	 */
	private static function int_set( array $values, int $min, int $max ): array {
		$set = array();

		foreach ( $values as $value ) {
			if ( is_numeric( $value ) && (int) $value >= $min && (int) $value <= $max ) {
				$set[ (int) $value ] = (int) $value;
			}
		}

		ksort( $set );

		return array_values( $set );
	}

	/**
	 * A valid Y-m-d date, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function date( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * A valid HH:MM time, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function time( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return '';
		}

		return $value;
	}
}
