<?php
/**
 * Emails: triggers, rendering, scheduled emails, admin endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Emails\Triggers;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Payments\PaymentService;

/**
 * Uses the core test suite's mock mailer.
 */
class EmailsTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		update_option( 'admin_email', 'office@example.com' );
		reset_phpmailer_instance();
	}

	/**
	 * Sent emails: list of [ to, subject, body ].
	 *
	 * @return list<array{0: string, 1: string, 2: string}>
	 */
	private function sent(): array {
		return array_map(
			static fn( array $mail ): array => array( $mail['to'][0][0], $mail['subject'], $mail['body'] ),
			tests_retrieve_phpmailer_instance()->mock_sent
		);
	}

	/**
	 * Creates a booking and returns it, then clears sent mail.
	 *
	 * @param array<string, mixed> $customer Customer overrides.
	 * @return array<string, mixed>
	 */
	private function new_booking( array $customer = array() ): array {
		$extra = $customer ? array(
			'customer' => array_merge(
				array(
					'email'      => 'ada@example.com',
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
				),
				$customer
			),
		) : array();

		$booking = ( new BookingRepository() )->find_by_key( $this->book( $this->hold(), $extra )->get_data()['booking_key'] );
		reset_phpmailer_instance();

		return $booking;
	}

	public function test_new_booking_emails_customer_and_staff(): void {
		$response = $this->book( $this->hold() );
		$booking  = ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] );
		$sent     = $this->sent();

		$this->assertCount( 2, $sent );
		$this->assertSame( 'ada@example.com', $sent[0][0] );
		$this->assertSame( 'We received your booking ' . $booking['number'], $sent[0][1] );
		$this->assertStringContainsString( 'Hi Ada', $sent[0][2] );
		$this->assertStringContainsString( 'Lake Trek', $sent[0][2] );
		$this->assertStringContainsString( '$200.00', $sent[0][2], 'The summary table shows the total.' );
		$this->assertStringContainsString( 'token=', $sent[0][2], 'The booking link is signed.' );

		$this->assertSame( 'office@example.com', $sent[1][0] );
		$this->assertStringStartsWith( 'New booking ' . $booking['number'], $sent[1][1] );

		$history = array_column( ( new BookingRepository() )->history( $booking['id'] ), 'event' );
		$this->assertSame( 2, count( array_keys( $history, 'email_sent', true ) ) );
	}

	public function test_customer_text_is_escaped(): void {
		$this->new_booking( array( 'first_name' => '<script>x</script>Eve' ) );
		$this->book(
			$this->hold( array( 'adult' => 1 ) ),
			array(
				'customer' => array(
					'email'      => 'eve@example.com',
					'first_name' => '<b>Eve</b>',
					'last_name'  => 'Doe',
				),
				'note'     => '<img src=x onerror=alert(1)> please',
			)
		);

		foreach ( $this->sent() as $mail ) {
			$this->assertStringNotContainsString( '<img', $mail[2] );
			$this->assertStringNotContainsString( '<b>Eve', $mail[2] );
		}
	}

	public function test_turned_off_templates_are_not_sent(): void {
		wpitravel()->options()->update( array( 'emails' => array( 'templates' => array( 'admin_new_booking' => array( 'enabled' => false ) ) ) ) );

		$this->book( $this->hold() );

		$this->assertSame( array( 'ada@example.com' ), array_column( $this->sent(), 0 ) );
	}

	public function test_custom_staff_recipients_and_edited_text(): void {
		wpitravel()->options()->update(
			array(
				'emails' => array(
					'admin_recipients' => 'sales@example.com, ops@example.com',
					'templates'        => array(
						'customer_booking_received' => array(
							'subject' => 'Thanks {customer_first_name}! Ref {booking_number}',
							'body'    => 'Total: {total}',
						),
					),
				),
			)
		);

		$this->book( $this->hold() );
		$mails = tests_retrieve_phpmailer_instance()->mock_sent;

		$this->assertMatchesRegularExpression( '/^Thanks Ada! Ref TRV-\d+$/', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Total: $200.00', $mails[0]['body'] );
		$this->assertSame( array( 'sales@example.com', 'ops@example.com' ), array_column( $mails[1]['to'], 0 ) );
	}

	public function test_full_payment_sends_one_confirmation_not_two_emails(): void {
		$booking = $this->new_booking();

		( new PaymentService() )->record_manual( $booking['id'], '200', 'manual' );

		$subjects = array_column( $this->sent(), 1 );
		$this->assertContains( 'Your booking ' . $booking['number'] . ' is confirmed', $subjects );
		$this->assertNotContains( 'Payment received for booking ' . $booking['number'], $subjects, 'The confirmation covers it.' );
		$this->assertContains( 'Payment of $200.00 for ' . $booking['number'], $subjects, 'Staff are told about the payment.' );
	}

	public function test_partial_payment_sends_a_receipt(): void {
		$booking = $this->new_booking();

		( new PaymentService() )->record_manual( $booking['id'], '50', 'manual' );

		$receipt = array_values( array_filter( $this->sent(), static fn( $mail ) => 'ada@example.com' === $mail[0] ) );
		$this->assertCount( 1, $receipt );
		$this->assertStringContainsString( 'We received $50.00', $receipt[0][2] );
		$this->assertStringContainsString( 'Balance: $150.00', $receipt[0][2] );
	}

	public function test_cancellation_and_cancellation_request_emails(): void {
		$booking = $this->new_booking();
		$service = new BookingService();

		$service->set_status( $booking['id'], 'cancel_requested', 'Flight cancelled' );
		$request = $this->sent();
		$this->assertSame( 'office@example.com', $request[0][0] );
		$this->assertStringContainsString( 'Flight cancelled', $request[0][2] );

		reset_phpmailer_instance();
		$service->set_status( $booking['id'], 'cancelled' );
		$this->assertSame( 'Your booking ' . $booking['number'] . ' was cancelled', $this->sent()[0][1] );
	}

	public function test_daily_reminders_reviews_and_completion(): void {
		global $wpdb;

		update_post_meta( $this->trip, '_wpitravel_duration', array( 'days' => 3 ) );

		$soon = $this->new_booking();
		$past = $this->new_booking();
		$wpdb->update(
			Schema::table( 'bookings' ),
			array(
				'status'     => 'confirmed',
				'start_date' => '2030-04-28',
			),
			array( 'id' => $soon['id'] )
		);
		$wpdb->update(
			Schema::table( 'bookings' ),
			array(
				'status'     => 'confirmed',
				'start_date' => '2030-04-20',
			),
			array( 'id' => $past['id'] )
		);

		$triggers = new Triggers();
		$result   = $triggers->run_daily( '2030-04-26' );

		$this->assertSame( 1, $result['reminders'], 'Departs in 2 days, within the 3-day reminder.' );
		$this->assertSame( 1, $result['reviews'], 'Ended 2030-04-22, more than 1 day ago.' );
		$this->assertSame( 1, $result['completed'] );
		$this->assertSame( 'completed', ( new BookingRepository() )->find( $past['id'] )['status'] );

		$again = $triggers->run_daily( '2030-04-27' );
		$this->assertSame( 0, $again['reminders'] + $again['reviews'], 'Scheduled emails go out once.' );

		$subjects = array_column( $this->sent(), 1 );
		$this->assertContains( 'Your trip Lake Trek starts on ' . wp_date( (string) get_option( 'date_format' ), strtotime( '2030-04-28 12:00 UTC' ) ), $subjects );
		$this->assertContains( 'How was Lake Trek?', $subjects );
	}

	public function test_preview_test_and_resend_endpoints(): void {
		$booking = $this->new_booking();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->api( 'POST', '/emails/customer_booking_received/preview' )->get_status() );

		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'boss@example.com',
			)
		);
		wp_set_current_user( $admin );

		$preview = $this->api( 'POST', '/emails/customer_booking_confirmed/preview', array( 'subject' => 'Draft {booking_number}' ) )->get_data();
		$this->assertSame( 'Draft TRV-1042', $preview['subject'], 'Previews use sample data and unsaved edits.' );
		$this->assertStringContainsString( '<html', $preview['html'] );

		$this->assertSame( 'boss@example.com', $this->api( 'POST', '/emails/admin_new_enquiry/test' )->get_data()['sent_to'] );

		reset_phpmailer_instance();
		$this->assertSame( 200, $this->api( 'POST', "/bookings/{$booking['id']}/emails", array( 'template' => 'customer_booking_received' ) )->get_status() );
		$this->assertSame( 'ada@example.com', $this->sent()[0][0] );
		$this->assertSame( 400, $this->api( 'POST', "/bookings/{$booking['id']}/emails", array( 'template' => 'admin_new_enquiry' ) )->get_status() );
	}
}
