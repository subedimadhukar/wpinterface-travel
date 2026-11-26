<?php
/**
 * Shared set-up for booking tests.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_REST_Response;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Options;

/**
 * A published trip with Adult 100 / Child 50, departing 1 May 2030.
 */
trait BookingFixtures {

	/**
	 * Trip under test.
	 *
	 * @var int
	 */
	protected int $trip;

	/**
	 * Resets settings, REST and creates the trip. Call from set_up().
	 *
	 * @param int $capacity Seats per departure (0 = unlimited).
	 */
	protected function set_up_booking( int $capacity = 10 ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();
		( new TripMeta() )->register_meta();

		delete_option( Options::OPTION );
		wpitravel()->options()->flush();
		add_filter( 'wpitravel_rate_limit', '__return_zero' );

		$this->trip = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'publish',
				'post_title'  => 'Lake Trek',
			)
		);
		update_post_meta(
			$this->trip,
			TripMeta::PRICING,
			array(
				'options' => array(
					array(
						'key'        => 'standard',
						'label'      => 'Standard',
						'max_pax'    => 6,
						'categories' => array(
							array(
								'key'   => 'adult',
								'label' => 'Adult',
								'price' => '100',
								'min'   => 1,
							),
							array(
								'key'   => 'child',
								'label' => 'Child',
								'price' => '50',
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
				'fixed'        => array( array( 'date' => '2030-05-01' ) ),
				'capacity'     => $capacity,
				'advance_days' => 0,
			)
		);

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * A selection for the trip's departure.
	 *
	 * @param array<string, int> $pax Counts.
	 * @return array<string, mixed>
	 */
	protected function selection( array $pax = array( 'adult' => 2 ) ): array {
		return array(
			'trip_id'      => $this->trip,
			'date'         => '2030-05-01',
			'price_option' => 'standard',
			'pax'          => $pax,
		);
	}

	/**
	 * Sends a JSON request to the plugin namespace.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below the namespace.
	 * @param array  $body   JSON body.
	 */
	protected function api( string $method, string $path, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wpinterface-travel/v1' . $path );

		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Holds seats and returns the hold key.
	 *
	 * @param array<string, int> $pax Counts.
	 */
	protected function hold( array $pax = array( 'adult' => 2 ) ): string {
		$response = $this->api( 'POST', '/holds', $this->selection( $pax ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data()['hold_key'];
	}

	/**
	 * Books with a hold.
	 *
	 * @param string $hold_key Hold key.
	 * @param array  $extra    Extra body fields.
	 */
	protected function book( string $hold_key, array $extra = array() ): WP_REST_Response {
		return $this->api(
			'POST',
			'/bookings',
			array_merge(
				array(
					'hold_key' => $hold_key,
					'customer' => array(
						'email'      => 'ada@example.com',
						'first_name' => 'Ada',
						'last_name'  => 'Lovelace',
						'phone'      => '+44 20 1234',
					),
				),
				$extra
			)
		);
	}

	/**
	 * Creates a coupon.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	protected function coupon( array $fields ): array {
		$coupon = ( new \WPInterface\Travel\Booking\Coupons() )->save( $fields );
		$this->assertIsArray( $coupon );

		return $coupon;
	}
}
