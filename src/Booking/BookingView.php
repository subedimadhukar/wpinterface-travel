<?php
/**
 * Customer-facing booking data.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Booking;

use WPInterface\Travel\Emails\Triggers;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Payments\PaymentRepository;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Reviews\Reviews;
use WPInterface\Travel\Support\Money;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * What a customer sees about their booking: no internal IDs, IPs, notes by
 * staff or provider data.
 */
final class BookingView {

	/**
	 * Statuses a customer can ask to cancel.
	 */
	public const CANCELLABLE = array( 'pending', 'confirmed' );

	/**
	 * Booking for the customer.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array<string, mixed>
	 */
	public static function for_customer( array $booking ): array {
		$repository = new BookingRepository();
		$today      = current_time( 'Y-m-d' );
		$end        = Triggers::end_date( $booking );
		$decimals   = \WPInterface\Travel\Support\Currencies::decimals( (string) $booking['currency'] );
		$open       = ! in_array( $booking['status'], BookingRepository::RELEASED_STATUSES, true );
		$customer   = $repository->customer( $booking );
		$payments   = array();

		foreach ( ( new PaymentRepository() )->for_booking( (int) $booking['id'] ) as $row ) {
			if ( 'completed' !== $row['status'] ) {
				continue;
			}

			$gateway    = Gateways::get( (string) $row['gateway'] );
			$payments[] = array(
				'date'   => get_date_from_gmt( (string) $row['created'], 'Y-m-d' ),
				'type'   => $row['payment_type'],
				'amount' => $row['amount'],
				'method' => $gateway ? $gateway->title() : __( 'Recorded by staff', 'wpinterface-travel' ),
			);
		}

		$items = array();

		foreach ( $repository->items( (int) $booking['id'] ) as $item ) {
			$items[] = array(
				'type'     => $item['item_type'],
				'label'    => $item['label'],
				'quantity' => $item['quantity'],
				'total'    => Money::normalize( (string) $item['total'], $decimals ),
				'included' => ! empty( $item['meta']['included'] ),
			);
		}

		$travelers = array_map(
			static fn( array $row ): array => array(
				'first_name' => $row['first_name'],
				'last_name'  => $row['last_name'],
				'category'   => $row['category'],
				'lead'       => (bool) $row['is_lead'],
			),
			$repository->travelers( (int) $booking['id'] )
		);

		return array(
			'booking_key'          => $booking['booking_key'],
			'token'                => BookingAccess::token( $booking ),
			'number'               => $booking['number'],
			'status'               => $booking['status'],
			'status_label'         => BookingAccess::status_label( (string) $booking['status'] ),
			'payment_status'       => $booking['payment_status'],
			'payment_status_label' => BookingAccess::payment_status_label( (string) $booking['payment_status'] ),
			'trip'                 => array(
				'id'    => (int) $booking['trip_id'],
				'title' => get_the_title( Multilingual::translated_id( (int) $booking['trip_id'] ) ),
				'url'   => (string) get_permalink( Multilingual::translated_id( (int) $booking['trip_id'] ) ),
			),
			'date'                 => $booking['start_date'],
			'time'                 => $booking['start_time'],
			'pickup'               => Pickups::of_booking( $booking ),
			'end_date'             => $end,
			'pax'                  => $booking['pax'],
			'contact'              => array(
				'first_name' => $customer['first_name'],
				'last_name'  => $customer['last_name'],
				'email'      => $customer['email'],
			),
			'travelers'            => $travelers,
			'items'                => $items,
			'currency'             => $booking['currency'],
			'total'                => $booking['total'],
			'amount_paid'          => $booking['amount_paid'],
			'balance'              => $booking['balance'],
			'payments'             => $payments,
			'can_pay'              => $open && 'cancel_requested' !== $booking['status'] && Money::to_minor( (string) $booking['balance'], $decimals ) > 0,
			'can_cancel'           => in_array( $booking['status'], self::CANCELLABLE, true ) && $booking['start_date'] > $today,
			'can_review'           => Reviews::can_review( $booking ),
			'voucher_url'          => $open ? BookingAccess::voucher_url( $booking ) : '',
			'created'              => get_date_from_gmt( (string) $booking['created'], 'Y-m-d' ),
			// What paying now asks for, including bookings paid together with this one.
			'amount_due'           => ( new PaymentService() )->amount_due( $booking ),
			/**
			 * Filters short notes shown to the customer on their booking
			 * (e.g. Pro's payment plan: "Balance due on 1 March").
			 *
			 * @param string[] $notes   Notes (plain text).
			 * @param array    $booking Booking.
			 */
			'notes'                => array_values( array_map( 'strval', (array) apply_filters( 'wpitravel_customer_booking_notes', array(), $booking ) ) ),
			'links'                => self::links( $booking ),
		);
	}

	/**
	 * Files and links for the customer, from add-ons.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return list<array{label: string, url: string, note: string}>
	 */
	private static function links( array $booking ): array {
		/**
		 * Filters files and links shown to the customer on their booking
		 * (e.g. Pro's downloads: itineraries, souvenirs).
		 *
		 * @param array<int, array{label: string, url: string, note?: string}> $links   Links.
		 * @param array                                                        $booking Booking.
		 */
		$links = (array) apply_filters( 'wpitravel_customer_booking_links', array(), $booking );
		$clean = array();

		foreach ( $links as $link ) {
			$url = is_array( $link ) ? esc_url_raw( (string) ( $link['url'] ?? '' ) ) : '';

			if ( '' === $url || '' === trim( (string) ( $link['label'] ?? '' ) ) ) {
				continue;
			}

			$clean[] = array(
				'label' => sanitize_text_field( (string) $link['label'] ),
				'url'   => $url,
				'note'  => sanitize_text_field( (string) ( $link['note'] ?? '' ) ),
			);
		}

		return $clean;
	}
}
