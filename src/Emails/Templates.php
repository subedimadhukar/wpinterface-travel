<?php
/**
 * Email templates.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * The emails the plugin sends, with their default subject and body.
 *
 * Staff edit them in Travel → Emails; the text is stored in the settings
 * (emails.templates.{id}). Pro adds more with `wpitravel_email_templates`.
 */
final class Templates {

	/**
	 * Every template: id => title, recipient (customer|admin), description, context (booking|enquiry), subject, body.
	 *
	 * @return array<string, array{title: string, recipient: string, description: string, context: string, subject: string, body: string}>
	 */
	public static function definitions(): array {
		$definitions = array(
			'customer_booking_received'  => array(
				'title'       => __( 'Booking received', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent to the customer right after booking.', 'wpinterface-travel' ),
				'subject'     => __( 'We received your booking {booking_number}', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nThank you for booking {trip_title}. Your booking number is {booking_number}.\n\n{booking_summary}\n\n{payment_instructions}\n\nYou can see your booking, pay the balance or print your voucher here: {booking_link}\n\nWe will confirm your booking soon.",
					'wpinterface-travel'
				),
			),
			'customer_booking_confirmed' => array(
				'title'       => __( 'Booking confirmed', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent when a booking is confirmed, by staff or after full payment.', 'wpinterface-travel' ),
				'subject'     => __( 'Your booking {booking_number} is confirmed', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nGood news: your booking {booking_number} for {trip_title} on {start_date} is confirmed.\n\n{booking_summary}\n\nPrint or save your voucher: {voucher_link}\n\nSee you soon!",
					'wpinterface-travel'
				),
			),
			'customer_payment_received'  => array(
				'title'       => __( 'Payment received', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent when a payment is received but the booking was not confirmed by it (for example a deposit).', 'wpinterface-travel' ),
				'subject'     => __( 'Payment received for booking {booking_number}', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nWe received {payment_amount} for booking {booking_number}.\n\nPaid so far: {amount_paid}\nBalance: {balance}\n\nYour booking: {booking_link}",
					'wpinterface-travel'
				),
			),
			'customer_booking_cancelled' => array(
				'title'       => __( 'Booking cancelled', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent when a booking is cancelled.', 'wpinterface-travel' ),
				'subject'     => __( 'Your booking {booking_number} was cancelled', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nYour booking {booking_number} for {trip_title} on {start_date} was cancelled.\n\nIf you paid, we will contact you about your refund. If you have questions, just reply to this email.",
					'wpinterface-travel'
				),
			),
			'customer_trip_reminder'     => array(
				'title'       => __( 'Trip reminder', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent to confirmed bookings a few days before departure (set the number of days above).', 'wpinterface-travel' ),
				'subject'     => __( 'Your trip {trip_title} starts on {start_date}', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nYour trip {trip_title} starts on {start_date} {start_time}.\n\nMeeting point: {meeting_point}\nBalance to pay: {balance}\n\nYour voucher: {voucher_link}\n\nHave a great trip!",
					'wpinterface-travel'
				),
			),
			'customer_review_request'    => array(
				'title'       => __( 'Review request', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent after the trip ends, asking for a review.', 'wpinterface-travel' ),
				'subject'     => __( 'How was {trip_title}?', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nWe hope you enjoyed {trip_title}. Would you take a minute to tell other travelers about it?\n\nWrite a review: {review_link}\n\nThank you!",
					'wpinterface-travel'
				),
			),
			'customer_enquiry_received'  => array(
				'title'       => __( 'Enquiry received', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'enquiry',
				'description' => __( 'Sent to the person who sent an enquiry.', 'wpinterface-travel' ),
				'subject'     => __( 'We received your question', 'wpinterface-travel' ),
				// No name or message from the form: anyone can type any address,
				// so echoing their text would let strangers send mail from the site.
				'body'        => __(
					"Hello,\n\nThank you for your message about {trip_title}. We will reply as soon as we can.\n\nIf you did not write to us, you can ignore this email.",
					'wpinterface-travel'
				),
			),
			'customer_booking_link'      => array(
				'title'       => __( 'Booking link', 'wpinterface-travel' ),
				'recipient'   => 'customer',
				'context'     => 'booking',
				'description' => __( 'Sent when a customer looks up their booking on the "My bookings" page.', 'wpinterface-travel' ),
				'subject'     => __( 'Your booking {booking_number}', 'wpinterface-travel' ),
				'body'        => __(
					"Hi {customer_first_name},\n\nHere is the link to your booking {booking_number} for {trip_title}:\n{booking_link}\n\nIf you did not ask for this link, you can ignore this email.",
					'wpinterface-travel'
				),
			),
			'admin_new_booking'          => array(
				'title'       => __( 'New booking (staff)', 'wpinterface-travel' ),
				'recipient'   => 'admin',
				'context'     => 'booking',
				'description' => __( 'Sent to staff for every new booking.', 'wpinterface-travel' ),
				'subject'     => __( 'New booking {booking_number}: {trip_title} on {start_date}', 'wpinterface-travel' ),
				'body'        => __(
					"New booking {booking_number} from {customer_name} ({customer_email}, {customer_phone}).\n\n{booking_summary}\n\nPayment method: {payment_method}\nNote from the customer: {customer_note}",
					'wpinterface-travel'
				),
			),
			'admin_payment_received'     => array(
				'title'       => __( 'Payment received (staff)', 'wpinterface-travel' ),
				'recipient'   => 'admin',
				'context'     => 'booking',
				'description' => __( 'Sent to staff when a payment is received.', 'wpinterface-travel' ),
				'subject'     => __( 'Payment of {payment_amount} for {booking_number}', 'wpinterface-travel' ),
				'body'        => __(
					"{customer_name} paid {payment_amount} for booking {booking_number} ({trip_title}, {start_date}).\n\nPaid so far: {amount_paid}\nBalance: {balance}",
					'wpinterface-travel'
				),
			),
			'admin_cancel_request'       => array(
				'title'       => __( 'Cancellation request (staff)', 'wpinterface-travel' ),
				'recipient'   => 'admin',
				'context'     => 'booking',
				'description' => __( 'Sent to staff when a customer asks to cancel.', 'wpinterface-travel' ),
				'subject'     => __( 'Cancellation request for {booking_number}', 'wpinterface-travel' ),
				'body'        => __(
					"{customer_name} asked to cancel booking {booking_number} ({trip_title}, {start_date}).\n\nReason: {cancellation_reason}\n\nPaid so far: {amount_paid}",
					'wpinterface-travel'
				),
			),
			'admin_new_enquiry'          => array(
				'title'       => __( 'New enquiry (staff)', 'wpinterface-travel' ),
				'recipient'   => 'admin',
				'context'     => 'enquiry',
				'description' => __( 'Sent to staff for every new enquiry. Reply to it to answer the customer.', 'wpinterface-travel' ),
				'subject'     => __( 'Enquiry from {enquiry_name} about {trip_title}', 'wpinterface-travel' ),
				'body'        => __(
					"{enquiry_name} ({enquiry_email}, {enquiry_phone}) asked about {trip_title}:\n\n{enquiry_message}",
					'wpinterface-travel'
				),
			),
		);

		/**
		 * Filters the email templates.
		 *
		 * @param array $definitions Templates by ID.
		 */
		return (array) apply_filters( 'wpitravel_email_templates', $definitions );
	}

	/**
	 * Default stored settings of every template.
	 *
	 * @return array<string, array{enabled: bool, subject: string, body: string}>
	 */
	public static function default_settings(): array {
		$settings = array();

		foreach ( self::definitions() as $id => $definition ) {
			$settings[ $id ] = array(
				'enabled' => true,
				'subject' => $definition['subject'],
				'body'    => $definition['body'],
			);
		}

		return $settings;
	}

	/**
	 * One template's definition merged with its stored settings.
	 *
	 * @param string $id Template ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$definition = self::definitions()[ $id ] ?? null;

		if ( ! $definition ) {
			return null;
		}

		$stored = (array) wpitravel()->options()->get( 'emails.templates.' . $id, array() );

		return array_merge( $definition, array( 'enabled' => true ), array_filter( $stored, static fn( $value ) => '' !== $value ) );
	}
}
