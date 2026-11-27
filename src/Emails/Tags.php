<?php
/**
 * Email merge tags.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Emails;

use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Support\Money;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the values of {merge_tags} for a booking or an enquiry.
 *
 * Values are plain text unless listed in html(); plain values are escaped
 * when placed in the HTML body.
 */
final class Tags {

	/**
	 * Tags whose value is already safe HTML.
	 *
	 * @return list<string>
	 */
	public static function html(): array {
		return array( 'booking_summary', 'payment_instructions', 'enquiry_message', 'customer_note' );
	}

	/**
	 * Tag names and descriptions for the editor.
	 *
	 * @param string $context booking, enquiry, or an add-on's context.
	 * @return array<string, string>
	 */
	public static function describe( string $context ): array {
		/**
		 * Filters the extra tags listed in the email editor (tag => description).
		 * Add the values with `wpitravel_email_values`.
		 *
		 * @param array<string, string> $tags    Extra tags.
		 * @param string                $context booking or enquiry.
		 */
		$added = (array) apply_filters( 'wpitravel_email_tags', array(), $context );

		$common = array(
			'site_name' => __( 'Site name', 'wpinterface-travel' ),
			'site_url'  => __( 'Site address', 'wpinterface-travel' ),
		);

		if ( 'enquiry' === $context ) {
			return $common + array(
				'enquiry_name'    => __( 'Name', 'wpinterface-travel' ),
				'enquiry_email'   => __( 'Email', 'wpinterface-travel' ),
				'enquiry_phone'   => __( 'Phone', 'wpinterface-travel' ),
				'enquiry_message' => __( 'Message', 'wpinterface-travel' ),
				'trip_title'      => __( 'Trip name (or "our trips")', 'wpinterface-travel' ),
				'trip_link'       => __( 'Trip page address', 'wpinterface-travel' ),
			);
		}

		// Add-on contexts (e.g. Pro's waitlist) list only their own tags.
		if ( 'booking' !== $context ) {
			return $common + $added;
		}

		return $common + array(
			'booking_number'       => __( 'Booking number, e.g. TRV-1042', 'wpinterface-travel' ),
			'booking_status'       => __( 'Booking status', 'wpinterface-travel' ),
			'booking_link'         => __( 'Link to see and manage the booking', 'wpinterface-travel' ),
			'voucher_link'         => __( 'Link to the printable voucher', 'wpinterface-travel' ),
			'review_link'          => __( 'Link to write a review', 'wpinterface-travel' ),
			'booking_summary'      => __( 'Table of travelers, prices and totals', 'wpinterface-travel' ),
			'customer_name'        => __( 'Customer full name', 'wpinterface-travel' ),
			'customer_first_name'  => __( 'Customer first name', 'wpinterface-travel' ),
			'customer_email'       => __( 'Customer email', 'wpinterface-travel' ),
			'customer_phone'       => __( 'Customer phone', 'wpinterface-travel' ),
			'customer_note'        => __( 'Note from the customer', 'wpinterface-travel' ),
			'trip_title'           => __( 'Trip name', 'wpinterface-travel' ),
			'trip_link'            => __( 'Trip page address', 'wpinterface-travel' ),
			'start_date'           => __( 'Departure date', 'wpinterface-travel' ),
			'start_time'           => __( 'Start time', 'wpinterface-travel' ),
			'end_date'             => __( 'Return date', 'wpinterface-travel' ),
			'meeting_point'        => __( 'Meeting point', 'wpinterface-travel' ),
			'travelers'            => __( 'Number of travelers', 'wpinterface-travel' ),
			'total'                => __( 'Total', 'wpinterface-travel' ),
			'amount_paid'          => __( 'Amount paid', 'wpinterface-travel' ),
			'balance'              => __( 'Balance to pay', 'wpinterface-travel' ),
			'payment_amount'       => __( 'Amount of the payment just received', 'wpinterface-travel' ),
			'payment_method'       => __( 'Payment method', 'wpinterface-travel' ),
			'payment_instructions' => __( 'Bank details or other payment instructions', 'wpinterface-travel' ),
			'cancellation_reason'  => __( 'Reason given for cancelling', 'wpinterface-travel' ),
		) + $added;
	}

