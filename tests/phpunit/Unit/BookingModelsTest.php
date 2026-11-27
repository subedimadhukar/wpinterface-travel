<?php
/**
 * Day tours with time slots, private departures and price on request.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingService;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Departures\Schedule;
use WPInterface\Travel\Frontend\BookingWidget;
use WPInterface\Travel\Frontend\Parts;
use WPInterface\Travel\Frontend\TripData;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * Booking models (Phase 19, free plugin).
 */
class BookingModelsTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		wp_set_current_user( 0 );
	}

	/**
	 * Holds seats on a slot; returns the response.
	 *
	 * @param string $time Start time.
	 * @param int    $pax  Adults.
	 * @param string $date Date.
	 */
	private function hold_slot( string $time, int $pax, string $date = '2030-05-01' ): \WP_REST_Response {
		return $this->api(
			'POST',
			'/holds',
			array_merge(
				$this->selection( array( 'adult' => $pax ) ),
				array(
					'date' => $date,
					'time' => $time,
				)
			)
		);
	}

	public function test_each_start_time_sells_its_own_seats(): void {
		update_post_meta(
			$this->trip,
			TripMeta::SCHEDULE,
			array(
				'recurring'    => array(
					array(
						'start'    => '2030-05-01',
						'end'      => '2030-05-01',
						'times'    => array( '09:00', '14:00' ),
						'capacity' => 4,
					),
				),
				'fixed'        => array(
					array(
						'date'     => '2030-05-01',
						'time'     => '18:00',
						'capacity' => 2,
					),
				),
				'capacity'     => 10,
				'advance_days' => 0,
			)
		);

		$schedule = Schedule::sanitize( get_post_meta( $this->trip, TripMeta::SCHEDULE, true ) );
		$this->assertSame( 4, Schedule::capacity( $schedule, '2030-05-01', '09:00' ) );
		$this->assertSame( 2, Schedule::capacity( $schedule, '2030-05-01', '18:00' ) );

		$slots = ( new Availability() )->for_trip( $this->trip, '2030-05-01', '2030-05-01' );
		$this->assertSame(
			array(
				'09:00' => 4,
				'14:00' => 4,
				'18:00' => 2,
			),
			array_column( $slots, 'seats_left', 'time' )
		);

		$this->assertSame( 201, $this->hold_slot( '09:00', 4 )->get_status() );
		$this->assertSame( 409, $this->hold_slot( '09:00', 1 )->get_status(), 'The morning is full.' );
		$this->assertSame( 201, $this->hold_slot( '14:00', 4 )->get_status(), 'The afternoon sells its own seats.' );
		$this->assertSame( 409, $this->hold_slot( '18:00', 3 )->get_status(), 'The evening has 2 seats.' );
		$this->assertSame( 201, $this->hold_slot( '18:00', 2 )->get_status() );
	}

	public function test_a_private_departure_takes_one_group(): void {
		$schedule             = Schedule::sanitize( get_post_meta( $this->trip, TripMeta::SCHEDULE, true ) );
		$schedule['private']  = true;
		$schedule['capacity'] = 8;
		update_post_meta( $this->trip, TripMeta::SCHEDULE, $schedule );

		$slots = ( new Availability() )->for_trip( $this->trip, '2030-05-01', '2030-05-01' );
		$this->assertTrue( $slots[0]['private'] );

		$first = $this->hold( array( 'adult' => 2 ) );
		$this->assertSame( 409, $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 1 ) ) )->get_status(), 'Held by the first group.' );

		$booking = ( new BookingRepository() )->find_by_key( $this->book( $first )->get_data()['booking_key'] );
		$slots   = ( new Availability() )->for_trip( $this->trip, '2030-05-01', '2030-05-01' );
		$this->assertSame( 'sold_out', $slots[0]['status'] );

		$refused = $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 1 ) ) );
		$this->assertSame( 409, $refused->get_status() );

		// Cancelled: the date is free again.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new BookingService() )->set_status( (int) $booking['id'], 'cancelled' );
		wp_set_current_user( 0 );
		$this->assertSame( 201, $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 3 ) ) )->get_status() );
	}

	public function test_price_on_request_takes_enquiries_instead_of_bookings(): void {
		update_post_meta( $this->trip, TripMeta::ON_REQUEST, true );

		$this->assertTrue( TripMeta::on_request( $this->trip ) );
		$this->assertNull( TripData::price( $this->trip ) );
		$this->assertTrue( TripData::card( get_post( $this->trip ) )['on_request'] );
		$this->assertStringContainsString( 'Price on request', Parts::price( $this->trip ) );
		$this->assertSame( '', Parts::departures( $this->trip ) );

		$quote = $this->api( 'POST', '/quote', $this->selection() );
		$this->assertSame( 409, $quote->get_status() );
		$this->assertSame( 'wpitravel_price_on_request', $quote->get_data()['code'] );

		$html = ( new BookingWidget() )->render( $this->trip );
		$this->assertStringContainsString( 'wpitravel-enquiry', $html );

		$slots = ( new Availability() )->for_trip( $this->trip, '2030-05-01', '2030-05-01' );
		$this->assertFalse( $slots[0]['bookable'] );

		delete_post_meta( $this->trip, TripMeta::ON_REQUEST );
		$this->assertSame( 200, $this->api( 'POST', '/quote', $this->selection() )->get_status() );
	}

	public function test_add_ons_can_refuse_seats_inside_the_lock(): void {
		add_filter(
			'wpitravel_seats_check',
			static function ( $row, $trip_id, $seats ) {
				return $seats > 2 ? new \WP_Error( 'test_full', 'Jeep full', array( 'status' => 409 ) ) : $row;
			},
			10,
			3
		);

		$this->assertSame( 201, $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 2 ) ) )->get_status() );
		$this->assertSame( 'test_full', $this->api( 'POST', '/holds', $this->selection( array( 'adult' => 3 ) ) )->get_data()['code'] );
	}
}
