<?php
/**
 * Holds, bookings and status changes.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Booking\Coupons;
use WPInterface\Travel\Departures\DepartureRepository;
use WPInterface\Travel\Install\Schema;

/**
 * Covers the checkout endpoints end to end.
 */
class BookingFlowTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking( 4 );
	}

	/**
	 * Seats booked on the trip's departure.
	 */
	private function seats_booked(): int {
		$row = ( new DepartureRepository() )->find_slot( $this->trip, '2030-05-01', '' );

		return $row ? $row['seats_booked'] : 0;
	}

	public function test_holds_prevent_overselling(): void {
		$first = $this->hold( array( 'adult' => 3 ) );

		$second = $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 2 ) ) );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'wpitravel_not_enough_seats', $second->get_data()['code'] );

		$this->assertSame( 1, $this->availability_seats_left(), 'Customers see held seats as taken.' );

		$this->assertSame( 204, $this->api( 'DELETE', '/holds/' . $first )->get_status() );
		$this->hold( array( 'adult' => 2 ) );
	}

	/**
	 * Seats left as customers see them.
	 */
	private function availability_seats_left(): int {
		$request = new \WP_REST_Request( 'GET', "/wpinterface-travel/v1/trips/{$this->trip}/availability" );
		$request->set_query_params(
			array(
				'from' => '2030-05-01',
				'to'   => '2030-05-01',
			)
		);

		return (int) rest_do_request( $request )->get_data()['departures'][0]['seats_left'];
	}

	public function test_booking_from_hold_saves_everything(): void {
		$key      = $this->hold(
			array(
				'adult' => 2,
				'child' => 1,
			)
		);
		$response = $this->book(
			$key,
			array(
				'travelers' => array(
					array(
						'category'   => 'adult',
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
					),
					array(
						'category'   => 'child',
						'first_name' => 'Byron',
						'last_name'  => 'Lovelace',
					),
				),
				'note'      => 'Vegetarian',
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( '250.00', $data['total'] );
		$this->assertSame( 'none', $data['payment']['action'] );
		$this->assertMatchesRegularExpression( '/^TRV-\d+$/', $data['number'] );

		$repository = new BookingRepository();
		$booking    = $repository->find_by_key( $data['booking_key'] );

		$this->assertSame( 3, $booking['pax'] );
		$this->assertSame( 'unpaid', $booking['payment_status'] );
		$this->assertSame( 'standard', $booking['price_option'] );
		$this->assertSame( 'Vegetarian', $booking['customer_note'] );
		$this->assertCount( 2, $repository->items( $booking['id'] ) );
		$this->assertSame( array( 'Ada', 'Byron' ), array_column( $repository->travelers( $booking['id'] ), 'first_name' ) );
		$this->assertSame( 'created', $repository->history( $booking['id'] )[0]['event'] );
		$this->assertSame( 3, $this->seats_booked() );
		$this->assertSame( 1, $this->availability_seats_left(), 'Booked seats replace the hold, not add to it.' );
	}

	public function test_hold_is_single_use_and_expires(): void {
		global $wpdb;

		$key = $this->hold();
		$this->assertSame( 201, $this->book( $key )->get_status() );

		$again = $this->book( $key );
		$this->assertSame( 410, $again->get_status() );
		$this->assertSame( 'wpitravel_hold_expired', $again->get_data()['code'] );

		$expired = $this->hold( array( 'adult' => 1 ) );
		$wpdb->update( Schema::table( 'holds' ), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'hold_key' => $expired ) );
		$this->assertSame( 410, $this->book( $expired )->get_status() );
		$this->assertSame( 2, $this->seats_booked() );
	}

	public function test_client_cannot_change_the_price(): void {
		$key      = $this->hold();
		$response = $this->book(
			$key,
			array(
				'total' => '1.00',
				'items' => array(
					array(
						'type'  => 'pax',
						'total' => '1.00',
					),
				),
			)
		);

		$this->assertSame( '200.00', $response->get_data()['total'] );
	}

	public function test_customer_and_traveler_validation(): void {
		$key = $this->hold();

		$bad_email = $this->book(
			$key,
			array(
				'customer' => array(
					'email'      => 'not-an-email',
					'first_name' => 'A',
					'last_name'  => 'B',
				),
			)
		);
		$this->assertSame( 'customer.email', $bad_email->get_data()['data']['field'] );

		$too_many = $this->book(
			$key,
			array(
				'travelers' => array(
					array(
						'category'   => 'adult',
						'first_name' => 'One',
					),
					array(
						'category'   => 'adult',
						'first_name' => 'Two',
					),
					array(
						'category'   => 'adult',
						'first_name' => 'Three',
					),
				),
			)
		);
		$this->assertSame( 'wpitravel_invalid_traveler_count', $too_many->get_data()['code'] );

		$this->assertSame( 201, $this->book( $key )->get_status(), 'Failed attempts leave the hold usable.' );
	}

	public function test_coupon_limits_are_enforced_at_booking(): void {
		$coupon = $this->coupon(
			array(
				'code'               => 'ONCE',
				'discount_type'      => 'percent',
				'amount'             => '10',
				'per_customer_limit' => 1,
				'usage_limit'        => 2,
			)
		);

		$first = $this->book( $this->hold( array( 'adult' => 1 ) ), array( 'coupon' => 'once' ) );
		$this->assertSame( '90.00', $first->get_data()['total'] );

		$same_customer = $this->book( $this->hold( array( 'adult' => 1 ) ), array( 'coupon' => 'ONCE' ) );
		$this->assertSame( 400, $same_customer->get_status() );
		$this->assertSame( 'wpitravel_invalid_coupon', $same_customer->get_data()['code'] );

		$this->assertSame( 1, ( new Coupons() )->find( $coupon['id'] )['usage_count'] );
	}

	public function test_cancelling_releases_seats_and_coupon_use(): void {
		$coupon  = $this->coupon(
			array(
				'code'          => 'BACK',
				'discount_type' => 'fixed',
				'amount'        => '20',
				'usage_limit'   => 1,
			)
		);
		$booking = ( new BookingRepository() )->find_by_key( $this->book( $this->hold( array( 'adult' => 3 ) ), array( 'coupon' => 'BACK' ) )->get_data()['booking_key'] );
		$service = new BookingService();

		$this->assertSame( 3, $this->seats_booked() );
		$this->assertSame( 1, ( new Coupons() )->find( $coupon['id'] )['usage_count'] );

		$service->set_status( $booking['id'], 'cancelled', 'Customer asked' );
		$this->assertSame( 0, $this->seats_booked() );
		$this->assertSame( 0, ( new Coupons() )->find( $coupon['id'] )['usage_count'] );

		$this->hold( array( 'adult' => 2 ) );
		$reopen = $service->set_status( $booking['id'], 'pending' );
		$this->assertWPError( $reopen, 'Reopening fails when the seats were taken meanwhile.' );
		$this->assertSame( 'cancelled', ( new BookingRepository() )->find( $booking['id'] )['status'] );

		$history = ( new BookingRepository() )->history( $booking['id'] );
		$this->assertContains( 'status_changed', array_column( $history, 'event' ) );
	}

	public function test_guest_checkout_and_terms_settings(): void {
		$terms = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		wpitravel()->options()->update(
			array(
				'booking' => array(
					'guest_checkout' => false,
					'terms_page_id'  => $terms,
				),
			)
		);

		$key = $this->hold();
		$this->assertSame( 401, $this->book( $key )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'wpitravel_terms_required', $this->book( $key )->get_data()['code'] );

		$booked = $this->book( $key, array( 'terms_accepted' => true ) );
		$this->assertSame( 201, $booked->get_status() );
		$this->assertSame( get_current_user_id(), ( new BookingRepository() )->find_by_key( $booked->get_data()['booking_key'] )['user_id'] );
	}

	public function test_guests_can_create_an_account(): void {
		// Off by default: a guest cannot make the site create accounts.
		$this->book(
			$this->hold( array( 'adult' => 1 ) ),
			array(
				'create_account' => true,
				'customer'       => array(
					'email'      => 'uninvited@example.com',
					'first_name' => 'Un',
					'last_name'  => 'Invited',
				),
			)
		);
		$this->assertFalse( get_user_by( 'email', 'uninvited@example.com' ) );

		wpitravel()->options()->update( array( 'booking' => array( 'create_accounts' => true ) ) );
		update_option( 'default_role', 'editor' );

		$response = $this->book(
			$this->hold(),
			array(
				'create_account' => true,
				'customer'       => array(
					'email'      => 'new.traveler@example.com',
					'first_name' => 'New',
					'last_name'  => 'Traveler',
				),
			)
		);
		$user     = get_user_by( 'email', 'new.traveler@example.com' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'New', $user->first_name );
		$this->assertSame( array( 'subscriber' ), $user->roles, 'Never the site default role.' );
		$this->assertSame( $user->ID, ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] )['user_id'] );

		$existing = self::factory()->user->create( array( 'user_email' => 'taken@example.com' ) );
		$second   = $this->book(
			$this->hold( array( 'adult' => 1 ) ),
			array(
				'create_account' => true,
				'customer'       => array(
					'email'      => 'taken@example.com',
					'first_name' => 'Not',
					'last_name'  => 'Owner',
				),
			)
		);
		$this->assertSame( 0, ( new BookingRepository() )->find_by_key( $second->get_data()['booking_key'] )['user_id'], 'A guest cannot claim an existing account.' );
		$this->assertNotSame( 'Not', get_userdata( $existing )->first_name, 'The existing account is not changed.' );
		$this->assertSame( $existing, get_user_by( 'email', 'taken@example.com' )->ID );
	}

	public function test_requests_are_rate_limited(): void {
		remove_filter( 'wpitravel_rate_limit', '__return_zero' );
		add_filter(
			'wpitravel_rate_limit',
			static fn( $limit, $bucket ) => 'quote' === $bucket ? 2 : $limit,
			10,
			2
		);

		$this->assertSame( 200, $this->api( 'POST', '/quote', $this->selection() )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', '/quote', $this->selection() )->get_status() );
		$this->assertSame( 429, $this->api( 'POST', '/quote', $this->selection() )->get_status() );
	}
}
