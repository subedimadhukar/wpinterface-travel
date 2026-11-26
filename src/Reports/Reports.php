<?php
/**
 * Sales reports.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Reports;

use DateTimeImmutable;
use DateTimeZone;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingSearch;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Support\Currencies;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Figures for the Travel dashboard, in the site's timezone:
 *
 * - Revenue: money received minus refunds, by the day it was received.
 * - Bookings and booking value: bookings made in the period, not counting
 *   cancelled or failed ones.
 *
 * Amounts in another currency than the site's (after a currency change)
 * are left out of revenue totals; `other_currencies` reports them.
 */
final class Reports {

	public const PERIODS = array( '7d', '30d', '90d', '12m' );

	/**
	 * Date range of a period, ending today (site time).
	 *
	 * @param string $period Period key.
	 * @return array{0: string, 1: string} From and to (Y-m-d).
	 */
	public static function range( string $period ): array {
		$today = new DateTimeImmutable( 'now', wp_timezone() );
		$days  = array(
			'7d'  => 6,
			'30d' => 29,
			'90d' => 89,
		);

		$from = '12m' === $period
			? $today->modify( 'first day of this month' )->modify( '-11 months' )
			: $today->modify( '-' . ( $days[ $period ] ?? 29 ) . ' days' );

		return array( $from->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
	}

	/**
	 * Everything the dashboard shows for a date range.
	 *
	 * @param string $from From (Y-m-d, site time).
	 * @param string $to   To (Y-m-d, site time, inclusive).
	 * @return array<string, mixed>
	 */
	public static function summary( string $from, string $to ): array {
		$currency = (string) wpitravel()->options()->get( 'currency.code', 'USD' );
		$decimals = Currencies::decimals( $currency );
		$monthly  = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS > 92;
		$series   = self::empty_series( $from, $to, $monthly );
		$revenue  = 0;
		$others   = array();

		foreach ( self::payments( $from, $to ) as $row ) {
			$minor = Money::to_minor( (string) $row['amount'], Currencies::decimals( (string) $row['currency'] ) ) * ( 'refund' === $row['payment_type'] ? -1 : 1 );

			if ( $row['currency'] !== $currency ) {
				$others[ $row['currency'] ] = true;
				continue;
			}

			$bucket = self::bucket( self::site_date( (string) $row['created'] ), $monthly );

			if ( isset( $series[ $bucket ] ) ) {
				$series[ $bucket ]['revenue'] += $minor;
			}

			$revenue += $minor;
		}

		$bookings = 0;
		$pax      = 0;
		$value    = 0;
		$trips    = array();

		foreach ( self::bookings( $from, $to ) as $row ) {
			$bucket = self::bucket( self::site_date( (string) $row['created'] ), $monthly );
			$minor  = $row['currency'] === $currency ? Money::to_minor( (string) $row['total'], $decimals ) : 0;
			$trip   = (int) $row['trip_id'];

			if ( isset( $series[ $bucket ] ) ) {
				++$series[ $bucket ]['bookings'];
			}

			++$bookings;
			$pax   += (int) $row['pax'];
			$value += $minor;

			$trips[ $trip ] = $trips[ $trip ] ?? array(
				'bookings' => 0,
				'pax'      => 0,
				'value'    => 0,
			);
			++$trips[ $trip ]['bookings'];
			$trips[ $trip ]['pax']   += (int) $row['pax'];
			$trips[ $trip ]['value'] += $minor;
		}

		uasort( $trips, static fn( array $a, array $b ): int => array( $b['value'], $b['bookings'] ) <=> array( $a['value'], $a['bookings'] ) );

		$top = array();

		foreach ( array_slice( $trips, 0, 5, true ) as $trip_id => $row ) {
			$top[] = array(
				'id'       => $trip_id,
				'title'    => html_entity_decode( get_the_title( $trip_id ), ENT_QUOTES, 'UTF-8' ),
				'bookings' => $row['bookings'],
				'pax'      => $row['pax'],
				'value'    => Money::from_minor( $row['value'], $decimals ),
			);
		}

		return array(
			'from'             => $from,
			'to'               => $to,
			'interval'         => $monthly ? 'month' : 'day',
			'currency'         => $currency,
			'revenue'          => Money::from_minor( $revenue, $decimals ),
			'bookings'         => $bookings,
			'pax'              => $pax,
			'booking_value'    => Money::from_minor( $value, $decimals ),
			'average_value'    => Money::from_minor( $bookings > 0 ? intdiv( $value, $bookings ) : 0, $decimals ),
			'series'           => array_values(
				array_map(
					static fn( array $point ): array => array(
						'date'     => $point['date'],
						'revenue'  => Money::from_minor( $point['revenue'], $decimals ),
						'bookings' => $point['bookings'],
					),
					$series
				)
			),
			'top_trips'        => $top,
			'other_currencies' => array_keys( $others ),
		);
	}

	/**
	 * Departures in the next days that have travelers.
	 *
	 * @param int $days Days ahead.
	 * @return list<array<string, mixed>>
	 */
	public static function upcoming( int $days = 14 ): array {
		global $wpdb;

		$today = current_time( 'Y-m-d' );
		$until = gmdate( 'Y-m-d', (int) strtotime( $today . ' +' . $days . ' days' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE start_date BETWEEN %s AND %s AND seats_booked > 0 ORDER BY start_date, start_time LIMIT 20', Schema::table( 'departures' ), $today, $until ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array_map(
			static fn( array $row ): array => array(
				'trip'     => array(
					'id'    => (int) $row['trip_id'],
					'title' => html_entity_decode( get_the_title( (int) $row['trip_id'] ), ENT_QUOTES, 'UTF-8' ),
				),
				'date'     => (string) $row['start_date'],
				'time'     => (string) $row['start_time'],
				'booked'   => (int) $row['seats_booked'],
				'capacity' => null === $row['capacity'] ? null : (int) $row['capacity'],
				'status'   => (string) $row['status'],
			),
			(array) $rows
		);
	}

	/**
	 * Bookings that need staff: awaiting confirmation, cancellation
	 * requests, and unpaid balances on trips leaving within a week.
	 *
	 * @return array<string, int>
	 */
	public static function attention(): array {
		global $wpdb;

		$counts = BookingSearch::status_counts();
		$today  = current_time( 'Y-m-d' );
		$week   = gmdate( 'Y-m-d', (int) strtotime( $today . ' +7 days' ) );
		$unpaid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('pending','confirmed') AND payment_status IN ('unpaid','partial') AND start_date BETWEEN %s AND %s", Schema::table( 'bookings' ), $today, $week ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return array(
			'pending'          => $counts['pending'],
			'cancel_requested' => $counts['cancel_requested'],
			'unpaid_soon'      => $unpaid,
		);
	}

	/**
	 * Completed payments and refunds received in the range.
	 *
	 * @param string $from From (site date).
	 * @param string $to   To (site date).
	 * @return list<array<string, mixed>>
	 */
	private static function payments( string $from, string $to ): array {
		global $wpdb;

		list( $start, $end ) = self::utc_bounds( $from, $to );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT amount, currency, payment_type, created FROM %i WHERE status = 'completed' AND created >= %s AND created < %s", Schema::table( 'payments' ), $start, $end ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Bookings made in the range, without cancelled and failed ones.
	 *
	 * @param string $from From (site date).
	 * @param string $to   To (site date).
	 * @return list<array<string, mixed>>
	 */
	private static function bookings( string $from, string $to ): array {
		global $wpdb;

		list( $start, $end ) = self::utc_bounds( $from, $to );
		$released            = BookingRepository::RELEASED_STATUSES;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT trip_id, pax, total, currency, created FROM %i WHERE created >= %s AND created < %s AND status NOT IN (%s, %s)', Schema::table( 'bookings' ), $start, $end, $released[0], $released[1] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * UTC datetimes for the start of $from and the end of $to in site time.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array{0: string, 1: string}
	 */
	private static function utc_bounds( string $from, string $to ): array {
		$utc   = new DateTimeZone( 'UTC' );
		$start = new DateTimeImmutable( $from . ' 00:00:00', wp_timezone() );
		$end   = ( new DateTimeImmutable( $to . ' 00:00:00', wp_timezone() ) )->modify( '+1 day' );

		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Site date of a stored UTC datetime.
	 *
	 * @param string $utc Y-m-d H:i:s in UTC.
	 */
	private static function site_date( string $utc ): string {
		return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/**
	 * Series key of a date.
	 *
	 * @param string $date    Y-m-d.
	 * @param bool   $monthly Monthly buckets.
	 */
	private static function bucket( string $date, bool $monthly ): string {
		return $monthly ? substr( $date, 0, 7 ) : $date;
	}

	/**
	 * Zero-filled series, so days without sales still show.
	 *
	 * @param string $from    From.
	 * @param string $to      To.
	 * @param bool   $monthly Monthly buckets.
	 * @return array<string, array{date: string, revenue: int, bookings: int}>
	 */
	private static function empty_series( string $from, string $to, bool $monthly ): array {
		$series = array();
		$date   = new DateTimeImmutable( $monthly ? substr( $from, 0, 7 ) . '-01' : $from );
		$end    = new DateTimeImmutable( $to );

		$guard = 0;

		while ( $date <= $end && $guard++ < 400 ) {
			$key            = self::bucket( $date->format( 'Y-m-d' ), $monthly );
			$series[ $key ] = array(
				'date'     => $key,
				'revenue'  => 0,
				'bookings' => 0,
			);
			$date           = $date->modify( $monthly ? '+1 month' : '+1 day' );
		}

		return $series;
	}
}
