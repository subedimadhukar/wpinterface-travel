<?php
/**
 * Settings endpoint tests.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Options;

/**
 * Covers GET and POST /wpinterface-travel/v1/settings.
 */
class SettingsControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();
		delete_option( Options::OPTION );
		wpitravel()->options()->flush();

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * Sends a JSON POST to /settings.
	 *
	 * @param array $body Body.
	 */
	private function post( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wpinterface-travel/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	public function test_editors_cannot_read_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/wpinterface-travel/v1/settings' ) )->get_status() );
		$this->assertSame( 403, $this->post( array( 'currency' => array( 'code' => 'EUR' ) ) )->get_status() );
	}

	public function test_get_returns_settings_and_choices(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wpinterface-travel/v1/settings' ) )->get_data();

		$this->assertSame( 'USD', $data['settings']['currency']['code'] );
		$this->assertContains( 'JPY', wp_list_pluck( $data['currencies'], 'code' ) );
		$this->assertNotEmpty( $data['positions'] );
	}

	public function test_post_saves_partial_settings(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->post(
			array(
				'currency' => array(
					'code'               => 'eur',
					'thousand_separator' => ' ',
					'decimal_separator'  => ',',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'EUR', $response->get_data()['settings']['currency']['code'] );

		$fresh = new Options();
		$this->assertSame( ' ', $fresh->get( 'currency.thousand_separator' ), 'A space separator survives saving and reading.' );
		$this->assertSame( 15, $fresh->get( 'booking.hold_minutes' ), 'Untouched sections keep their values.' );
	}

	public function test_invalid_values_are_rejected_and_nothing_is_saved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$cases = array(
			'currency.code'               => array( 'currency' => array( 'code' => 'XXX' ) ),
			'currency.thousand_separator' => array(
				'currency' => array(
					'thousand_separator' => ',',
					'decimal_separator'  => ',',
				),
			),
			'tax.rate'                    => array( 'tax' => array( 'rate' => 150 ) ),
			'booking.hold_minutes'        => array( 'booking' => array( 'hold_minutes' => 1 ) ),
			'permalinks.trip_base'        => array( 'permalinks' => array( 'destination_base' => 'trips' ) ),
		);

		foreach ( $cases as $field => $body ) {
			$response = $this->post( $body + array( 'general' => array( 'delete_data_on_uninstall' => true ) ) );

			$this->assertSame( 400, $response->get_status(), $field );
			$this->assertSame( 'wpitravel_invalid_setting', $response->get_data()['code'], $field );
			$this->assertSame( $field, $response->get_data()['data']['field'], $field );
		}

		$this->assertFalse( ( new Options() )->get( 'general.delete_data_on_uninstall' ), 'A rejected request saves nothing.' );
	}

	public function test_permalink_change_schedules_rewrite_flush(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'wpitravel_flush_rewrite_rules' );

		$this->post( array( 'currency' => array( 'code' => 'GBP' ) ) );
		$this->assertFalse( get_option( 'wpitravel_flush_rewrite_rules' ) );

		$response = $this->post( array( 'permalinks' => array( 'trip_base' => 'Tours & Holidays' ) ) );

		$this->assertSame( 'tours-holidays', $response->get_data()['settings']['permalinks']['trip_base'] );
		$this->assertSame( '1', (string) get_option( 'wpitravel_flush_rewrite_rules' ) );
	}
}
