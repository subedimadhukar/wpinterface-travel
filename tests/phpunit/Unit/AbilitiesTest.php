<?php
/**
 * Abilities for AI tools.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Abilities\Abilities;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * Trip search and availability abilities.
 */
class AbilitiesTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
	}

	public function test_abilities_are_registered_where_the_api_exists(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API needs WordPress 6.9.' );
		}

		$this->assertNotNull( wp_get_ability( 'wpinterface-travel/search-trips' ) );
		$this->assertNotNull( wp_get_ability( 'wpinterface-travel/trip-availability' ) );
	}

	public function test_search_returns_public_trip_facts(): void {
		$trips = Abilities::search_trips( array( 'search' => 'Lake' ) );

		$this->assertCount( 1, $trips );
		$this->assertSame( $this->trip, $trips[0]['id'] );
		$this->assertSame( 'Lake Trek', $trips[0]['title'] );
		$this->assertSame( '100.00', $trips[0]['price_from'] );
		$this->assertSame( array(), Abilities::search_trips( array( 'search' => 'Nothing like this' ) ) );
	}

	public function test_availability_lists_dates_with_seats(): void {
		$dates = Abilities::availability(
			array(
				'trip_id' => $this->trip,
				'from'    => '2030-04-01',
				'to'      => '2030-06-01',
			)
		);

		$this->assertSame( '2030-05-01', $dates[0]['date'] );
		$this->assertTrue( $dates[0]['bookable'] );
		$this->assertStringContainsString( 'wpitravel_date=2030-05-01', $dates[0]['book_url'] );
		$this->assertWPError( Abilities::availability( array( 'trip_id' => 999999 ) ) );

		wp_update_post(
			array(
				'ID'          => $this->trip,
				'post_status' => 'draft',
			)
		);
		$this->assertWPError( Abilities::availability( array( 'trip_id' => $this->trip ) ), 'Drafts stay private.' );
	}
}