	/**
	 * Values for a booking.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param array<string, mixed> $extra   Extra values (payment_amount, cancellation_reason).
	 * @return array<string, string>
	 */
	public static function for_booking( array $booking, array $extra = array() ): array {
		$repository = new BookingRepository();
		$customer   = $repository->customer( $booking );
		$currency   = array_merge( (array) wpitravel()->options()->get( 'currency', array() ), array( 'code' => $booking['currency'] ) );
		$money      = static fn( string $amount ): string => Money::format( $amount, $currency );
		$duration   = TripMeta::sanitize_duration( get_post_meta( (int) $booking['trip_id'], TripMeta::DURATION, true ) );
		$end        = $duration['days'] > 1 ? gmdate( 'Y-m-d', (int) strtotime( $booking['start_date'] . ' +' . ( $duration['days'] - 1 ) . ' days' ) ) : $booking['start_date'];
		$gateway    = Gateways::get( (string) $booking['payment_method'] );

		$instructions = '';

		if ( $gateway && ! $gateway->is_online() && 'paid' !== $booking['payment_status'] ) {
			$started      = $gateway->start( $booking, null );
			$instructions = is_array( $started ) ? (string) ( $started['instructions'] ?? '' ) : '';
		}

		// The trip's name and page in the customer's language.
		$trip_in_language = Multilingual::translated_id( (int) $booking['trip_id'], (string) ( $booking['locale'] ?? '' ) );

		$values = array_merge(
			self::site(),
			array(
				'booking_number'       => (string) $booking['number'],
				'booking_status'       => BookingAccess::status_label( (string) $booking['status'] ),
				'booking_link'         => BookingAccess::manage_url( $booking ),
				'voucher_link'         => BookingAccess::voucher_url( $booking ),
				'review_link'          => BookingAccess::manage_url( $booking, array( 'review' => '1' ) ),
				'booking_summary'      => self::summary( $booking, $money ),
				'customer_name'        => trim( $customer['first_name'] . ' ' . $customer['last_name'] ),
				'customer_first_name'  => (string) $customer['first_name'],
				'customer_email'       => (string) $customer['email'],
				'customer_phone'       => '' !== $customer['phone'] ? (string) $customer['phone'] : '—',
				'customer_note'        => '' !== (string) $booking['customer_note'] ? nl2br( esc_html( (string) $booking['customer_note'] ) ) : '—',
				'trip_title'           => get_the_title( $trip_in_language ),
				'trip_link'            => (string) get_permalink( $trip_in_language ),
				'start_date'           => self::date( (string) $booking['start_date'] ),
				'start_time'           => (string) $booking['start_time'],
				'end_date'             => self::date( $end ),
				'meeting_point'        => '' !== (string) get_post_meta( (int) $booking['trip_id'], TripMeta::MEETING_POINT, true ) ? (string) get_post_meta( (int) $booking['trip_id'], TripMeta::MEETING_POINT, true ) : '—',
				'travelers'            => (string) $booking['pax'],
				'total'                => $money( (string) $booking['total'] ),
				'amount_paid'          => $money( (string) $booking['amount_paid'] ),
				'balance'              => $money( (string) $booking['balance'] ),
				'payment_amount'       => isset( $extra['payment_amount'] ) ? $money( (string) $extra['payment_amount'] ) : '',
				'payment_method'       => $gateway ? $gateway->title() : '—',
				'payment_instructions' => '' !== $instructions ? nl2br( esc_html( $instructions ) ) : '',
				'cancellation_reason'  => isset( $extra['cancellation_reason'] ) && '' !== $extra['cancellation_reason'] ? (string) $extra['cancellation_reason'] : '—',
			)
		);

		// Other values the sender passed (plain text, e.g. Pro's {amount_due}).
		foreach ( $extra as $key => $value ) {
			if ( is_string( $key ) && ! isset( $values[ $key ] ) && is_scalar( $value ) && preg_match( '/^[a-z_]+\z/', $key ) ) {
				$values[ $key ] = (string) $value;
			}
		}

		/**
		 * Filters the tag values of a booking email (add-ons add their own
		 * tags, e.g. {invoice_link}). Values are plain text.
		 *
		 * @param array<string, string> $values  Tag => value.
		 * @param array                 $booking Booking.
		 * @param array                 $extra   Values passed by the sender.
		 */
		return (array) apply_filters( 'wpitravel_email_values', $values, $booking, $extra );
	}

