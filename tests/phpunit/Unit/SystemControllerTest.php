<?php
/**
 * System endpoint tests.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_REST_Request;
use WP_UnitTestCase;
use WPInterface\Travel\Install\Activator;
use WPInterface\Travel\Options;

/**
 * Covers GET /wpinterface-travel/v1/system.
 */
class SystemControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Activator::activate_site();

		// Earlier tests may leave other values in the shared settings cache.
		delete_option( Options::OPTION );
		wpitravel()->options()->flush();

		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function test_guests_are_rejected(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wpinterface-travel/v1/system' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'wpitravel_forbidden', $response->get_data()['code'] );
	}

	public function test_editors_without_settings_capability_are_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wpinterface-travel/v1/system' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_administrators_get_status(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wpinterface-travel/v1/system' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['tables_ok'] );
		$this->assertSame( WPITRAVEL_DB_VERSION, $data['db_version'] );
		$this->assertSame( 'USD', $data['currency'] );
	}
}
