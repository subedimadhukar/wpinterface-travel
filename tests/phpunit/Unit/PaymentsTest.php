<?php
/**
 * Payments: gateways, PayPal, ledger, refunds.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Departures\DepartureRepository;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Payments\Gateways;
use WPInterface\Travel\Payments\PaymentRepository;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Rest\SettingsController;

/**
 * Covers the payment flows. PayPal is answered by a fake API (PaymentFixtures).
 */
class PaymentsTest extends WP_UnitTestCase {

	use BookingFixtures;
	use PaymentFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		$this->set_up_payments(
			array(
				'paypal'        => $this->paypal_settings(),
				'bank_transfer' => array(
					'enabled'      => true,
					'instructions' => "IBAN DE00 1234\nBank: Example Bank",
				),
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_paypal' ) );
		Gateways::reset();
		parent::tear_down();
	}

	/**
	 * Books 2 adults (200.00 USD) with a gateway and returns the booking.
	 *
	 * @param string $gateway Gateway ID.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} Booking and the payment action.
	 */
	private function book_with( string $gateway ): array {
		$response = $this->book( $this->hold(), array( 'gateway' => $gateway ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return array(
			( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] ),
			$response->get_data()['payment'],
		);
	}

	/**
	 * A booking paid with PayPal (order ORDER-1, capture CAPTURE-1).
	 *
	 * @return array<string, mixed>
	 */
	private function paid_with_paypal(): array {
		$this->paypal_order_created( 'ORDER-1' );
		$this->paypal_capture( 'ORDER-1', 'CAPTURE-1', '200.00' );

		list( $booking ) = $this->book_with( 'paypal' );

		$this->assertSame(
			'paid',
			Gateways::get( 'paypal' )->handle_return(
				array(
					'token'   => 'ORDER-1',
					'booking' => $booking['booking_key'],
				)
			)
		);

		return ( new BookingRepository() )->find( $booking['id'] );
	}

	public function test_bank_transfer_shows_instructions_and_waits_for_staff(): void {
		list( $booking, $payment ) = $this->book_with( 'bank_transfer' );

		$this->assertSame( 'none', $payment['action'] );
		$this->assertStringContainsString( 'IBAN DE00 1234', $payment['instructions'] );
		$this->assertStringContainsString( $booking['number'], $payment['instructions'], 'The booking number is the payment reference.' );
		$this->assertSame( 'bank_transfer', $booking['payment_method'] );
		$this->assertSame( array(), ( new PaymentRepository() )->for_booking( $booking['id'] ), 'Nothing is recorded until money arrives.' );
	}

	public function test_a_payment_method_must_be_chosen_before_seats_are_taken(): void {
		$key = $this->hold();

		foreach ( array( '', 'stripe', 'pay_later' ) as $gateway ) {
			$response = $this->book( $key, array( 'gateway' => $gateway ) );
			$this->assertSame( 'wpitravel_gateway_unavailable', $response->get_data()['code'], $gateway );
		}

		$this->assertSame( 0, ( new DepartureRepository() )->find_slot( $this->trip, '2030-05-01', '' )['seats_booked'], 'Rejected bookings take no seats.' );
		$this->assertSame( 201, $this->book( $key, array( 'gateway' => 'bank_transfer' ) )->get_status(), 'The hold is still usable.' );
	}

	public function test_paypal_checkout_redirects_and_return_captures_once(): void {
		$this->paypal_order_created( 'ORDER-1' );
		$this->paypal_capture( 'ORDER-1', 'CAPTURE-1', '200.00' );

		list( $booking, $payment ) = $this->book_with( 'paypal' );

		$this->assertSame( 'redirect', $payment['action'] );
		$this->assertSame( 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1', $payment['url'] );

		$order = json_decode( $this->paypal_calls( 'POST /v2/checkout/orders' )[0], true );
		$unit  = $order['purchase_units'][0];
		$this->assertSame( 'CAPTURE', $order['intent'] );
		$this->assertSame(
			array(
				'currency_code' => 'USD',
				'value'         => '200.00',
			),
			$unit['amount']
		);
		$this->assertStringContainsString( $booking['booking_key'], $order['payment_source']['paypal']['experience_context']['return_url'] );

		$ledger = ( new PaymentRepository() )->for_booking( $booking['id'] );
		$this->assertCount( 1, $ledger );
		$this->assertSame( 'pending', $ledger[0]['status'] );
		$this->assertSame( 'ORDER-1', $ledger[0]['reference'] );
		$this->assertSame( 'test', $ledger[0]['mode'], 'Sandbox payments are test payments.' );
		$this->assertSame( (string) $ledger[0]['id'], $unit['custom_id'] );

		$paypal = Gateways::get( 'paypal' );
		$return = array(
			'token'   => 'ORDER-1',
			'booking' => $booking['booking_key'],
		);

		$this->assertSame( 'paid', $paypal->handle_return( $return ) );
		$this->assertSame( 'paid', $paypal->handle_return( $return ), 'Reloading the return page is harmless.' );
		$this->assertCount( 1, $this->paypal_calls( 'POST /v2/checkout/orders/ORDER-1/capture' ), 'Captured once.' );

		$booking = ( new BookingRepository() )->find( $booking['id'] );
		$this->assertSame( 'paid', $booking['payment_status'] );
		$this->assertSame( 'confirmed', $booking['status'], 'Fully paid bookings are confirmed.' );
		$this->assertSame( '200.00', $booking['amount_paid'] );
		$this->assertSame( '0.00', $booking['balance'] );

		$ledger = ( new PaymentRepository() )->for_booking( $booking['id'] );
		$this->assertCount( 1, $ledger );
		$this->assertSame( 'CAPTURE-1', $ledger[0]['transaction_id'] );
	}

	public function test_return_checks_the_booking_and_records_cancellation(): void {
		$this->paypal_order_created( 'ORDER-1' );

		list( $booking ) = $this->book_with( 'paypal' );
		$paypal          = Gateways::get( 'paypal' );

		$this->assertSame(
			'failed',
			$paypal->handle_return(
				array(
					'token'   => 'ORDER-1',
					'booking' => str_repeat( 'a', 32 ),
				)
			),
			'An order cannot be captured from another booking\'s link.'
		);
		$this->assertCount( 0, $this->paypal_calls( 'POST /v2/checkout/orders/ORDER-1/capture' ) );

		$this->assertSame(
			'cancelled',
			$paypal->handle_return(
				array(
					'token'   => 'ORDER-1',
					'booking' => $booking['booking_key'],
					'cancel'  => '1',
				)
			)
		);
		$this->assertSame( 'cancelled', ( new PaymentRepository() )->for_booking( $booking['id'] )[0]['status'] );
		$this->assertSame( 'unpaid', ( new BookingRepository() )->find( $booking['id'] )['payment_status'] );
	}

	public function test_currency_mismatch_is_rejected(): void {
		$this->paypal_order_created( 'ORDER-1' );
		$this->paypal_capture( 'ORDER-1', 'CAPTURE-1', '200.00', 'EUR' );

		list( $booking ) = $this->book_with( 'paypal' );

		$this->assertSame(
			'failed',
			Gateways::get( 'paypal' )->handle_return(
				array(
					'token'   => 'ORDER-1',
					'booking' => $booking['booking_key'],
				)
			)
		);
		$this->assertSame( 'failed', ( new PaymentRepository() )->for_booking( $booking['id'] )[0]['status'] );
		$this->assertSame( 'unpaid', ( new BookingRepository() )->find( $booking['id'] )['payment_status'] );
	}

	/**
	 * Sends a webhook with PayPal's headers.
	 *
	 * @param array<string, mixed> $event Event.
	 */
	private function webhook( array $event ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wpinterface-travel/v1/payments/paypal/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( array( 'auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time' ) as $header ) {
			$request->set_header( 'paypal_' . $header, 'value-' . $header );
		}
		$request->set_body( wp_json_encode( $event ) );

		return rest_do_request( $request );
	}

	public function test_webhooks_need_a_valid_signature(): void {
		$this->paypal_order_created( 'ORDER-1' );
		list( $booking ) = $this->book_with( 'paypal' );

		$event = array(
			'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
			'resource'   => array(
				'id'                 => 'CAPTURE-9',
				'status'             => 'COMPLETED',
				'amount'             => array(
					'currency_code' => 'USD',
					'value'         => '200.00',
				),
				'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'ORDER-1' ) ),
			),
		);

		$this->paypal_routes['POST /v1/notifications/verify-webhook-signature'] = array( 200, array( 'verification_status' => 'FAILURE' ) );
		$this->assertSame( 400, $this->webhook( $event )->get_status() );
		$this->assertSame( 'unpaid', ( new BookingRepository() )->find( $booking['id'] )['payment_status'], 'A forged webhook changes nothing.' );

		$this->paypal_routes['POST /v1/notifications/verify-webhook-signature'] = array( 200, array( 'verification_status' => 'SUCCESS' ) );
		$this->assertSame( 200, $this->webhook( $event )->get_status() );
		$this->assertSame( 200, $this->webhook( $event )->get_status(), 'Repeated deliveries are accepted.' );

		$sent = $this->paypal_calls( 'POST /v1/notifications/verify-webhook-signature' )[1];
		$this->assertStringContainsString( '"webhook_id":"WH-1"', $sent );
		$this->assertStringContainsString( '"webhook_event":' . wp_json_encode( $event ), $sent, 'The event is passed on exactly as received.' );

		$booking = ( new BookingRepository() )->find( $booking['id'] );
		$this->assertSame( 'paid', $booking['payment_status'] );
		$this->assertCount( 1, ( new PaymentRepository() )->for_booking( $booking['id'] ), 'No double counting.' );
	}

	public function test_webhook_without_configured_id_is_refused(): void {
		wpitravel()->options()->update( array( 'payments' => array( 'gateways' => array( 'paypal' => array( 'webhook_id' => '' ) ) ) ) );
		Gateways::reset();

		$this->assertSame( 400, $this->webhook( array( 'event_type' => 'PAYMENT.CAPTURE.COMPLETED' ) )->get_status() );
		$this->assertCount( 0, $this->paypal_calls( 'POST /v1/notifications/verify-webhook-signature' ) );
	}

	public function test_partial_payments_confirm_only_when_fully_paid(): void {
		list( $booking ) = $this->book_with( 'bank_transfer' );
		$service         = new PaymentService();

		$service->record_manual( $booking['id'], '80', 'bank_transfer', 'Deposit', 'BANK-REF-1' );
		$booking = ( new BookingRepository() )->find( $booking['id'] );
		$this->assertSame( 'partial', $booking['payment_status'] );
		$this->assertSame( 'pending', $booking['status'] );
		$this->assertSame( '120.00', $booking['balance'] );

		$duplicate = $service->record_manual( $booking['id'], '80', 'bank_transfer', '', 'BANK-REF-1' );
		$this->assertSame( 'wpitravel_duplicate_transaction', $duplicate->get_error_code() );

		$service->record_manual( $booking['id'], '120', 'bank_transfer' );
		$booking = ( new BookingRepository() )->find( $booking['id'] );
		$this->assertSame( 'paid', $booking['payment_status'] );
		$this->assertSame( 'confirmed', $booking['status'] );
	}

	public function test_refunds_through_paypal_and_by_hand(): void {
		$booking = $this->paid_with_paypal();
		$payment = ( new PaymentRepository() )->for_booking( $booking['id'] )[0];
		$service = new PaymentService();

		$too_much = $service->refund( $payment['id'], '250', 'Too much' );
		$this->assertSame( 'wpitravel_refund_amount', $too_much->get_error_code() );

		$this->paypal_routes['POST /v2/payments/captures/CAPTURE-1/refund'] = array(
			201,
			array(
				'id'     => 'REFUND-1',
				'status' => 'COMPLETED',
			),
		);

		$refund = $service->refund( $payment['id'], '50', 'Child did not travel' );
		$this->assertSame( 'REFUND-1', $refund['transaction_id'] );
		$this->assertSame( $payment['id'], $refund['parent_id'] );

		$sent = json_decode( $this->paypal_calls( 'POST /v2/payments/captures/CAPTURE-1/refund' )[0], true );
		$this->assertSame( '50.00', $sent['amount']['value'] );

		$booking = ( new BookingRepository() )->find( $booking['id'] );
		$this->assertSame( 'partially_refunded', $booking['payment_status'] );
		$this->assertSame( '150.00', $booking['amount_paid'] );

		// PayPal also notifies about the same refund: recorded once.
		$this->paypal_routes['POST /v1/notifications/verify-webhook-signature'] = array( 200, array( 'verification_status' => 'SUCCESS' ) );
		$this->webhook(
			array(
				'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
				'resource'   => array(
					'id'     => 'REFUND-1',
					'status' => 'COMPLETED',
					'amount' => array(
						'value'         => '50.00',
						'currency_code' => 'USD',
					),
					'links'  => array(
						array(
							'rel'  => 'up',
							'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-1',
						),
					),
				),
			)
		);
		$this->assertCount( 2, ( new PaymentRepository() )->for_booking( $booking['id'] ) );

		$service->refund( $payment['id'], '150', 'Refunded by bank transfer', true );
		$this->assertCount( 1, $this->paypal_calls( 'POST /v2/payments/captures/CAPTURE-1/refund' ), 'A manual refund does not call PayPal.' );
		$this->assertSame( 'refunded', ( new BookingRepository() )->find( $booking['id'] )['payment_status'] );
	}

	public function test_staff_routes(): void {
		list( $booking ) = $this->book_with( 'bank_transfer' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->api( 'GET', "/bookings/{$booking['id']}/payments" )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->api( 'POST', "/bookings/{$booking['id']}/payments", array( 'amount' => '10' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$recorded = $this->api(
			'POST',
			"/bookings/{$booking['id']}/payments",
			array(
				'amount' => '200',
				'method' => 'bank_transfer',
				'note'   => 'Received 2 May',
			)
		);
		$this->assertSame( 201, $recorded->get_status() );

		$ledger = $this->api( 'GET', "/bookings/{$booking['id']}/payments" )->get_data();
		$this->assertSame( 'paid', $ledger['payment_status'] );
		$this->assertCount( 1, $ledger['payments'] );

		$refund = $this->api(
			'POST',
			"/bookings/{$booking['id']}/refunds",
			array(
				'payment_id' => $recorded->get_data()['id'],
				'amount'     => '200',
				'reason'     => 'Trip cancelled',
			)
		);
		$this->assertSame( 201, $refund->get_status() );
		$this->assertSame( 'refunded', $this->api( 'GET', "/bookings/{$booking['id']}/payments" )->get_data()['payment_status'] );

		$other = $this->api(
			'POST',
			'/bookings/' . ( $booking['id'] + 99 ) . '/refunds',
			array(
				'payment_id' => $recorded->get_data()['id'],
				'amount'     => '1',
			)
		);
		$this->assertSame( 404, $other->get_status(), 'A payment can only be refunded on its own booking.' );
	}

	public function test_abandoned_online_bookings_free_their_seats(): void {
		global $wpdb;

		$this->paypal_order_created( 'ORDER-1' );
		list( $online ) = $this->book_with( 'paypal' );
		list( $bank )   = $this->book_with( 'bank_transfer' );
		$old            = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );

		$wpdb->update( Schema::table( 'bookings' ), array( 'created' => $old ), array( 'id' => $online['id'] ) );
		$wpdb->update( Schema::table( 'bookings' ), array( 'created' => $old ), array( 'id' => $bank['id'] ) );

		$this->assertSame( 1, ( new PaymentService() )->cancel_abandoned() );
		$this->assertSame( 'cancelled', ( new BookingRepository() )->find( $online['id'] )['status'] );
		$this->assertSame( 'cancelled', ( new PaymentRepository() )->for_booking( $online['id'] )[0]['status'] );
		$this->assertSame( 'pending', ( new BookingRepository() )->find( $bank['id'] )['status'], 'Bank transfers have days to arrive.' );
		$this->assertSame( 2, ( new DepartureRepository() )->find_slot( $this->trip, '2030-05-01', '' )['seats_booked'] );
	}

	public function test_customers_can_retry_payment(): void {
		$this->paypal_order_created( 'ORDER-1' );
		list( $booking ) = $this->book_with( 'paypal' );

		$this->paypal_order_created( 'ORDER-2' );
		$retry = $this->api( 'POST', "/bookings/{$booking['booking_key']}/pay", array( 'gateway' => 'paypal' ) );

		$this->assertSame( 200, $retry->get_status() );
		$this->assertSame( 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-2', $retry->get_data()['payment']['url'] );
		$this->assertSame( array( 'cancelled', 'pending' ), array_column( ( new PaymentRepository() )->for_booking( $booking['id'] ), 'status' ) );

		$bank = $this->api( 'POST', "/bookings/{$booking['booking_key']}/pay", array( 'gateway' => 'bank_transfer' ) );
		$this->assertSame( 'none', $bank->get_data()['payment']['action'] );
		$this->assertSame( 'bank_transfer', ( new BookingRepository() )->find( $booking['id'] )['payment_method'] );

		$this->assertSame( 404, $this->api( 'POST', '/bookings/' . str_repeat( 'f', 32 ) . '/pay', array( 'gateway' => 'paypal' ) )->get_status() );
	}

	public function test_paypal_availability(): void {
		$paypal = Gateways::get( 'paypal' );

		$this->assertTrue( $paypal->is_available( 'USD' ) );
		$this->assertFalse( $paypal->is_available( 'NPR' ), 'PayPal does not support every currency.' );

		wpitravel()->options()->update( array( 'payments' => array( 'gateways' => array( 'paypal' => array( 'client_secret' => '' ) ) ) ) );
		$this->assertFalse( Gateways::get( 'paypal' )->is_available( 'USD' ), 'Not without credentials.' );
	}

	public function test_secrets_never_reach_the_browser(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = $this->api( 'GET', '/settings' )->get_data()['settings'];
		$this->assertSame( SettingsController::SECRET_MASK, $settings['payments']['gateways']['paypal']['client_secret'] );

		// Saving the form as shown keeps the stored secret.
		$this->api( 'POST', '/settings', array( 'payments' => $settings['payments'] ) );
		wpitravel()->options()->flush();
		$this->assertSame( 'client-secret', wpitravel()->options()->get( 'payments.gateways.paypal.client_secret' ) );

		$settings['payments']['gateways']['paypal']['client_secret'] = 'new-secret';
		$this->api( 'POST', '/settings', array( 'payments' => $settings['payments'] ) );
		wpitravel()->options()->flush();
		$this->assertSame( 'new-secret', wpitravel()->options()->get( 'payments.gateways.paypal.client_secret' ) );
	}

	/**
	 * Two 200.00 bookings paid together; returns them.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function two_linked_bookings(): array {
		list( $first )  = $this->book_with( 'bank_transfer' );
		list( $second ) = $this->book_with( 'bank_transfer' );
		$ids            = array( (int) $first['id'], (int) $second['id'] );

		add_filter( 'wpitravel_payment_group', static fn( $group, $booking ) => in_array( (int) $booking['id'], $ids, true ) ? $ids : $group, 10, 2 );

		return array( $first, $second );
	}

	public function test_one_charge_pays_linked_bookings_and_refunds_go_through_it(): void {
		list( $first, $second ) = $this->two_linked_bookings();
		$this->paypal_order_created( 'ORDER-G' );
		$this->paypal_capture( 'ORDER-G', 'CAPTURE-G', '400.00' );

		$this->assertSame( '400.00', ( new PaymentService() )->amount_due( ( new BookingRepository() )->find( $first['id'] ) ), 'The booking page asks for both.' );

		$action = ( new PaymentService() )->start( ( new BookingRepository() )->find( $first['id'] ), 'paypal' );
		$this->assertSame( 'redirect', $action['action'] );
		$order = json_decode( $this->paypal_calls( 'POST /v2/checkout/orders' )[0], true );
		$this->assertSame( '400.00', $order['purchase_units'][0]['amount']['value'], 'One charge for both.' );

		Gateways::get( 'paypal' )->handle_return(
			array(
				'token'   => 'ORDER-G',
				'booking' => $first['booking_key'],
			)
		);

		$repository = new BookingRepository();
		foreach ( array( $first, $second ) as $booking ) {
			$fresh = $repository->find( $booking['id'] );
			$this->assertSame( 'paid', $fresh['payment_status'] );
			$this->assertSame( '200.00', $fresh['amount_paid'] );
			$this->assertSame( 'confirmed', $fresh['status'] );
			$this->assertSame( 'paypal', $fresh['payment_method'] );
		}

		$share = ( new PaymentRepository() )->for_booking( $second['id'] )[0];
		$this->assertNull( $share['transaction_id'] );
		$this->assertSame( '200.00', ( new PaymentRepository() )->for_booking( $first['id'] )[0]['amount'] );

		$this->paypal_routes['POST /v2/payments/captures/CAPTURE-G/refund'] = array(
			201,
			array(
				'id'     => 'REFUND-G',
				'status' => 'COMPLETED',
			),
		);

		$this->assertSame( 'wpitravel_refund_amount', ( new PaymentService() )->refund( $share['id'], '250' )->get_error_code(), 'Only its own share.' );

		$refund = ( new PaymentService() )->refund( $share['id'], '50', 'One traveler less' );
		$this->assertSame( 'REFUND-G', $refund['transaction_id'] );
		$this->assertSame( (int) $second['id'], (int) $refund['booking_id'] );
		$this->assertSame( 'partially_refunded', $repository->find( $second['id'] )['payment_status'] );
		$this->assertSame( 'paid', $repository->find( $first['id'] )['payment_status'] );
	}

	public function test_a_different_amount_for_linked_bookings_is_left_for_staff(): void {
		list( $first, $second ) = $this->two_linked_bookings();
		$this->paypal_order_created( 'ORDER-H' );
		$this->paypal_capture( 'ORDER-H', 'CAPTURE-H', '300.00' );

		( new PaymentService() )->start( ( new BookingRepository() )->find( $first['id'] ), 'paypal' );
		Gateways::get( 'paypal' )->handle_return(
			array(
				'token'   => 'ORDER-H',
				'booking' => $first['booking_key'],
			)
		);

		$this->assertSame( 'unpaid', ( new BookingRepository() )->find( $second['id'] )['payment_status'] );
		$this->assertContains( 'payment_needs_review', wp_list_pluck( ( new BookingRepository() )->history( $first['id'] ), 'event' ) );
	}
}
