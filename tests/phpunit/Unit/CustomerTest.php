<?php
/**
 * Customer access, cancellation requests, lookup, my bookings.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Install\Schema;

/**
 * Covers BookingAccess and the customer endpoints.
 */
class CustomerTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Booking under test.
	 *
	 * @var array<string, mixed>
	 */
	private array $booking;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		$this->booking = ( new BookingRepository() )->find_by_key( $this->book( $this->hold() )->get_data()['booking_key'] );
		wp_set_current_user( 0 );
	}

	/**
	 * GET /bookings/{key} with query args.
	 *
	 * @param array<string, string> $args Query args.
	 */
	private function view( array $args ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wpinterface-travel/v1/bookings/' . $this->booking['booking_key'] );
		$request->set_query_params( $args );

		return rest_do_request( $request );
	}

	public function test_access_needs_token_email_owner_or_staff(): void {
		$token = BookingAccess::token( $this->booking );

		$this->assertSame( 200, $this->view( array( 'token' => $token ) )->get_status() );
		$this->assertSame( 200, $this->view( array( 'email' => 'ADA@example.com' ) )->get_status(), 'Email match ignores case.' );
		$this->assertSame( 404, $this->view( array() )->get_status() );
		$this->assertSame( 404, $this->view( array( 'token' => str_repeat( '0', 32 ) ) )->get_status() );
		$this->assertSame( 404, $this->view( array( 'email' => 'someone@example.com' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 404, $this->view( array() )->get_status(), 'Other customers cannot see it.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 200, $this->view( array() )->get_status(), 'Staff can.' );
	}

	public function test_customer_view_hides_internal_data(): void {
		$data = $this->view( array( 'token' => BookingAccess::token( $this->booking ) ) )->get_data();

		$this->assertSame( $this->booking['number'], $data['number'] );
		$this->assertSame( '200.00', $data['balance'] );
		$this->assertTrue( $data['can_cancel'] );
		$this->assertFalse( $data['can_review'], 'The trip has not happened yet.' );

		$encoded = wp_json_encode( $data );
		foreach ( array( '"ip"', '"customer_id"', '"user_id"', '"departure_id"', '"locale"' ) as $key ) {
			$this->assertStringNotContainsString( $key, $encoded, $key );
		}
	}

	public function test_tokens_are_bound_to_the_booking_email(): void {
		global $wpdb;

		$token = BookingAccess::token( $this->booking );
		$wpdb->update( Schema::table( 'customers' ), array( 'email' => 'changed@example.com' ), array( 'id' => $this->booking['customer_id'] ) );

		$this->assertNotSame( $token, BookingAccess::token( $this->booking ), 'Changing the booking email invalidates old links.' );
	}

	public function test_cancellation_requests(): void {
		$token   = BookingAccess::token( $this->booking );
		$path    = '/bookings/' . $this->booking['booking_key'] . '/cancel-request';
		$request = $this->api(
			'POST',
			$path,
			array(
				'token'  => $token,
				'reason' => 'Illness',
			)
		);

		$this->assertSame( 200, $request->get_status() );
		$this->assertSame( 'cancel_requested', $request->get_data()['status'] );
		$this->assertFalse( $request->get_data()['can_cancel'] );
		$this->assertSame( 2, ( new \WPInterface\Travel\Departures\DepartureRepository() )->find_slot( $this->trip, '2030-05-01', '' )['seats_booked'], 'Seats stay held until staff decide.' );

		$this->assertSame( 409, $this->api( 'POST', $path, array( 'token' => $token ) )->get_status(), 'Only once.' );
		$this->assertSame( 404, $this->api( 'POST', $path, array( 'token' => 'wrong' ) )->get_status() );
	}

	public function test_departed_bookings_cannot_be_cancelled_online(): void {
		global $wpdb;

		$wpdb->update( Schema::table( 'bookings' ), array( 'start_date' => '2020-01-01' ), array( 'id' => $this->booking['id'] ) );

		$response = $this->api( 'POST', '/bookings/' . $this->booking['booking_key'] . '/cancel-request', array( 'token' => BookingAccess::token( $this->booking ) ) );
		$this->assertSame( 'wpitravel_cannot_cancel', $response->get_data()['code'] );
	}

	public function test_lookup_by_number_and_email(): void {
		$found = $this->api(
			'POST',
			'/bookings/lookup',
			array(
				'number' => strtolower( $this->booking['number'] ),
				'email'  => 'ada@example.com',
			)
		);
		// The link goes by email (SecurityTest); the response never holds it.
		$this->assertSame( 200, $found->get_status() );
		$this->assertArrayNotHasKey( 'token', $found->get_data() );

		$wrong_email  = $this->api(
			'POST',
			'/bookings/lookup',
			array(
				'number' => $this->booking['number'],
				'email'  => 'eve@example.com',
			)
		);
		$wrong_number = $this->api(
			'POST',
			'/bookings/lookup',
			array(
				'number' => 'TRV-999999',
				'email'  => 'ada@example.com',
			)
		);
		$this->assertSame( $found->get_data(), $wrong_email->get_data(), 'A wrong email looks like a match.' );
		$this->assertSame( $wrong_email->get_data(), $wrong_number->get_data(), 'Both mistakes look the same.' );
	}

	public function test_my_bookings_and_claiming_a_guest_booking(): void {
		$this->assertSame( 401, $this->api( 'GET', '/my/bookings' )->get_status() );

		$owner = self::factory()->user->create( array( 'user_email' => 'ada@example.com' ) );
		$other = self::factory()->user->create( array( 'user_email' => 'eve@example.com' ) );

		wp_set_current_user( $owner );
		$this->assertSame( array(), $this->api( 'GET', '/my/bookings' )->get_data(), 'Same email alone does not attach a booking.' );

		wp_set_current_user( $other );
		$this->view( array( 'token' => BookingAccess::token( $this->booking ) ) );
		$this->assertSame( 0, ( new BookingRepository() )->find( $this->booking['id'] )['user_id'], 'A link opened by another account does not attach it.' );

		wp_set_current_user( $owner );
		$this->view( array( 'token' => BookingAccess::token( $this->booking ) ) );
		$mine = $this->api( 'GET', '/my/bookings' )->get_data();
		$this->assertSame( array( $this->booking['number'] ), array_column( $mine, 'number' ), 'Opening the signed link with the matching account attaches it.' );
	}
}