	/**
	 * Values for an enquiry.
	 *
	 * @param array<string, mixed> $enquiry Enquiry.
	 * @return array<string, string>
	 */
	public static function for_enquiry( array $enquiry ): array {
		$trip = (int) $enquiry['trip_id'];

		return array_merge(
			self::site(),
			array(
				'enquiry_name'    => (string) $enquiry['name'],
				'enquiry_email'   => (string) $enquiry['email'],
				'enquiry_phone'   => '' !== (string) $enquiry['phone'] ? (string) $enquiry['phone'] : '—',
				'enquiry_message' => nl2br( esc_html( (string) $enquiry['message'] ) ),
				'trip_title'      => $trip ? get_the_title( $trip ) : __( 'our trips', 'wpinterface-travel' ),
				'trip_link'       => $trip ? (string) get_permalink( $trip ) : home_url( '/' ),
			)
		);
	}

	/**
	 * Replaces {tags} in a text.
	 *
	 * @param string                $text   Text.
	 * @param array<string, string> $values Values.
	 * @param bool                  $html   Escape plain values for HTML; false strips tags (subjects).
	 */
	public static function replace( string $text, array $values, bool $html ): string {
		$safe = array_flip( self::html() );

		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $found ) use ( $values, $html, $safe ): string {
				if ( ! array_key_exists( $found[1], $values ) ) {
					return $found[0];
				}

				$value = (string) $values[ $found[1] ];

				if ( ! $html ) {
					return wp_strip_all_tags( $value );
				}

				if ( isset( $safe[ $found[1] ] ) ) {
					return $value;
				}

				// Links become clickable.
				if ( 0 === strpos( $value, 'http' ) && false === strpos( $value, ' ' ) ) {
					return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $value ), esc_html( $value ) );
				}

				return esc_html( $value );
			},
			$text
		);
	}

	/**
	 * Site values.
	 *
	 * @return array<string, string>
	 */
	public static function site_values(): array {
		return self::site();
	}

	/**
	 * Site name and address.
	 *
	 * @return array{site_name: string, site_url: string}
	 */
	private static function site(): array {
		return array(
			'site_name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'  => home_url( '/' ),
		);
	}

	/**
	 * A date in the site's date format.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function date( string $date ): string {
		return '' !== $date ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : '';
	}

	/**
	 * HTML table of items and totals.
	 *
	 * @param array<string, mixed>     $booking Booking.
	 * @param callable(string): string $money   Formatter.
	 */
	private static function summary( array $booking, callable $money ): string {
		$rows = '';

		foreach ( ( new BookingRepository() )->items( (int) $booking['id'] ) as $item ) {
			$label = 'pax' === $item['item_type'] ? $item['label'] . ' × ' . $item['quantity'] : $item['label'];
			$total = ! empty( $item['meta']['included'] ) ? '(' . $money( (string) $item['total'] ) . ')' : $money( (string) $item['total'] );
			$rows .= sprintf( '<tr><td style="padding:4px 0">%1$s</td><td style="padding:4px 0;text-align:right">%2$s</td></tr>', esc_html( $label ), esc_html( $total ) );
		}

		$rows .= sprintf(
			'<tr><td style="padding:8px 0 4px;border-top:1px solid #ddd"><strong>%1$s</strong></td><td style="padding:8px 0 4px;border-top:1px solid #ddd;text-align:right"><strong>%2$s</strong></td></tr>',
			esc_html__( 'Total', 'wpinterface-travel' ),
			esc_html( $money( (string) $booking['total'] ) )
		);

		return sprintf(
			'<p><strong>%1$s</strong><br>%2$s%3$s</p><table role="presentation" style="width:100%%;border-collapse:collapse">%4$s</table>',
			esc_html( get_the_title( Multilingual::translated_id( (int) $booking['trip_id'], (string) ( $booking['locale'] ?? '' ) ) ) ),
			esc_html( self::date( (string) $booking['start_date'] ) ),
			'' !== $booking['start_time'] ? esc_html( ' · ' . $booking['start_time'] ) : '',
			$rows
		);
	}
}
