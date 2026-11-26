<?php
/**
 * Trip post type, taxonomies and detail fields.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Install\Schema;
use WPInterface\Travel\Install\Uninstaller;
use WPInterface\Travel\Options;

/**
 * Covers registration, permissions, REST meta and uninstall of trip content.
 */
class TripContentTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();

		// The core test case clears registered meta after each test; real requests register it on `init`.
		( new TripMeta() )->register_meta();

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function test_post_type_and_taxonomies_are_registered(): void {
		$type = get_post_type_object( PostTypes::TRIP );

		$this->assertNotNull( $type );
		$this->assertSame( 'trips', $type->rest_base );
		$this->assertSame( 'edit_wpitravel_trips', $type->cap->edit_posts );
		$this->assertTrue( post_type_supports( PostTypes::TRIP, 'custom-fields' ), 'Meta is only exposed in REST with custom-fields support.' );

		foreach ( PostTypes::taxonomies() as $taxonomy ) {
			$this->assertTrue( is_object_in_taxonomy( PostTypes::TRIP, $taxonomy ), $taxonomy );
		}

		$this->assertSame( 'destinations', get_taxonomy( PostTypes::DESTINATION )->rest_base );
	}

	public function test_editors_can_manage_trips_but_authors_cannot(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$trip   = self::factory()->post->create( array( 'post_type' => PostTypes::TRIP ) );

		$this->assertTrue( user_can( $editor, 'edit_post', $trip ) );
		$this->assertTrue( user_can( $editor, 'publish_wpitravel_trips' ) );
		$this->assertFalse( user_can( $author, 'edit_post', $trip ) );
		$this->assertFalse( user_can( $author, 'edit_wpitravel_trips' ) );
	}

	public function test_rest_saves_and_sanitizes_trip_details(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/trips' );
		$request->set_body_params(
			array(
				'title'  => 'Alpine Lakes Trek',
				'status' => 'publish',
				'meta'   => array(
					TripMeta::CODE       => 'ALT-01',
					TripMeta::DURATION   => array(
						'days'   => 5,
						'nights' => 4,
					),
					TripMeta::DIFFICULTY => 'moderate',
					TripMeta::GROUP_SIZE => array(
						'min' => 4,
						'max' => 2,
					),
					TripMeta::ITINERARY  => array(
						array(
							'title'       => 'Arrival',
							'description' => '<p>Meet the guide.</p><script>alert(1)</script>',
							'meals'       => array( 'dinner' ),
						),
						array(
							'title' => '',
						),
					),
					TripMeta::HIGHLIGHTS => array( 'Glacier views', '', '  ' ),
					TripMeta::FAQS       => array(
						array(
							'question' => 'Is it hard?',
							'answer'   => 'Moderate.',
						),
						array(
							'question' => '',
							'answer'   => 'Orphan answer',
						),
					),
					TripMeta::MAP        => array(
						'lat'  => 46.5,
						'lng'  => 8.25,
						'zoom' => 9,
					),
				),
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$id = $response->get_data()['id'];

		$this->assertSame( 'ALT-01', get_post_meta( $id, TripMeta::CODE, true ) );
		$this->assertSame(
			array(
				'days'   => 5,
				'nights' => 4,
				'hours'  => 0,
			),
			get_post_meta( $id, TripMeta::DURATION, true )
		);
		$this->assertSame(
			array(
				'min' => 4,
				'max' => 4,
			),
			get_post_meta( $id, TripMeta::GROUP_SIZE, true ),
			'Max is raised to min.'
		);

		$itinerary = get_post_meta( $id, TripMeta::ITINERARY, true );
		$this->assertCount( 1, $itinerary, 'Empty days are dropped.' );
		$this->assertStringNotContainsString( '<script', $itinerary[0]['description'] );
		$this->assertStringContainsString( '<p>Meet the guide.</p>', $itinerary[0]['description'] );
		$this->assertSame( array( 'dinner' ), $itinerary[0]['meals'] );

		$this->assertSame( array( 'Glacier views' ), get_post_meta( $id, TripMeta::HIGHLIGHTS, true ) );
		$this->assertCount( 1, get_post_meta( $id, TripMeta::FAQS, true ) );
		$this->assertSame( 46.5, get_post_meta( $id, TripMeta::MAP, true )['lat'] );

		$read = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/trips/' . $id ) );
		$this->assertSame( 'moderate', $read->get_data()['meta'][ TripMeta::DIFFICULTY ] );
	}

	public function test_rest_rejects_values_outside_the_schema(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$trip = self::factory()->post->create( array( 'post_type' => PostTypes::TRIP ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/trips/' . $trip );
		$request->set_body_params(
			array(
				'meta' => array(
					TripMeta::ITINERARY => array(
						array(
							'title' => 'Day 1',
							'meals' => array( 'brunch' ),
						),
					),
				),
			)
		);

		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		$request->set_body_params( array( 'meta' => array( TripMeta::MAP => array( 'lat' => 120 ) ) ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_sanitizers_handle_direct_writes(): void {
		$trip = self::factory()->post->create( array( 'post_type' => PostTypes::TRIP ) );

		update_post_meta( $trip, TripMeta::DIFFICULTY, 'impossible' );
		update_post_meta( $trip, TripMeta::GALLERY, array( '12', 12, 0, 'x', 7 ) );
		update_post_meta( $trip, TripMeta::MAP, array( 'lat' => 999 ) );

		$this->assertSame( '', get_post_meta( $trip, TripMeta::DIFFICULTY, true ) );
		$this->assertSame( array( 12, 7 ), get_post_meta( $trip, TripMeta::GALLERY, true ) );
		$this->assertSame( 90.0, get_post_meta( $trip, TripMeta::MAP, true )['lat'] );
	}

	public function test_subscribers_cannot_write_trip_meta(): void {
		$trip = self::factory()->post->create( array( 'post_type' => PostTypes::TRIP ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( current_user_can( 'edit_post_meta', $trip, TripMeta::CODE ) );
	}

	public function test_uninstall_deletes_trips_and_terms_only_when_enabled(): void {
		$trip = self::factory()->post->create( array( 'post_type' => PostTypes::TRIP ) );
		$term = self::factory()->term->create( array( 'taxonomy' => PostTypes::DESTINATION ) );

		Uninstaller::run();
		$this->assertNotNull( get_post( $trip ), 'Default settings keep trips.' );

		( new Options() )->update( array( 'general' => array( 'delete_data_on_uninstall' => true ) ) );
		Uninstaller::run();

		$this->assertNull( get_post( $trip ) );
		$this->assertNull( get_term( $term, PostTypes::DESTINATION ) );

		// Uninstall drops tables, which commits the test transaction; restore them for later tests.
		Schema::install();
		update_option( 'wpitravel_db_version', WPITRAVEL_DB_VERSION );
	}
}
