<?php
/**
 * Setup wizard, sample trips and importers.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Import\Importer;
use WPInterface\Travel\Import\Importers;
use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Setup\SampleData;
use WPInterface\Travel\Setup\Setup;

/**
 * Covers getting a new site ready.
 */
class SetupImportTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Source tables created by a test.
	 *
	 * @var string[]
	 */
	private array $tables = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Setup::DONE_OPTION );
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuerySchemaChange -- Test fixture.
		}

		parent::tear_down();
	}

	/**
	 * Creates a source table. Temporary, because CREATE TABLE would commit
	 * the test's transaction and leak data into later tests.
	 *
	 * @param string $name    Name without prefix.
	 * @param string $columns Column SQL.
	 * @return string Full name.
	 */
	private function table( string $name, string $columns ): string {
		global $wpdb;

		$table          = $wpdb->prefix . $name;
		$this->tables[] = $table;
		$wpdb->query( "CREATE TEMPORARY TABLE {$table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, {$columns}, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuerySchemaChange -- Test fixture.

		return $table;
	}

	/**
	 * Imports everything from one importer.
	 *
	 * @param string $id Importer ID.
	 * @return array<string, mixed> Last batch.
	 */
	private function import( string $id ): array {
		$response = $this->api( 'POST', '/import/' . $id, array( 'offset' => 0 ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	public function test_sample_trips_are_added_once_and_removed_cleanly(): void {
		$response = $this->api( 'POST', '/setup/sample-data' );
		$this->assertSame( 201, $response->get_status() );
		$ids = $response->get_data()['samples'];
		$this->assertCount( 3, $ids );

		$pricing = Pricing::sanitize( get_post_meta( $ids[0], TripMeta::PRICING, true ) );
		$this->assertSame( '1290.00', $pricing['options'][0]['categories'][0]['sale_price'] );
		$this->assertNotEmpty( get_post_meta( $ids[0], TripMeta::ITINERARY, true ) );
		$this->assertSame( array( 'Nepal' ), wp_list_pluck( get_the_terms( $ids[0], PostTypes::DESTINATION ), 'name' ) );
		$this->assertSame( 409, $this->api( 'POST', '/setup/sample-data' )->get_status() );

		// The agency's own trip shares the destination; it must survive.
		wp_set_object_terms( $this->trip, 'Nepal', PostTypes::DESTINATION );

		$removed = $this->api( 'DELETE', '/setup/sample-data' )->get_data();
		$this->assertSame( 3, $removed['removed'] );
		$this->assertSame( array(), SampleData::ids() );
		$this->assertNotNull( get_post( $this->trip ) );
		$this->assertInstanceOf( \WP_Term::class, get_term_by( 'name', 'Nepal', PostTypes::DESTINATION ) );
		$this->assertFalse( get_term_by( 'name', 'Portugal', PostTypes::DESTINATION ) );
	}

	public function test_sample_trip_with_bookings_is_kept(): void {
		$ids = SampleData::create();
		$this->assertIsArray( $ids );

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
			\WPInterface\Travel\Install\Schema::table( 'bookings' ),
			array(
				'booking_key' => str_repeat( 'a', 32 ),
				'trip_id'     => $ids[0],
				'start_date'  => '2030-01-01',
				'currency'    => 'USD',
				'created'     => '2026-01-01 00:00:00',
				'updated'     => '2026-01-01 00:00:00',
			)
		);

		$this->assertSame(
			array(
				'removed' => 2,
				'kept'    => 1,
			),
			SampleData::remove()
		);
	}

	public function test_setup_completes_and_needs_settings_rights(): void {
		$this->assertFalse( $this->api( 'GET', '/setup' )->get_data()['done'] );
		$this->assertTrue( $this->api( 'POST', '/setup/complete' )->get_data()['done'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->api( 'POST', '/setup/sample-data' )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/import/wp-travel' )->get_status() );
	}

	public function test_wp_travel_import(): void {
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'itineraries',
				'post_status'  => 'publish',
				'post_title'   => 'Everest View Trek',
				'post_content' => 'Trek description',
			)
		);
		update_post_meta(
			$source,
			'wp_travel_trip_duration_formating',
			array(
				'days'   => 8,
				'nights' => 7,
			)
		);
		update_post_meta(
			$source,
			'wp_travel_trip_itinerary_data',
			array(
				array(
					'label' => 'Day 1',
					'title' => 'Fly to Lukla',
					'desc'  => '<p>Scenic flight</p>',
				),
			)
		);
		update_post_meta( $source, 'wp_travel_trip_include', '<ul><li>Guide</li><li>Permits &amp; fees</li></ul>' );
		update_post_meta( $source, 'wp_travel_trip_exclude', "Flights\nInsurance" );
		update_post_meta(
			$source,
			'wptravel_trip_faqs',
			array(
				array(
					'question' => 'Is it hard?',
					'answer'   => 'Moderate.',
				),
			)
		);
		update_post_meta( $source, 'wp_travel_lat', '27.8' );
		update_post_meta( $source, 'wp_travel_lng', '86.7' );
		update_post_meta( $source, 'wp_travel_location', 'Khumbu' );
		update_post_meta( $source, 'wp_travel_group_size', '12' );

		register_taxonomy( 'travel_locations', 'itineraries' );
		$term = wp_insert_term( 'Nepal', 'travel_locations' );
		wp_set_object_terms( $source, array( (int) $term['term_id'] ), 'travel_locations' );
		unregister_taxonomy( 'travel_locations' );

		register_taxonomy( 'itinerary_pricing_category', 'itineraries' );
		$adult = wp_insert_term( 'Adult', 'itinerary_pricing_category' );
		unregister_taxonomy( 'itinerary_pricing_category' );

		global $wpdb;
		$pricings  = $this->table( 'wt_pricings', 'title varchar(255), trip_id bigint(20), min_pax int, max_pax int, sort_order int DEFAULT 0' );
		$relations = $this->table( 'wt_price_category_relation', 'pricing_id bigint(20), pricing_category_id bigint(20), regular_price varchar(20), is_sale varchar(5), sale_price varchar(20)' );
		$dates     = $this->table( 'wt_dates', "trip_id bigint(20), start_date date, end_date date NULL, recurring tinyint(1) DEFAULT 0, years varchar(255) DEFAULT '', months varchar(255) DEFAULT '', days varchar(255) DEFAULT '', date_days varchar(255) DEFAULT '', trip_time varchar(255) DEFAULT ''" );
		$excluded  = $this->table( 'wt_excluded_dates_times', "trip_id bigint(20), start_date date, end_date date NULL, recurring tinyint(1) DEFAULT 0, time varchar(255) DEFAULT ''" );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert(
			$pricings,
			array(
				'title'   => 'Group tour',
				'trip_id' => $source,
				'min_pax' => 2,
				'max_pax' => 10,
			)
		);
		$wpdb->insert(
			$relations,
			array(
				'pricing_id'          => $wpdb->insert_id,
				'pricing_category_id' => $adult['term_id'],
				'regular_price'       => '1500',
				'is_sale'             => '1',
				'sale_price'          => '1350',
			)
		);
		$wpdb->insert(
			$dates,
			array(
				'trip_id'    => $source,
				'start_date' => '2031-04-01',
			)
		);
		$wpdb->insert(
			$dates,
			array(
				'trip_id'    => $source,
				'start_date' => '2001-04-01',
			)
		);
		$wpdb->insert(
			$dates,
			array(
				'trip_id'    => $source,
				'start_date' => '2031-01-01',
				'end_date'   => '2031-10-31',
				'recurring'  => 1,
				'years'      => 'every_year',
				'months'     => '4,5',
				'days'       => 'MO,TH',
			)
		);
		// The 1st and 15th of each month: not a weekday rule, so dates for the coming year.
		$wpdb->insert(
			$dates,
			array(
				'trip_id'    => $source,
				'start_date' => gmdate( 'Y-m-d' ),
				'end_date'   => '0000-00-00',
				'recurring'  => 1,
				'months'     => 'every_month',
				'date_days'  => '1,15',
			)
		);
		$wpdb->insert(
			$excluded,
			array(
				'trip_id'    => $source,
				'start_date' => '2031-04-02',
				'end_date'   => '2031-04-03',
			)
		);
		$wpdb->insert(
			$excluded,
			array(
				'trip_id'    => $source,
				'start_date' => '2031-04-09',
				'time'       => '09:00',
			)
		);
		// phpcs:enable

		$result = $this->import( 'wp-travel' );
		$this->assertCount( 1, $result['created'] );
		$trip = $result['created'][0];

		$this->assertSame( 'Everest View Trek', get_the_title( $trip ) );
		$this->assertSame( 'publish', get_post_status( $trip ) );
		$this->assertSame( 8, TripMeta::sanitize_duration( get_post_meta( $trip, TripMeta::DURATION, true ) )['days'] );
		$this->assertSame( 'Fly to Lukla', get_post_meta( $trip, TripMeta::ITINERARY, true )[0]['title'] );
		$this->assertSame( array( 'Guide', 'Permits & fees' ), get_post_meta( $trip, TripMeta::INCLUDES, true ) );
		$this->assertSame( array( 'Flights', 'Insurance' ), get_post_meta( $trip, TripMeta::EXCLUDES, true ) );
		$this->assertSame( 'Khumbu', get_post_meta( $trip, TripMeta::MAP, true )['address'] );
		$this->assertSame( array( 'Nepal' ), wp_list_pluck( get_the_terms( $trip, PostTypes::DESTINATION ), 'name' ) );

		$option = Pricing::sanitize( get_post_meta( $trip, TripMeta::PRICING, true ) )['options'][0];
		$this->assertSame( 'Group tour', $option['label'] );
		$this->assertSame( 'Adult', $option['categories'][0]['label'] );
		$this->assertSame( '1500.00', $option['categories'][0]['price'] );
		$this->assertSame( '1350.00', $option['categories'][0]['sale_price'] );

		$schedule = get_post_meta( $trip, TripMeta::SCHEDULE, true );
		$fixed    = wp_list_pluck( $schedule['fixed'], 'date' );
		$this->assertContains( '2031-04-01', $fixed );
		$this->assertNotContains( '2001-04-01', $fixed, 'Past dates are dropped.' );
		$this->assertSame(
			array(
				'start'    => '2031-01-01',
				'end'      => '2031-10-31',
				'weekdays' => array( 1, 4 ),
				'months'   => array( 4, 5 ),
				'times'    => array(),
				'capacity' => 0,
			),
			$schedule['recurring'][0],
			'Weekday rules are kept as rules.'
		);
		$monthdays = array_diff( $fixed, array( '2031-04-01' ) );
		$this->assertNotEmpty( $monthdays );
		foreach ( $monthdays as $day ) {
			$this->assertContains( (int) substr( $day, 8 ), array( 1, 15 ), 'Days of the month become dates: ' . $day );
		}
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d', strtotime( '+366 days' ) ), max( $monthdays ), 'Only the coming year.' );
		$this->assertSame( array( '2031-04-02', '2031-04-03' ), $schedule['excluded'] );
		$notes = implode( ' ', $result['notes'] );
		$this->assertStringContainsString( 'days of the month', $notes );
		$this->assertStringContainsString( 'excluded dates', $notes, 'A time-only exclusion is reported, not imported.' );

		// Running again copies nothing twice.
		$again = $this->import( 'wp-travel' );
		$this->assertSame( array(), $again['created'] );
		$this->assertSame( 1, $again['skipped'] );
		$this->assertSame( 'publish', get_post_status( $source ), 'The source is untouched.' );
	}

	public function test_wp_travel_engine_import(): void {
		$source = self::factory()->post->create(
			array(
				'post_type'   => 'trip',
				'post_status' => 'draft',
				'post_title'  => 'Safari Classic',
			)
		);
		update_post_meta(
			$source,
			'wp_travel_engine_setting',
			array(
				'trip_code'          => 'SAF-1',
				'trip_duration'      => '5',
				'trip_duration_unit' => 'days',
				'itinerary'          => array(
					'itinerary_title'   => array( 1 => 'Arusha' ),
					'itinerary_content' => array( 1 => 'Meet the guide' ),
				),
				'trip_highlights'    => array( array( 'highlight_text' => 'Big five' ) ),
				'cost'               => array(
					'cost_includes' => "Park fees\nMeals",
					'cost_excludes' => 'Tips',
				),
				'faq'                => array(
					'faq_title'   => array( 'Visa?' ),
					'faq_content' => array( 'Yes, on arrival.' ),
				),
				'trip_minimum_pax'   => '2',
				'trip_cutoff_enable' => '1',
				'trip_cut_off_time'  => '3',
				'trip_cut_off_unit'  => 'days',
			)
		);
		update_post_meta( $source, 'max_travellers_per_day', '6' );

		$package = self::factory()->post->create(
			array(
				'post_type'   => 'trip-packages',
				'post_status' => 'publish',
				'post_title'  => 'Lodge',
			)
		);
		update_post_meta(
			$package,
			'package-categories',
			array(
				'c_ids'        => array( 11 => 11 ),
				'labels'       => array( 11 => 'Adult' ),
				'prices'       => array( 11 => '2000' ),
				'sale_prices'  => array( 11 => '1800' ),
				'enabled_sale' => array( 11 => '1' ),
			)
		);
		update_post_meta(
			$package,
			'package-dates',
			array(
				'20310601' => array(
					'dtstart'      => '2031-06-01',
					'is_recurring' => '',
				),
				'20310105' => array(
					'dtstart'      => '2031-01-05',
					'times'        => array( array( 'from' => '07:00' ) ),
					'is_recurring' => '1',
					'rrule'        => array(
						'r_frequency' => 'WEEKLY',
						'r_weekdays'  => array(
							1 => 'MO',
							5 => 'FR',
						),
						'r_until'     => '2031-12-31',
					),
				),
				'monthly'  => array(
					'dtstart'      => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
					'is_recurring' => true,
					'rrule'        => array(
						'r_frequency' => 'MONTHLY',
						'r_count'     => 4,
					),
				),
			)
		);
		update_post_meta( $source, 'packages_ids', array( $package ) );

		$trip = $this->import( 'wp-travel-engine' )['created'][0];

		$this->assertSame( 'draft', get_post_status( $trip ) );
		$this->assertSame( 'SAF-1', get_post_meta( $trip, TripMeta::CODE, true ) );
		$this->assertSame( array( 'Big five' ), get_post_meta( $trip, TripMeta::HIGHLIGHTS, true ) );
		$this->assertSame( array( 'Park fees', 'Meals' ), get_post_meta( $trip, TripMeta::INCLUDES, true ) );
		$this->assertSame( 'Visa?', get_post_meta( $trip, TripMeta::FAQS, true )[0]['question'] );
		$this->assertSame( 'Arusha', get_post_meta( $trip, TripMeta::ITINERARY, true )[0]['title'] );

		$option = Pricing::sanitize( get_post_meta( $trip, TripMeta::PRICING, true ) )['options'][0];
		$this->assertSame( 'Lodge', $option['label'] );
		$this->assertSame( '1800.00', $option['categories'][0]['sale_price'] );

		$schedule = get_post_meta( $trip, TripMeta::SCHEDULE, true );
		$fixed    = wp_list_pluck( $schedule['fixed'], 'date' );
		$this->assertContains( '2031-06-01', $fixed );
		$this->assertSame( array( 1, 5 ), $schedule['recurring'][0]['weekdays'], 'Weekly until a date stays a rule.' );
		$this->assertSame( '2031-12-31', $schedule['recurring'][0]['end'] );
		$this->assertSame( array( '07:00' ), $schedule['recurring'][0]['times'] );
		$this->assertCount( 5, $fixed, 'A monthly date repeated 4 times becomes 4 dates, plus the fixed one.' );
		$this->assertSame( 72, $schedule['cutoff_hours'] );
		$this->assertSame( 6, $schedule['capacity'] );
	}

	public function test_yatra_import(): void {
		global $wpdb;

		$trips           = $this->table(
			'yatra_trips',
			'title varchar(255), slug varchar(255), description text, short_description varchar(500), starting_location varchar(255), starting_latitude decimal(10,8), starting_longitude decimal(11,8), duration_days smallint, duration_nights smallint, duration_hours smallint, booking_deadline_hours smallint, difficulty_level bigint, pricing_type varchar(20), original_price decimal(10,2), discounted_price decimal(10,2), sale_price decimal(10,2), min_travelers smallint, max_travelers smallint, age_min tinyint, featured_image bigint, status varchar(50), included_items text, excluded_items text, price_types text, deleted_at datetime NULL'
		);
		$content         = $this->table( 'yatra_trip_content', 'trip_id bigint, content_type varchar(20), title varchar(255), description text, content_url varchar(500), sort_order smallint DEFAULT 0' );
		$days            = $this->table( 'yatra_trip_itinerary_days', 'trip_id bigint, day_number smallint, title varchar(255), description text, `order` smallint DEFAULT 0' );
		$classifications = $this->table( 'yatra_classifications', 'type varchar(20), name varchar(255)' );
		$links           = $this->table( 'yatra_trip_classifications', 'trip_id bigint, classification_id bigint, classification_type varchar(20), sort_order smallint DEFAULT 0' );
		$available       = $this->table( 'yatra_trip_availability_dates', 'trip_id bigint, departure_date date, seats_total smallint' );
		$rules           = $this->table( 'yatra_trip_availability_rules', "trip_id bigint, status varchar(20) DEFAULT 'active', availability_status varchar(20) DEFAULT 'available', rule_type varchar(50) DEFAULT 'weekly', start_date date, end_date date NULL, days_of_week text, week_of_month smallint NULL, day_of_week tinyint NULL, interval_days int NULL, interval_start_date date NULL, months text, excluded_dates text, time_slots text, departure_time time NULL, seats_total smallint DEFAULT 0" );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->insert(
			$classifications,
			array(
				'type' => 'difficulty',
				'name' => 'Hard',
			)
		);
		$hard = $wpdb->insert_id;
		$wpdb->insert(
			$classifications,
			array(
				'type' => 'destination',
				'name' => 'Bhutan',
			)
		);
		$bhutan = $wpdb->insert_id;
		$wpdb->insert(
			$trips,
			array(
				'title'                  => 'Druk Path<script>alert(1)</script>',
				'slug'                   => 'druk-path',
				'description'            => 'Long text<script>alert(2)</script><p onclick="x()">kept</p>',
				'short_description'      => 'Short',
				'starting_location'      => 'Paro',
				'starting_latitude'      => 27.43,
				'starting_longitude'     => 89.41,
				'duration_days'          => 6,
				'duration_nights'        => 5,
				'booking_deadline_hours' => 48,
				'difficulty_level'       => $hard,
				'pricing_type'           => 'traveler_based',
				'price_types'            => wp_json_encode(
					array(
						array(
							'label'          => 'Adult',
							'original_price' => '2400',
							'sale_price'     => '2200',
						),
						array(
							'label'          => 'Child',
							'original_price' => '1200',
						),
					)
				),
				'max_travelers'          => 8,
				'status'                 => 'publish',
				'included_items'         => wp_json_encode( array( array( 'title' => 'Guide' ) ) ),
				'excluded_items'         => wp_json_encode( array( array( 'title' => 'Visa' ) ) ),
			)
		);
		$id = $wpdb->insert_id;
		$wpdb->insert(
			$trips,
			array(
				'title'      => 'Deleted',
				'slug'       => 'deleted',
				'status'     => 'publish',
				'deleted_at' => '2025-01-01 00:00:00',
			)
		);
		$wpdb->insert(
			$content,
			array(
				'trip_id'      => $id,
				'content_type' => 'highlight',
				'title'        => 'Dzongs',
			)
		);
		$wpdb->insert(
			$content,
			array(
				'trip_id'      => $id,
				'content_type' => 'faq',
				'title'        => 'Altitude?',
				'description'  => '4,000 m',
			)
		);
		$wpdb->insert(
			$days,
			array(
				'trip_id'    => $id,
				'day_number' => 2,
				'title'      => 'Jangchulakha',
			)
		);
		$wpdb->insert(
			$days,
			array(
				'trip_id'    => $id,
				'day_number' => 1,
				'title'      => 'Paro',
			)
		);
		$wpdb->insert(
			$links,
			array(
				'trip_id'             => $id,
				'classification_id'   => $bhutan,
				'classification_type' => 'destination',
			)
		);
		$wpdb->insert(
			$available,
			array(
				'trip_id'        => $id,
				'departure_date' => '2031-10-01',
				'seats_total'    => 10,
			)
		);
		// Weekly on Sunday and Wednesday (0 = Sunday), with one closed period.
		$wpdb->insert(
			$rules,
			array(
				'trip_id'        => $id,
				'start_date'     => '2031-03-01',
				'days_of_week'   => '[0,3]',
				'months'         => '[3,4,5]',
				'excluded_dates' => '["2031-03-05",{"start":"2031-04-01","end":"2031-04-02"}]',
				'time_slots'     => '[{"departure_time":"09:00"},{"departure_time":"14:30"}]',
			)
		);
		// Last Saturday of each month: dates for the coming year.
		$wpdb->insert(
			$rules,
			array(
				'trip_id'       => $id,
				'rule_type'     => 'monthly',
				'start_date'    => gmdate( 'Y-m-d' ),
				'week_of_month' => 5,
				'day_of_week'   => 6,
				'months'        => '[]',
			)
		);
		// Paused rules are left out.
		$wpdb->insert(
			$rules,
			array(
				'trip_id'      => $id,
				'status'       => 'paused',
				'start_date'   => '2031-01-01',
				'days_of_week' => '[1]',
			)
		);
		// phpcs:enable

		$this->assertSame( 1, Importers::get( 'yatra' )->count(), 'Deleted trips are left out.' );

		$trip = $this->import( 'yatra' )['created'][0];

		$this->assertSame( 'Druk Path', get_the_title( $trip ), 'Titles from Yatra are plain text.' );
		$this->assertStringNotContainsString( '<script', get_post_field( 'post_content', $trip ), 'Content from Yatra is cleaned like any other input.' );
		$this->assertStringNotContainsString( 'onclick', get_post_field( 'post_content', $trip ) );
		$this->assertStringContainsString( '<p>kept</p>', get_post_field( 'post_content', $trip ) );
		$this->assertSame( 'challenging', get_post_meta( $trip, TripMeta::DIFFICULTY, true ) );
		$this->assertSame( array( 'Paro', 'Jangchulakha' ), wp_list_pluck( get_post_meta( $trip, TripMeta::ITINERARY, true ), 'title' ) );
		$this->assertSame( array( 'Dzongs' ), get_post_meta( $trip, TripMeta::HIGHLIGHTS, true ) );
		$this->assertSame( array( 'Guide' ), get_post_meta( $trip, TripMeta::INCLUDES, true ) );
		$this->assertSame( array( 'Bhutan' ), wp_list_pluck( get_the_terms( $trip, PostTypes::DESTINATION ), 'name' ) );

		$categories = Pricing::sanitize( get_post_meta( $trip, TripMeta::PRICING, true ) )['options'][0]['categories'];
		$this->assertSame( array( 'Adult', 'Child' ), wp_list_pluck( $categories, 'label' ) );
		$this->assertSame( '2200.00', $categories[0]['sale_price'] );

		$schedule = get_post_meta( $trip, TripMeta::SCHEDULE, true );
		$this->assertSame( 10, $schedule['capacity'] );
		$this->assertSame( 48, $schedule['cutoff_hours'] );

		$this->assertCount( 1, $schedule['recurring'], 'The paused rule is left out.' );
		$this->assertSame( array( 3, 7 ), $schedule['recurring'][0]['weekdays'], 'Sunday (0) becomes 7.' );
		$this->assertSame( array( 3, 4, 5 ), $schedule['recurring'][0]['months'] );
		$this->assertSame( array( '09:00', '14:30' ), $schedule['recurring'][0]['times'] );
		$this->assertSame( array( '2031-03-05', '2031-04-01', '2031-04-02' ), $schedule['excluded'] );

		$saturdays = array_diff( wp_list_pluck( $schedule['fixed'], 'date' ), array( '2031-10-01' ) );
		$this->assertNotEmpty( $saturdays );
		foreach ( $saturdays as $day ) {
			$this->assertSame( '6', gmdate( 'N', strtotime( $day ) ), $day );
			$this->assertNotSame( gmdate( 'n', strtotime( $day ) ), gmdate( 'n', strtotime( $day . ' +7 days' ) ), 'The last Saturday of its month: ' . $day );
		}
	}

	public function test_detection_lists_only_sources_with_trips(): void {
		$this->assertSame( array(), Importers::detected() );

		self::factory()->post->create( array( 'post_type' => 'itineraries' ) );

		$this->assertSame( array( 'wp-travel' ), wp_list_pluck( Importers::detected(), 'id' ) );
		$this->assertSame( '_wpitravel_imported_from', Importer::SOURCE_META );
	}
}
