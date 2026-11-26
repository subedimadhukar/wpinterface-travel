<?php
/**
 * Coupons REST endpoint.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;

/**
 * Covers /coupons for staff.
 */
class CouponsRestTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
		update_option( 'timezone_string', 'Europe/Berlin' );
	}

	public function test_only_coupon_managers_can_use_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->api( 'GET', '/coupons' )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/coupons', array( 'code' => 'X' ) )->get_status() );
	}

	public function test_create_list_update_delete(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$created = $this->api(
			'POST',
			'/coupons',
			array(
				'code'          => 'summer-2030',
				'discount_type' => 'percent',
				'amount'        => '15',
				'usage_limit'   => 0,
				'starts_at'     => '2030-06-01',
				'expires_at'    => '2030-08-31 23:59',
				'trip_ids'      => array( $this->trip ),
			)
		);

		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );

		$coupon = $created->get_data();
		$this->assertSame( 'SUMMER-2030', $coupon['code'] );
		$this->assertSame( '15', $coupon['amount'] );
		$this->assertNull( $coupon['usage_limit'], '0 means unlimited.' );
		$this->assertSame( '2030-06-01 00:00', $coupon['starts_at'], 'Shown in the site timezone.' );
		$this->assertSame( array( $this->trip ), $coupon['trip_ids'] );

		$list = $this->api( 'GET', '/coupons' );
		$this->assertSame( '1', (string) $list->get_headers()['X-WP-Total'] );

		$updated = $this->api( 'PATCH', '/coupons/' . $coupon['id'], array( 'status' => 'inactive' ) );
		$this->assertSame( 'inactive', $updated->get_data()['status'] );
		$this->assertSame( '15', $updated->get_data()['amount'], 'Fields not sent are kept.' );

		$this->assertSame( 200, $this->api( 'DELETE', '/coupons/' . $coupon['id'] )->get_status() );
		$this->assertSame( 404, $this->api( 'GET', '/coupons/' . $coupon['id'] )->get_status() );
	}

	public function test_validation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->api(
			'POST',
			'/coupons',
			array(
				'code'   => 'TAKEN',
				'amount' => '5',
			)
		);

		$cases = array(
			'duplicate code'      => array(
				array(
					'code'   => 'taken',
					'amount' => '5',
				),
				409,
				'wpitravel_coupon_exists',
			),
			'short code'          => array(
				array(
					'code'   => 'A',
					'amount' => '5',
				),
				400,
				'wpitravel_invalid_coupon_data',
			),
			'over 100 percent'    => array(
				array(
					'code'   => 'HUGE',
					'amount' => '150',
				),
				400,
				'wpitravel_invalid_coupon_data',
			),
			'ends before start'   => array(
				array(
					'code'       => 'BACKWARDS',
					'amount'     => '5',
					'starts_at'  => '2030-02-01',
					'expires_at' => '2030-01-01',
				),
				400,
				'wpitravel_invalid_coupon_data',
			),
			'invalid date format' => array(
				array(
					'code'       => 'BADDATE',
					'amount'     => '5',
					'expires_at' => '31/12/2030',
				),
				400,
				'wpitravel_invalid_coupon_data',
			),
		);

		foreach ( $cases as $label => $case ) {
			$response = $this->api( 'POST', '/coupons', $case[0] );
			$this->assertSame( $case[1], $response->get_status(), $label );
			$this->assertSame( $case[2], $response->get_data()['code'], $label );
		}
	}
}
