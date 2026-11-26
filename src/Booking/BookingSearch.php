<?php
/**
 * Booking search for staff.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WPInterface\Travel\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Finds bookings for the admin list: by status, payment status, trip,
 * departure date and a search term (booking number, customer name, email
 * or phone).
 */
final class BookingSearch {

	public const ORDERBY = array( 'created', 'start_date', 'total', 'id' );

	/**
	 * Runs a search.
	 *
	 * @param array<string, mixed> $args status, payment_status, trip_id, search, date_from, date_to,
	 *                                   orderby, order, page, per_page.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public static function run( array $args ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'         => '',
				'payment_status' => '',
				'trip_id'        => 0,
				'search'         => '',
				'date_from'      => '',
				'date_to'        => '',
				'orderby'        => 'created',
				'order'          => 'desc',
				'page'           => 1,
				'per_page'       => 20,
			)
		);

		$bookings  = Schema::table( 'bookings' );
		$customers = Schema::table( 'customers' );
		$sort      = ( in_array( $args['orderby'], self::ORDERBY, true ) ? $args['orderby'] : 'created' ) . ( 'asc' === strtolower( (string) $args['order'] ) ? '_asc' : '_desc' );
		$per_page  = max( 1, min( 100, (int) $args['per_page'] ) );
		$page      = max( 1, (int) $args['page'] );
		$filters   = self::filters( $args );

		// Fixed SQL: empty filters match everything, and the sort is chosen
		// with CASE on a placeholder, so no value is ever put into the query text.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom tables; the values are spread from filters(), which the sniff cannot count.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i b LEFT JOIN %i c ON c.id = b.customer_id WHERE (%s = \'\' OR b.status = %s) AND (%s = \'\' OR b.payment_status = %s) AND (%d = 0 OR b.trip_id = %d) AND (%s = \'\' OR b.start_date >= %s) AND (%s = \'\' OR b.start_date <= %s) AND (%s = \'\' OR c.email LIKE %s OR c.phone LIKE %s OR CONCAT(c.first_name, \' \', c.last_name) LIKE %s OR b.id = %d)', $bookings, $customers, ...$filters ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.id FROM %i b LEFT JOIN %i c ON c.id = b.customer_id WHERE (%s = \'\' OR b.status = %s) AND (%s = \'\' OR b.payment_status = %s) AND (%d = 0 OR b.trip_id = %d) AND (%s = \'\' OR b.start_date >= %s) AND (%s = \'\' OR b.start_date <= %s) AND (%s = \'\' OR c.email LIKE %s OR c.phone LIKE %s OR CONCAT(c.first_name, \' \', c.last_name) LIKE %s OR b.id = %d)
				ORDER BY
					CASE WHEN %s = \'created_asc\' THEN b.created END ASC,
					CASE WHEN %s = \'created_desc\' THEN b.created END DESC,
					CASE WHEN %s = \'start_date_asc\' THEN b.start_date END ASC,
					CASE WHEN %s = \'start_date_desc\' THEN b.start_date END DESC,
					CASE WHEN %s = \'total_asc\' THEN b.total END ASC,
					CASE WHEN %s = \'total_desc\' THEN b.total END DESC,
					CASE WHEN %s LIKE \'%%\_asc\' THEN b.id END ASC,
					b.id DESC
				LIMIT %d OFFSET %d',
				$bookings,
				$customers,
				...array_merge( $filters, array_fill( 0, 7, $sort ), array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$repository = new BookingRepository();
		$items      = array();

		foreach ( (array) $rows as $row ) {
			$booking = $repository->find( (int) $row['id'] );

			if ( $booking ) {
				$items[] = self::summary( $booking, $repository->customer( $booking ) );
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Number of bookings per status (for the list's status links).
	 *
	 * @return array<string, int>
	 */
	public static function status_counts(): array {
		global $wpdb;

		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Schema::table( 'bookings' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$counts = array_fill_keys( BookingRepository::STATUSES, 0 );

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * A booking row for lists.
	 *
	 * @param array<string, mixed> $booking  Booking.
	 * @param array<string, mixed> $customer Customer.
	 * @return array<string, mixed>
	 */
	public static function summary( array $booking, array $customer ): array {
		return array(
			'id'                   => $booking['id'],
			'number'               => $booking['number'],
			'status'               => $booking['status'],
			'status_label'         => BookingAccess::status_label( (string) $booking['status'] ),
			'payment_status'       => $booking['payment_status'],
			'payment_status_label' => BookingAccess::payment_status_label( (string) $booking['payment_status'] ),
			'trip'                 => array(
				'id'    => $booking['trip_id'],
				'title' => html_entity_decode( get_the_title( $booking['trip_id'] ), ENT_QUOTES, 'UTF-8' ),
			),
			'date'                 => $booking['start_date'],
			'time'                 => $booking['start_time'],
			'pax'                  => $booking['pax'],
			'customer'             => array(
				'name'  => trim( $customer['first_name'] . ' ' . $customer['last_name'] ),
				'email' => $customer['email'],
			),
			'currency'             => $booking['currency'],
			'total'                => $booking['total'],
			'amount_paid'          => $booking['amount_paid'],
			'balance'              => $booking['balance'],
			'created'              => $booking['created'],
		);
	}

	/**
	 * Values for the fixed WHERE clause, in order.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return list<mixed>
	 */
	private static function filters( array $args ): array {
		global $wpdb;

		$status  = in_array( $args['status'], BookingRepository::STATUSES, true ) ? (string) $args['status'] : '';
		$payment = in_array( $args['payment_status'], array( 'unpaid', 'partial', 'paid', 'refunded', 'partially_refunded' ), true ) ? (string) $args['payment_status'] : '';
		$trip    = max( 0, (int) $args['trip_id'] );
		$from    = preg_match( '/^\d{4}-\d{2}-\d{2}\z/', (string) $args['date_from'] ) ? (string) $args['date_from'] : '';
		$to      = preg_match( '/^\d{4}-\d{2}-\d{2}\z/', (string) $args['date_to'] ) ? (string) $args['date_to'] : '';
		$search  = trim( (string) $args['search'] );
		$like    = '%' . $wpdb->esc_like( $search ) . '%';

		// A booking number ("TRV-12" or "12") also matches the booking ID.
		$prefix = (string) wpitravel()->options()->get( 'booking.number_prefix', 'TRV-' );
		$number = '' !== $prefix && 0 === stripos( $search, $prefix ) ? substr( $search, strlen( $prefix ) ) : $search;
		$id     = ctype_digit( $number ) ? (int) $number : -1;

		return array( $status, $status, $payment, $payment, $trip, $trip, $from, $from, $to, $to, $search, $like, $like, $like, $id );
	}
}
