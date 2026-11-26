<?php
/**
 * Bookings admin, staff bookings and reports.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingSearch;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Payments\PaymentService;
use WPInterface\Travel\Reports\Reports;

/**
 * Covers Travel → Bookings and the dashboard figures.
 */
class AdminBookingsTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Books through the public flow.
	 *
	 * @param array<string, int> $pax   Counts.
	 * @param string             $email Email.
	 * @param string             $first First name.
	 * @return array<string, mixed>
	 */
	private function booking( array $pax = array( 'adult' => 2 ), string $email = 'ada@example.com', string $first = 'Ada' ): array {
		$response = $this->book(
			$this->hold( $pax ),
			array(
				'customer' => array(
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => 'Tester',
				),
			)
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] );
	}

	/**
	 * Booking numbers of a search.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return string[]
	 */
	private function numbers( array $args ): array {
		return wp_list_pluck( BookingSearch::run( $args )['items'], 'number' );
	}

	public function test_search_by_status_number_name_and_email(): void {
		$ada   = $this->booking();
		$grace = $this->booking( array( 'adult' => 1 ), 'grace@example.org', 'Grace' );
		( new \WPInterface\Travel\Booking\BookingService() )->set_status( $grace['id'], 'confirmed' );

		$this->assertSame( array( $ada['number'] ), $this->numbers( array( 'status' => 'pending' ) ) );
		$this->assertSame( array( $grace['number'] ), $this->numbers( array( 'search' => 'grace@' ) ) );
		$this->assertSame( array( $grace['number'] ), $this->numbers( array( 'search' => 'Grace Tester' ) ) );
		$this->assertSame( array( $ada['number'] ), $this->numbers( array( 'search' => $ada['number'] ) ) );
		$this->assertSame( array(), $this->numbers( array( 'search' => "' OR 1=1 -- " ) ) );
		$this->assertSame( 1, BookingSearch::status_counts()['confirmed'] );
	}

	public function test_list_and_detail_need_booking_rights(): void {
		$booking = $this->booking();

		$response = $this->api( 'GET', '/bookings' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', (string) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, $response->get_data()['counts']['pending'] );

		$detail = $this->api( 'GET', '/bookings/' . $booking['id'] )->get_data();
		$this->assertSame( 'ada@example.com', $detail['customer']['email'] );
		$this->assertNotEmpty( $detail['history'] );
		$this->assertArrayHasKey( 'confirmed', $detail['statuses'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->api( 'GET', '/bookings' )->get_status() );
		$this->assertSame( 403, $this->api( 'GET', '/bookings/' . $booking['id'] )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/bookings/' . $booking['id'] . '/notes', array( 'note' => 'x' ) )->get_status() );
	}

	public function test_status_change_and_notes_are_logged(): void {
		$booking = $this->booking();

		$response = $this->api(
			'PATCH',
			'/bookings/' . $booking['id'],
			array(
				'status' => 'confirmed',
				'note'   => 'Paid at the office',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'confirmed', $response->get_data()['status'] );

		$this->api( 'POST', '/bookings/' . $booking['id'] . '/notes', array( 'note' => 'Vegetarian <b>meals</b>' ) );
		$history = $this->api( 'GET', '/bookings/' . $booking['id'] )->get_data()['history'];

		$this->assertSame( 'note', $history[0]['event'] );
		$this->assertSame( 'Vegetarian meals', $history[0]['message'] );
		$this->assertContains( 'Paid at the office', wp_list_pluck( $history, 'message' ) );
		$this->assertSame( 400, $this->api( 'POST', '/bookings/' . $booking['id'] . '/notes', array( 'note' => '   ' ) )->get_status() );
	}

	public function test_staff_booking_takes_seats_and_can_skip_customer_emails(): void {
		$sent = array();
		$spy  = static function ( $send, $template ) use ( &$sent ) {
			if ( $send ) {
				$sent[] = $template;
			}
			return $send;
		};
		add_filter( 'wpitravel_send_booking_email', $spy, 99, 2 );

		$response = $this->api(
			'POST',
			'/bookings/manual',
			array_merge(
				$this->selection( array( 'adult' => 3 ) ),
				array(
					'customer' => array(
						'email'      => 'phone@example.com',
						'first_name' => 'Phone',
						'last_name'  => 'Caller',
					),
					'status'   => 'confirmed',
					'notify'   => false,
				)
			)
		);

		remove_filter( 'wpitravel_send_booking_email', $spy, 99 );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'confirmed', $response->get_data()['status'] );
		$this->assertSame( '300.00', $response->get_data()['total'] );
		$this->assertNotContains( 'customer_booking_received', $sent );
		$this->assertNotContains( 'customer_booking_confirmed', $sent );
		$this->assertContains( 'admin_new_booking', $sent, 'Staff are still told.' );

		global $wpdb;
		$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( 'SELECT seats_booked FROM %i WHERE trip_id = %d', Schema::table( 'departures' ), $this->trip ) ) );
		$this->assertSame( 'Booking entered by staff.', $response->get_data()['history'][ count( $response->get_data()['history'] ) - 1 ]['message'] );
	}

	public function test_staff_booking_respects_capacity_and_rights(): void {
		$this->set_up_booking( 2 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$body = array_merge(
			$this->selection( array( 'adult' => 3 ) ),
			array(
				'customer' => array(
					'email'      => 'x@example.com',
					'first_name' => 'X',
					'last_name'  => 'Y',
				),
			)
		);

		$this->assertSame( 409, $this->api( 'POST', '/bookings/manual', $body )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->api( 'POST', '/bookings/manual', $body )->get_status() );
	}

	public function test_report_counts_money_received_minus_refunds(): void {
		$one = $this->booking();
		$two = $this->booking( array( 'adult' => 1 ), 'b@example.com', 'Bea' );
		$this->booking( array( 'adult' => 1 ), 'c@example.com', 'Cy' );
		( new \WPInterface\Travel\Booking\BookingService() )->set_status( $two['id'], 'cancelled' );

		$payments = new PaymentService();
		$payment  = $payments->record_manual( $one['id'], '200', 'manual' );
		$this->assertIsArray( $payment );
		$this->assertIsArray( $payments->refund( (int) $payment['id'], '50', 'Partial refund', true ) );

		$today   = current_time( 'Y-m-d' );
		$summary = Reports::summary( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $today );

		$this->assertSame( '150.00', $summary['revenue'] );
		$this->assertSame( 2, $summary['bookings'], 'Cancelled bookings are not counted.' );
		$this->assertSame( 3, $summary['pax'] );
		$this->assertSame( '300.00', $summary['booking_value'] );
		$this->assertCount( 7, $summary['series'] );
		$this->assertSame( '150.00', end( $summary['series'] )['revenue'] );
		$this->assertSame( 'Lake Trek', $summary['top_trips'][0]['title'] );

		$this->assertSame( 0, Reports::summary( '2001-01-01', '2001-01-31' )['bookings'] );
		$this->assertSame( 'month', Reports::summary( ...Reports::range( '12m' ) )['interval'] );
	}

	public function test_report_endpoint_checks_rights_and_range(): void {
		$this->assertSame( 200, $this->api( 'GET', '/reports/summary' )->get_status() );
		$request = new \WP_REST_Request( 'GET', '/wpinterface-travel/v1/reports/summary' );
		$request->set_query_params(
			array(
				'from' => '2020-01-01',
				'to'   => '2025-01-01',
			)
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->api( 'GET', '/reports/summary' )->get_status() );
	}
}
