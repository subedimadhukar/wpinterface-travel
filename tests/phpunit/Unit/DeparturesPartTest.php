<?php
/**
 * Upcoming departures list, and the trip list's Bookings link.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Admin\TripList;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Frontend\Parts;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * The departures section shows dates, seats left and a Book link per date.
 */
class DeparturesPartTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Departure dates, next month and the month after.
	 *
	 * @var list<string>
	 */
	private array $dates;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking( 2 );

		$this->dates = array( gmdate( 'Y-m-d', strtotime( '+30 days' ) ), gmdate( 'Y-m-d', strtotime( '+60 days' ) ) );
		update_post_meta(
			$this->trip,
			TripMeta::SCHEDULE,
			array(
				'fixed'        => array( array( 'date' => $this->dates[0] ), array( 'date' => $this->dates[1] ) ),
				'capacity'     => 2,
				'advance_days' => 0,
			)
		);
	}

	public function test_lists_dates_seats_left_and_book_links(): void {
		$html = Parts::departures( $this->trip );

		$this->assertStringContainsString( 'datetime="' . $this->dates[0] . '"', $html );
		$this->assertStringContainsString( '2 seats left', $html );
		$this->assertStringContainsString( esc_url( add_query_arg( 'wpitravel_date', $this->dates[1], get_permalink( $this->trip ) ) . '#wpitravel-booking' ), $html );
		$this->assertSame( 2, substr_count( $html, 'wpitravel-departures__book' ) );

		$this->assertSame( 1, substr_count( Parts::departures( $this->trip, array( 'number' => 1 ) ), '<li' ), 'number limits the list.' );
	}

	public function test_a_full_departure_says_so_and_has_no_book_link(): void {
		$hold = $this->api( 'POST', '/holds', array_merge( $this->selection( array( 'adult' => 2 ) ), array( 'date' => $this->dates[0] ) ) );
		$this->assertSame( 201, $this->book( $hold->get_data()['hold_key'] )->get_status() );

		$html = Parts::departures( $this->trip );

		$this->assertStringContainsString( 'is-full', $html );
		$this->assertStringContainsString( 'Full', $html );
		$this->assertSame( 1, substr_count( $html, 'wpitravel-departures__book' ) );
	}

	public function test_no_upcoming_departures_shows_nothing(): void {
		delete_post_meta( $this->trip, TripMeta::SCHEDULE );

		$this->assertSame( '', Parts::departures( $this->trip ) );
	}

	public function test_shortcode_and_block_are_registered(): void {
		$this->assertTrue( shortcode_exists( 'wpitravel_trip_departures' ) );
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'wpitravel/trip-departures' ) );
	}

	public function test_trip_list_links_to_the_trips_bookings_for_staff_only(): void {
		$list = new TripList();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$actions = $list->row_actions( array(), get_post( $this->trip ) );
		$this->assertStringContainsString( 'trip_id=' . $this->trip, $actions['wpitravel_bookings'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertArrayNotHasKey( 'wpitravel_bookings', $list->row_actions( array(), get_post( $this->trip ) ) );
	}
}
