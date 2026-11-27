<?php
/**
 * Schema offers from departures, and the sitemap.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Frontend\Schema;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * Offers summarise the next year's departures.
 */
class SchemaOffersTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * A departure next month (the schema looks a year ahead).
	 *
	 * @var string
	 */
	private string $date;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking( 2 );

		$this->date = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		update_post_meta(
			$this->trip,
			\WPInterface\Travel\Content\TripMeta::SCHEDULE,
			array(
				'fixed'        => array( array( 'date' => $this->date ) ),
				'capacity'     => 2,
				'advance_days' => 0,
			)
		);
	}

	public function test_departures_make_an_aggregate_offer(): void {
		$offers = Schema::data( get_post( $this->trip ) )['offers'];

		$this->assertSame( 'AggregateOffer', $offers['@type'] );
		$this->assertSame( 1, $offers['offerCount'] );
		$this->assertSame( 'USD', $offers['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $offers['availability'] );
		$this->assertEqualsWithDelta( (float) $offers['lowPrice'], (float) $offers['highPrice'], 0.001, 'One departure: one price.' );
	}

	public function test_a_trip_with_only_full_departures_is_sold_out(): void {
		$hold = $this->api( 'POST', '/holds', array_merge( $this->selection( array( 'adult' => 2 ) ), array( 'date' => $this->date ) ) );
		$this->assertSame( 201, $hold->get_status(), wp_json_encode( $hold->get_data() ) );
		$this->assertSame( 201, $this->book( $hold->get_data()['hold_key'] )->get_status() );

		$offers = Schema::data( get_post( $this->trip ) )['offers'];

		$this->assertSame( 'Offer', $offers['@type'] );
		$this->assertSame( 'https://schema.org/SoldOut', $offers['availability'] );
	}

	public function test_password_protected_trips_are_left_out_of_the_sitemap(): void {
		$schema = new Schema();

		$this->assertFalse( $schema->sitemap_args( array(), PostTypes::TRIP )['has_password'] );
		$this->assertArrayNotHasKey( 'has_password', $schema->sitemap_args( array(), 'post' ) );

		$schema->register();
		$protected = self::factory()->post->create(
			array(
				'post_type'     => PostTypes::TRIP,
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$urls      = wp_list_pluck( ( new \WP_Sitemaps_Posts() )->get_url_list( 1, PostTypes::TRIP ), 'loc' );

		$this->assertContains( get_permalink( $this->trip ), $urls );
		$this->assertNotContains( get_permalink( $protected ), $urls );
	}
}
