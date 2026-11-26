<?php
/**
 * Departure rows.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Departures;

use WPInterface\Travel\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `departures` table.
 *
 * A row exists only for a date that needs its own state: seats booked or
 * held, a status or capacity override, or a date added by hand
 * (source "manual"). Every other date comes from the trip's schedule.
 */
final class DepartureRepository {

	public const STATUSES = array( 'open', 'closed', 'cancelled' );

	/**
	 * One row by ID.
	 *
	 * @param int $id Departure ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'departures' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * One row by trip, date and time.
	 *
	 * @param int    $trip_id Trip ID.
	 * @param string $date    Y-m-d.
	 * @param string $time    HH:MM or ''.
	 * @return array<string, mixed>|null
	 */
	public function find_slot( int $trip_id, string $date, string $time ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE trip_id = %d AND start_date = %s AND start_time = %s',
				Schema::table( 'departures' ),
				$trip_id,
				$date,
				$time
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * Rows of a trip between two dates, keyed by "date|time".
	 *
	 * @param int    $trip_id Trip ID.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d.
	 * @return array<string, array<string, mixed>>
	 */
	public function in_range( int $trip_id, string $from, string $to ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE trip_id = %d AND start_date BETWEEN %s AND %s ORDER BY start_date, start_time',
				Schema::table( 'departures' ),
				$trip_id,
				$from,
				$to
			),
			ARRAY_A
		);

		$keyed = array();

		foreach ( (array) $rows as $row ) {
			$row = self::cast( $row );
			$keyed[ $row['start_date'] . '|' . $row['start_time'] ] = $row;
		}

		return $keyed;
	}

	/**
	 * Seats held by unexpired holds, keyed by departure ID.
	 *
	 * @param int[] $departure_ids Departure IDs.
	 * @return array<int, int>
	 */
	public function held_seats( array $departure_ids ): array {
		global $wpdb;

		$departure_ids = array_values( array_filter( array_map( 'absint', $departure_ids ) ) );

		if ( empty( $departure_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $departure_ids ), '%d' ) );
		$args         = array_merge( array( Schema::table( 'holds' ) ), $departure_ids, array( gmdate( 'Y-m-d H:i:s' ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; one %d placeholder per ID is built above and the values are passed as a spread.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT departure_id, SUM(seats) AS seats FROM %i WHERE departure_id IN ({$placeholders}) AND expires_at > %s GROUP BY departure_id",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$held = array();

		foreach ( (array) $rows as $row ) {
			$held[ (int) $row['departure_id'] ] = (int) $row['seats'];
		}

		return $held;
	}

	/**
	 * Returns the row for a slot, creating it if needed.
	 *
	 * @param int    $trip_id Trip ID.
	 * @param string $date    Y-m-d.
	 * @param string $time    HH:MM or ''.
	 * @param string $source  fixed, recurring or manual.
	 * @return array<string, mixed>|null Null when the insert failed.
	 */
	public function ensure( int $trip_id, string $date, string $time, string $source ): ?array {
		$existing = $this->find_slot( $trip_id, $date, $time );

		if ( $existing ) {
			return $existing;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		// INSERT IGNORE: a concurrent request may create the same slot first; the unique key keeps one row.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (trip_id, start_date, start_time, capacity, seats_booked, status, source, created, updated) VALUES (%d, %s, %s, NULL, 0, %s, %s, %s, %s)',
				Schema::table( 'departures' ),
				$trip_id,
				$date,
				$time,
				'open',
				$source,
				$now,
				$now
			)
		);

		return $this->find_slot( $trip_id, $date, $time );
	}

	/**
	 * Updates capacity and/or status.
	 *
	 * @param int                  $id      Departure ID.
	 * @param array<string, mixed> $changes capacity (int|null), status.
	 */
	public function update( int $id, array $changes ): bool {
		global $wpdb;

		$data    = array( 'updated' => current_time( 'mysql', true ) );
		$formats = array( '%s' );

		if ( array_key_exists( 'capacity', $changes ) ) {
			$data['capacity'] = null === $changes['capacity'] ? null : absint( $changes['capacity'] );
			$formats[]        = '%d';
		}

		if ( isset( $changes['status'] ) && in_array( $changes['status'], self::STATUSES, true ) ) {
			$data['status'] = $changes['status'];
			$formats[]      = '%s';
		}

		return false !== $wpdb->update( Schema::table( 'departures' ), $data, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Deletes a row that has no booked seats. Rows with bookings must be
	 * cancelled instead, so the bookings keep their departure.
	 *
	 * @param int $id Departure ID.
	 */
	public function delete_unbooked( int $id ): bool {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND seats_booked = 0', Schema::table( 'departures' ), $id )
		);

		if ( $deleted ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE departure_id = %d', Schema::table( 'holds' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		}

		return (bool) $deleted;
	}

	/**
	 * Casts numeric columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['trip_id']      = (int) $row['trip_id'];
		$row['capacity']     = null === $row['capacity'] ? null : (int) $row['capacity'];
		$row['seats_booked'] = (int) $row['seats_booked'];

		return $row;
	}
}
