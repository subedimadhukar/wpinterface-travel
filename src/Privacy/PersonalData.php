<?php
/**
 * Personal data export and erasure.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Privacy;

use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Connects bookings, travelers and enquiries to Tools → Export / Erase
 * Personal Data (GDPR and similar laws). Reviews are comments, which core
 * already handles.
 *
 * Erasing anonymises names, contact details, notes and IP addresses but
 * keeps dates and amounts, which agencies must keep for accounting.
 */
final class PersonalData {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function exporters( $exporters ): array {
		$exporters                       = is_array( $exporters ) ? $exporters : array();
		$exporters['wpinterface-travel'] = array(
			'exporter_friendly_name' => __( 'Travel bookings and enquiries', 'wpinterface-travel' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function erasers( $erasers ): array {
		$erasers                       = is_array( $erasers ) ? $erasers : array();
		$erasers['wpinterface-travel'] = array(
			'eraser_friendly_name' => __( 'Travel bookings and enquiries', 'wpinterface-travel' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports everything stored for an email.
	 *
	 * @param string $email Email.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( $email ): array {
		global $wpdb;

		$email = strtolower( (string) $email );
		$data  = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.
		$customer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', Schema::table( 'customers' ), $email ), ARRAY_A );

		if ( is_array( $customer ) ) {
			$bookings = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d ORDER BY id', Schema::table( 'bookings' ), (int) $customer['id'] ), ARRAY_A );

			foreach ( $bookings as $booking ) {
				$travelers = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT first_name, last_name, email, phone, birth_date, nationality FROM %i WHERE booking_id = %d', Schema::table( 'travelers' ), (int) $booking['id'] ), ARRAY_A );

				$data[] = array(
					'group_id'    => 'wpitravel-bookings',
					'group_label' => __( 'Trip bookings', 'wpinterface-travel' ),
					'item_id'     => 'wpitravel-booking-' . $booking['id'],
					'data'        => array(
						array(
							'name'  => __( 'Booking number', 'wpinterface-travel' ),
							'value' => (string) wpitravel()->options()->get( 'booking.number_prefix', 'TRV-' ) . $booking['id'],
						),
						array(
							'name'  => __( 'Trip', 'wpinterface-travel' ),
							'value' => get_the_title( (int) $booking['trip_id'] ),
						),
						array(
							'name'  => __( 'Date', 'wpinterface-travel' ),
							'value' => trim( $booking['start_date'] . ' ' . $booking['start_time'] ),
						),
						array(
							'name'  => __( 'Status', 'wpinterface-travel' ),
							'value' => BookingAccess::status_label( (string) $booking['status'] ),
						),
						array(
							'name'  => __( 'Total', 'wpinterface-travel' ),
							'value' => $booking['total'] . ' ' . $booking['currency'],
						),
						array(
							'name'  => __( 'Contact', 'wpinterface-travel' ),
							'value' => implode( ', ', array_filter( array( trim( $customer['first_name'] . ' ' . $customer['last_name'] ), $customer['email'], $customer['phone'], $customer['country'] ) ) ),
						),
						array(
							'name'  => __( 'Travelers', 'wpinterface-travel' ),
							'value' => implode( '; ', array_map( static fn( $t ) => trim( implode( ' ', array_filter( $t ) ) ), $travelers ) ),
						),
						array(
							'name'  => __( 'Note', 'wpinterface-travel' ),
							'value' => (string) $booking['customer_note'],
						),
						array(
							'name'  => __( 'IP address', 'wpinterface-travel' ),
							'value' => (string) $booking['ip'],
						),
						array(
							'name'  => __( 'Cancellation reason', 'wpinterface-travel' ),
							'value' => implode( "\n", wp_list_pluck( self::free_text( (int) $booking['id'], true ), 'message' ) ),
						),
					),
				);

				/**
				 * Filters the exported data of one booking (add-ons append
				 * what they stored, e.g. answers to checkout questions).
				 *
				 * @param array $rows       Name/value rows.
				 * @param int   $booking_id Booking.
				 */
				$last                  = count( $data ) - 1;
				$data[ $last ]['data'] = (array) apply_filters( 'wpitravel_export_booking_data', $data[ $last ]['data'], (int) $booking['id'] );
			}
		}

		// The person may also travel on someone else's booking.
		$traveling = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT t.id, t.first_name, t.last_name, t.phone, t.birth_date, t.nationality, b.trip_id, b.start_date FROM %i t INNER JOIN %i b ON b.id = t.booking_id WHERE t.email = %s AND b.customer_id <> %d', Schema::table( 'travelers' ), Schema::table( 'bookings' ), $email, is_array( $customer ) ? (int) $customer['id'] : 0 ), ARRAY_A );

		foreach ( $traveling as $row ) {
			$data[] = array(
				'group_id'    => 'wpitravel-travelers',
				'group_label' => __( 'Trips as a traveler', 'wpinterface-travel' ),
				'item_id'     => 'wpitravel-traveler-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Trip', 'wpinterface-travel' ),
						'value' => get_the_title( (int) $row['trip_id'] ) . ', ' . $row['start_date'],
					),
					array(
						'name'  => __( 'Traveler', 'wpinterface-travel' ),
						'value' => trim( implode( ' ', array_filter( array( $row['first_name'], $row['last_name'], $row['phone'], $row['birth_date'], $row['nationality'] ) ) ) ),
					),
				),
			);
		}

		$enquiries = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', Schema::table( 'enquiries' ), $email ), ARRAY_A );
		// phpcs:enable

		foreach ( $enquiries as $enquiry ) {
			$data[] = array(
				'group_id'    => 'wpitravel-enquiries',
				'group_label' => __( 'Trip enquiries', 'wpinterface-travel' ),
				'item_id'     => 'wpitravel-enquiry-' . $enquiry['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'wpinterface-travel' ),
						'value' => (string) $enquiry['created'],
					),
					array(
						'name'  => __( 'Name', 'wpinterface-travel' ),
						'value' => (string) $enquiry['name'],
					),
					array(
						'name'  => __( 'Phone', 'wpinterface-travel' ),
						'value' => (string) $enquiry['phone'],
					),
					array(
						'name'  => __( 'Message', 'wpinterface-travel' ),
						'value' => (string) $enquiry['message'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Anonymises everything stored for an email.
	 *
	 * @param string $email Email.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( $email ): array {
		global $wpdb;

		$email    = strtolower( (string) $email );
		$removed  = false;
		$retained = false;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.
		$customer = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', Schema::table( 'customers' ), $email ), ARRAY_A );

		if ( is_array( $customer ) ) {
			$customer_id = (int) $customer['id'];
			$booking_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_id = %d', Schema::table( 'bookings' ), $customer_id ) ) );
			$host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );

			$wpdb->update(
				Schema::table( 'customers' ),
				array(
					// Unique, so the email column stays unique.
					'email'      => 'deleted-' . $customer_id . '@' . ( '' !== $host ? $host : 'example' ) . '.invalid',
					'first_name' => '',
					'last_name'  => '',
					'phone'      => '',
					'country'    => '',
					'user_id'    => 0,
				),
				array( 'id' => $customer_id )
			);

			foreach ( $booking_ids as $booking_id ) {
				$wpdb->update(
					Schema::table( 'bookings' ),
					array(
						'customer_note' => '',
						'ip'            => '',
						'user_id'       => 0,
					),
					array( 'id' => $booking_id )
				);
				$wpdb->update( Schema::table( 'travelers' ), self::blank_traveler(), array( 'booking_id' => $booking_id ) );

				// Free text in the history: cancellation reasons and staff notes.
				foreach ( self::free_text( $booking_id, false ) as $entry ) {
					$wpdb->update( Schema::table( 'booking_log' ), array( 'message' => '' ), array( 'id' => (int) $entry['id'] ) );
				}

				/**
				 * Fires while a booking's personal data is erased, so add-ons
				 * erase what they stored for it.
				 *
				 * @param int $booking_id Booking.
				 */
				do_action( 'wpitravel_erase_booking_personal_data', $booking_id );
			}

			$removed  = true;
			$retained = ! empty( $booking_ids );
		}

		// Traveler rows on other people's bookings.
		$travelers = (int) $wpdb->update( Schema::table( 'travelers' ), self::blank_traveler(), array( 'email' => $email ) );
		$removed   = $removed || $travelers > 0;

		$deleted = (int) $wpdb->delete( Schema::table( 'enquiries' ), array( 'email' => $email ) );
		// phpcs:enable

		return array(
			'items_removed'  => $removed || $deleted > 0,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Booking dates and amounts were kept for accounting; names and contact details were removed.', 'wpinterface-travel' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Empty traveler columns.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank_traveler(): array {
		return array(
			'first_name'  => '',
			'last_name'   => '',
			'email'       => '',
			'phone'       => '',
			'birth_date'  => null,
			'nationality' => '',
			'meta'        => null,
		);
	}

	/**
	 * History entries holding free text: the customer's cancellation reasons
	 * and (unless $customer_only) staff notes.
	 *
	 * @param int  $booking_id    Booking.
	 * @param bool $customer_only Only what the customer wrote.
	 * @return list<array{id: int, message: string}>
	 */
	private static function free_text( int $booking_id, bool $customer_only ): array {
		global $wpdb;

		$rows    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, event, message, context FROM %i WHERE booking_id = %d ORDER BY id', Schema::table( 'booking_log' ), $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$entries = array();

		foreach ( $rows as $row ) {
			$context = json_decode( (string) $row['context'], true );
			$reason  = 'status_changed' === $row['event'] && 'cancel_requested' === ( is_array( $context ) ? ( $context['to'] ?? '' ) : '' );

			if ( '' !== (string) $row['message'] && ( $reason || ( ! $customer_only && 'note' === $row['event'] ) ) ) {
				$entries[] = array(
					'id'      => (int) $row['id'],
					'message' => (string) $row['message'],
				);
			}
		}

		return $entries;
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'WPInterface Travel', 'wpinterface-travel' ),
			wp_kses_post(
				wpautop(
					__( 'When you book a trip we store your name, email address, phone number, the names of the travelers, your notes and your IP address, to run the booking and prevent fraud. When you send an enquiry we store your name, email, phone number and message. When you pay online, the payment provider (for example PayPal) receives the amount, the booking number and the trip name; it does not share card details with us. We keep booking dates and amounts for as long as accounting law requires. You can ask us to export or erase your personal data.', 'wpinterface-travel' )
				)
			)
		);
	}
}
