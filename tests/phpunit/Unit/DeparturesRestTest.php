<?php
/**
 * Availability and departures REST endpoints.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Install\Schema;

/**
 * Covers GET /trips/{id}/availability and the staff departure routes.
 *
 * Uses dates in 2030 with no booking window, so the real clock does not matter.
 */
class DeparturesRestTest extends WP_UnitTestCase {

	/**
	 * Published trip.
	 *
	 * @var int
	 */
	private int $trip;

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();
		( new TripMeta() )->register_meta();

		$this->trip = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$this->trip,
			TripMeta::PRICING,
			array(
				'options' => array(
					array(
						'label'      => 'Standard',
						'categories' => array(
							array(
								'label' => 'Adult',
								'price' => '250',
							),
						),
					),
				),
			)
		);
		update_post_meta(
			$this->trip,
			TripMeta::SCHEDULE,
			array(
				'fixed'        => array( array( 'date' => '2030-05-01' ), array( 'date' => '2030-05-08' ) ),
				'advance_days' => 0,
			)
		);

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * Runs a request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below the namespace.
	 * @param array  $params Query or body params.
	 */
	private function request( string $method, string $path, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wpinterface-travel/v1' . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}

	public function test_guests_see_departures_of_published_trips(): void {
		wp_set_current_user( 0 );

		$response = $this->request(
			'GET',
			"/trips/{$this->trip}/availability",
			array(
				'from' => '2030-05-01',
				'to'   => '2030-05-31',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( '2030-05-01', '2030-05-08' ), array_column( $data['departures'], 'date' ) );
		$this->assertSame( '250.00', $data['departures'][0]['from_price'] );
		$this->assertArrayNotHasKey( 'seats_booked', $data['departures'][0], 'Guests do not see booking counts.' );
	}

	public function test_drafts_and_other_post_types_are_not_found_for_guests(): void {
		wp_set_current_user( 0 );

		wp_update_post(
			array(
				'ID'          => $this->trip,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 404, $this->request( 'GET', "/trips/{$this->trip}/availability" )->get_status() );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 404, $this->request( 'GET', "/trips/{$page}/availability" )->get_status() );
	}

	public function test_invalid_ranges_are_rejected(): void {
		$cases = array(
			array(
				'from' => '2030-05-10',
				'to'   => '2030-05-01',
			),
			array(
				'from' => '2030-01-01',
				'to'   => '2031-06-01',
			),
			array( 'from' => '2030-02-30' ),
		);

		foreach ( $cases as $params ) {
			$this->assertSame( 400, $this->request( 'GET', "/trips/{$this->trip}/availability", $params )->get_status(), wp_json_encode( $params ) );
		}
	}

	public function test_staff_routes_need_edit_permission(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', "/trips/{$this->trip}/departures" )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->request( 'POST', "/trips/{$this->trip}/departures", array( 'date' => '2030-05-01' ) )->get_status() );
	}

	public function test_staff_can_close_resize_and_add_dates(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$closed = $this->request(
			'POST',
			"/trips/{$this->trip}/departures",
			array(
				'date'   => '2030-05-01',
				'status' => 'closed',
			)
		);
		$this->assertSame( 201, $closed->get_status() );
		$this->assertSame( 'fixed', $closed->get_data()['source'] );

		$extra = $this->request(
			'POST',
			"/trips/{$this->trip}/departures",
			array(
				'date'     => '2030-05-20',
				'time'     => '06:30',
				'capacity' => 8,
			)
		);
		$this->assertSame( 'manual', $extra->get_data()['source'] );
		$this->assertSame( 8, $extra->get_data()['capacity'] );

		$resized = $this->request( 'PATCH', '/departures/' . $extra->get_data()['id'], array( 'capacity' => 5 ) );
		$this->assertSame( 5, $resized->get_data()['capacity'] );

		wp_set_current_user( 0 );
		$public = $this->request(
			'GET',
			"/trips/{$this->trip}/availability",
			array(
				'from' => '2030-05-01',
				'to'   => '2030-05-31',
			)
		)->get_data();

		$this->assertSame( array( '2030-05-08', '2030-05-20' ), array_column( $public['departures'], 'date' ) );
		$this->assertSame( 5, $public['departures'][1]['seats_left'] );
	}

	public function test_departures_with_bookings_cannot_be_deleted(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$row = $this->request( 'POST', "/trips/{$this->trip}/departures", array( 'date' => '2030-05-08' ) )->get_data();
		$wpdb->update( Schema::table( 'departures' ), array( 'seats_booked' => 2 ), array( 'id' => $row['id'] ) );

		$response = $this->request( 'DELETE', '/departures/' . $row['id'] );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wpitravel_departure_has_bookings', $response->get_data()['code'] );

		$wpdb->update( Schema::table( 'departures' ), array( 'seats_booked' => 0 ), array( 'id' => $row['id'] ) );
		$this->assertSame( 200, $this->request( 'DELETE', '/departures/' . $row['id'] )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/departures/' . $row['id'] )->get_status() );
	}
}
