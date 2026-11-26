<?php
/**
 * Regression tests for the pre-release security review.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Booking\Coupons;
use WPInterface\Travel\Enquiries\Enquiries;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Payments\PaymentRepository;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Privacy\PersonalData;

/**
 * Each test pins one finding of the review.
 */
class SecurityTest extends WP_UnitTestCase {

	use BookingFixtures;
	use PaymentFixtures;

	/**
	 * Booking emails sent during a test (template IDs).
	 *
	 * @var string[]
	 */
	private array $emails = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking( 50 );
		$this->emails = array();
		add_filter( 'wpitravel_send_booking_email', array( $this, 'spy_email' ), 99, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'wpitravel_send_booking_email', array( $this, 'spy_email' ), 99 );
		remove_filter( 'pre_http_request', array( $this, 'fake_paypal' ) );
		Gateways::reset();
		parent::tear_down();
	}

	/**
	 * Records booking emails.
	 *
	 * @param bool   $send     Send.
	 * @param string $template Template.
	 */
	public function spy_email( $send, $template ): bool {
		$this->emails[] = (string) $template;

		return (bool) $send;
	}

	/**
	 * A PayPal booking and its pending payment.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function paypal_booking(): array {
		$this->set_up_payments( array( 'paypal' => $this->paypal_settings() ) );
		$this->paypal_order_created( 'ORDER-9' );
		$response = $this->book( $this->hold(), array( 'gateway' => 'paypal' ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$booking = ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] );

		return array( $booking, ( new PaymentRepository() )->for_booking( $booking['id'] )[0] );
	}

	public function test_closed_paypal_payment_is_never_captured(): void {
		list( $booking, $payment ) = $this->paypal_booking();
		$this->paypal_capture( 'ORDER-9', 'CAP-9', '200.00' );
		( new PaymentService() )->close_pending( $payment['id'], 'cancelled', 'Replaced.' );

		$result = Gateways::get( 'paypal' )->handle_return(
			array(
				'token'   => 'ORDER-9',
				'booking' => $booking['booking_key'],
			)
		);

		$this->assertSame( 'failed', $result );
		$this->assertSame( array(), $this->paypal_calls( 'POST /v2/checkout/orders/ORDER-9/capture' ), 'No money is taken.' );
	}

	public function test_cancelled_booking_is_not_captured(): void {
		list( $booking ) = $this->paypal_booking();
		$this->paypal_capture( 'ORDER-9', 'CAP-9', '200.00' );
		( new BookingService() )->set_status( $booking['id'], 'cancelled' );

		$this->assertSame(
			'failed',
			Gateways::get( 'paypal' )->handle_return(
				array(
					'token'   => 'ORDER-9',
					'booking' => $booking['booking_key'],
				)
			)
		);
		$this->assertSame( array(), $this->paypal_calls( 'POST /v2/checkout/orders/ORDER-9/capture' ) );
	}

	public function test_money_arriving_late_is_recorded_for_staff(): void {
		list( $booking, $payment ) = $this->paypal_booking();
		( new PaymentService() )->close_pending( $payment['id'], 'cancelled', 'Timed out.' );
		( new BookingService() )->set_status( $booking['id'], 'cancelled' );

		$result = ( new PaymentService() )->complete( $payment['id'], 'CAP-LATE', '200.00', 'USD' );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( '200.00', ( new BookingRepository() )->find( $booking['id'] )['amount_paid'] );
		$this->assertContains( 'payment_needs_review', wp_list_pluck( ( new BookingRepository() )->history( $booking['id'] ), 'event' ) );
	}

	public function test_capture_in_progress_is_not_cancelled_as_abandoned(): void {
		list( $booking, $payment ) = $this->paypal_booking();
		( new PaymentRepository() )->update( $payment['id'], array( 'gateway_data' => array( 'capture_id' => 'CAP-PENDING' ) ) );

		global $wpdb;
		$wpdb->update( Schema::table( 'bookings' ), array( 'created' => '2020-01-01 00:00:00' ), array( 'id' => $booking['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		$this->assertSame( 0, ( new PaymentService() )->cancel_abandoned() );
		$this->assertSame( 'pending', ( new BookingRepository() )->find( $booking['id'] )['status'] );
	}

	public function test_one_visitor_cannot_hold_every_seat(): void {
		$this->hold( array( 'adult' => 6 ) );
		$this->hold( array( 'adult' => 6 ) );
		$this->hold( array( 'adult' => 6 ) );

		$response = $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 3 ) ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'wpitravel_too_many_held', $response->get_data()['code'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 201, $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 3 ) ) )->get_status(), 'Admins testing checkout are not limited.' );
	}

	public function test_lookup_emails_a_link_instead_of_answering_with_it(): void {
		$booking      = ( new BookingRepository() )->find_by_key( $this->book( $this->hold() )->get_data()['booking_key'] );
		$this->emails = array();

		$found = $this->api(
			'POST',
			'/bookings/lookup',
			array(
				'number' => $booking['number'],
				'email'  => 'ada@example.com',
			)
		);
		$wrong = $this->api(
			'POST',
			'/bookings/lookup',
			array(
				'number' => $booking['number'],
				'email'  => 'attacker@example.com',
			)
		);

		$this->assertSame( 200, $found->get_status() );
		$this->assertArrayNotHasKey( 'booking_key', $found->get_data() );
		$this->assertArrayNotHasKey( 'token', $found->get_data() );
		$this->assertSame( $found->get_data(), $wrong->get_data(), 'Same answer either way.' );
		$this->assertSame( array( 'customer_booking_link' ), $this->emails );
	}

	public function test_guest_cannot_rewrite_an_existing_customer(): void {
		$this->book( $this->hold( array( 'adult' => 1 ) ) );
		$this->book(
			$this->hold( array( 'adult' => 1 ) ),
			array(
				'customer' => array(
					'email'      => 'ada@example.com',
					'first_name' => 'Mallory',
					'last_name'  => 'Evil',
					'phone'      => '+1 555 0000',
				),
			)
		);

		$customer = ( new BookingRepository() )->customer( array( 'customer_id' => $this->customer_id( 'ada@example.com' ) ) );
		$this->assertSame( 'Ada', $customer['first_name'] );
		$this->assertSame( '+44 20 1234', $customer['phone'] );
	}

	/**
	 * Customer ID by email.
	 *
	 * @param string $email Email.
	 */
	private function customer_id( string $email ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', Schema::table( 'customers' ), $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test.
	}

	public function test_reopening_a_booking_takes_its_coupon_use_again(): void {
		$coupon   = $this->coupon(
			array(
				'code'          => 'ONCE',
				'discount_type' => 'percent',
				'amount'        => '10',
				'usage_limit'   => 5,
			)
		);
		$response = $this->book( $this->hold(), array( 'coupon' => 'ONCE' ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$booking = ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] );
		$service = new BookingService();

		$service->set_status( $booking['id'], 'cancelled' );
		$this->assertSame( 0, ( new Coupons() )->find( $coupon['id'] )['usage_count'] );

		$service->set_status( $booking['id'], 'pending' );
		$this->assertSame( 1, ( new Coupons() )->find( $coupon['id'] )['usage_count'] );
	}

	public function test_password_protected_trip_cannot_be_booked(): void {
		wp_update_post(
			array(
				'ID'            => $this->trip,
				'post_password' => 'secret',
			)
		);

		$this->assertSame( 404, $this->api( 'POST', '/quote', $this->selection() )->get_status() );
		$this->assertSame( 404, $this->api( 'POST', '/holds', $this->selection() )->get_status() );
	}

	public function test_erasure_covers_reasons_and_other_bookings(): void {
		$own = ( new BookingRepository() )->find_by_key( $this->book( $this->hold() )->get_data()['booking_key'] );
		( new BookingService() )->set_status( $own['id'], 'cancel_requested', 'My mother is ill' );

		$other = $this->book(
			$this->hold(),
			array(
				'customer'  => array(
					'email'      => 'friend@example.com',
					'first_name' => 'Friend',
					'last_name'  => 'Payer',
				),
				'travelers' => array(
					array(
						'first_name' => 'Friend',
						'last_name'  => 'Payer',
					),
					array(
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
						'email'      => 'ada@example.com',
					),
				),
			)
		);
		$this->assertSame( 201, $other->get_status(), wp_json_encode( $other->get_data() ) );

		$export = ( new PersonalData() )->export( 'ada@example.com' );
		$this->assertContains( 'wpitravel-travelers', wp_list_pluck( $export['data'], 'group_id' ) );
		$this->assertStringContainsString( 'My mother is ill', wp_json_encode( $export['data'] ) );

		( new PersonalData() )->erase( 'ada@example.com' );

		$this->assertNotContains( 'My mother is ill', wp_list_pluck( ( new BookingRepository() )->history( $own['id'] ), 'message' ) );

		$friend = ( new BookingRepository() )->find_by_key( $other->get_data()['booking_key'] );
		$names  = wp_list_pluck( ( new BookingRepository() )->travelers( $friend['id'] ), 'first_name' );
		$this->assertNotContains( 'Ada', $names );
		$this->assertContains( 'Friend', $names, 'Other people stay.' );
	}

	public function test_enquiry_auto_reply_does_not_echo_the_message(): void {
		$sent = array();
		$spy  = static function ( $args ) use ( &$sent ) {
			$sent[] = $args;
			return $args;
		};
		add_filter( 'wp_mail', $spy );

		$enquiries = new Enquiries();
		foreach ( array( 1, 2 ) as $round ) {
			$enquiries->create(
				array(
					'name'    => 'Win a prize at evil.example',
					'email'   => 'victim@example.com',
					'message' => 'Click evil.example now ' . $round,
					'trip_id' => $this->trip,
				)
			);
		}

		remove_filter( 'wp_mail', $spy );

		$to_victim = array_values( array_filter( $sent, static fn( $mail ) => in_array( 'victim@example.com', (array) $mail['to'], true ) ) );
		$this->assertCount( 1, $to_victim, 'One auto-reply per address in 10 minutes.' );
		$this->assertStringNotContainsString( 'evil.example', $to_victim[0]['message'] . $to_victim[0]['subject'] );
	}
}
