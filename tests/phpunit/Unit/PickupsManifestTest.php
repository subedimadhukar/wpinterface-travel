<?php
/**
 * Pickup points and the departure traveler list.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Admin\ManifestPage;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Booking\BookingView;
use WPInterface\Travel\Booking\Pickups;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Emails\Tags;
use WPInterface\Travel\Frontend\BookingWidget;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * Customers choose a pickup point; staff print who is on each departure.
 */
class PickupsManifestTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		wp_set_current_user( 0 );

		update_post_meta(
			$this->trip,
			TripMeta::PICKUPS,
			array(
				array(
					'name' => 'Hotel Yak',
					'time' => '6:30',
					'note' => 'Lobby',
				),
				array(
					'name' => 'Airport',
					'time' => '07:15',
				),
			)
		);
	}

	/**
	 * Books with a pickup key (or none) and returns the response.
	 *
	 * @param string $pickup Key.
	 * @param array  $pax    Counts.
	 */
	private function book_pickup( string $pickup, array $pax = array( 'adult' => 2 ) ): \WP_REST_Response {
		return $this->book( $this->hold( $pax ), '' !== $pickup ? array( 'pickup' => $pickup ) : array() );
	}

	public function test_points_are_cleaned_with_stable_unique_keys(): void {
		$points = Pickups::sanitize(
			array(
				array(
					'name' => 'Hotel Yak',
					'time' => '25:00',
				),
				array( 'name' => 'Hotel Yak' ),
				array( 'name' => '' ),
				'nope',
			)
		);

		$this->assertSame( array( 'hotel-yak', 'hotel-yak-2' ), wp_list_pluck( $points, 'key' ) );
		$this->assertSame( '', $points[0]['time'], 'An impossible time is dropped.' );

		$saved = Pickups::for_trip( $this->trip );
		$this->assertSame( '06:30', $saved[0]['time'] );
		$this->assertSame( 'airport', $saved[1]['key'] );
	}

	public function test_the_booking_form_offers_the_points(): void {
		$data = BookingWidget::trip_data( get_post( $this->trip ) );

		$this->assertSame( array( 'hotel-yak', 'airport' ), wp_list_pluck( $data['pickups'], 'key' ) );
		$this->assertSame( 'Lobby', $data['pickups'][0]['note'] );
	}

	public function test_a_point_must_be_chosen_and_must_exist(): void {
		$missing = $this->book_pickup( '' );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'wpitravel_pickup_required', $missing->get_data()['code'] );

		$unknown = $this->book_pickup( 'moon-base' );
		$this->assertSame( 400, $unknown->get_status() );
		$this->assertSame( 'wpitravel_pickup_invalid', $unknown->get_data()['code'] );
	}

	public function test_the_booking_keeps_a_copy_of_the_chosen_point(): void {
		$response = $this->book_pickup( 'hotel-yak' );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$booking = ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] );

		// Changing the trip later does not change the booking.
		update_post_meta( $this->trip, TripMeta::PICKUPS, array( array( 'name' => 'Somewhere else' ) ) );

		$this->assertSame(
			array(
				'name' => 'Hotel Yak',
				'time' => '06:30',
				'note' => 'Lobby',
			),
			Pickups::of_booking( $booking )
		);
		$this->assertSame( 'Hotel Yak', BookingView::for_customer( $booking )['pickup']['name'] );
		$this->assertSame( 'Hotel Yak, 06:30', Tags::for_booking( $booking )['pickup_point'] );
	}

	public function test_trips_without_points_book_as_before(): void {
		delete_post_meta( $this->trip, TripMeta::PICKUPS );

		$response = $this->book_pickup( '' );
		$this->assertSame( 201, $response->get_status() );

		$booking = ( new BookingRepository() )->find_by_key( $response->get_data()['booking_key'] );
		$this->assertNull( Pickups::of_booking( $booking ) );
		$this->assertSame( '—', Tags::for_booking( $booking )['pickup_point'] );
	}

	public function test_traveler_list_groups_travelers_by_pickup_and_skips_cancelled(): void {
		$airport = ( new BookingRepository() )->find_by_key( $this->book_pickup( 'airport', array( 'adult' => 1 ) )->get_data()['booking_key'] );
		$hotel   = ( new BookingRepository() )->find_by_key( $this->book_pickup( 'hotel-yak', array( 'adult' => 2 ) )->get_data()['booking_key'] );
		$gone    = ( new BookingRepository() )->find_by_key( $this->book_pickup( 'airport', array( 'adult' => 1 ) )->get_data()['booking_key'] );
		( new BookingRepository() )->update( (int) $gone['id'], array( 'status' => 'cancelled' ) );

		$list = ManifestPage::data( $this->trip, '2030-05-01' );

		$this->assertSame( array( $hotel['number'], $airport['number'] ), wp_list_pluck( $list['bookings'], 'number' ), 'Earliest pickup first; cancelled left out.' );
		$this->assertSame( 3, $list['travelers'] );
		$this->assertCount( 2, $list['bookings'][0]['travelers'], 'One line per seat, even before names are sent.' );
		$this->assertSame( 'Ada Lovelace', $list['bookings'][0]['travelers'][0]['name'] );
		$this->assertSame(
			array(
				'Airport, 07:15'   => 1,
				'Hotel Yak, 06:30' => 2,
			),
			$list['pickups']
		);
		$this->assertSame( 30000, $list['due'], 'Unpaid bookings: 3 × 100.00 still to collect.' );
	}

	public function test_traveler_list_address_and_access(): void {
		$url = ManifestPage::url( $this->trip, '2030-05-01', '07:30' );

		$this->assertStringContainsString( 'page=wpitravel-manifest', $url );
		$this->assertStringContainsString( 'date=2030-05-01', $url );
		$this->assertStringContainsString( 'time=07:30', $url );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		( new ManifestPage() )->render();
	}
}
