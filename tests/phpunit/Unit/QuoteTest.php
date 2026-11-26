<?php
/**
 * Quote calculation.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Booking\QuoteCalculator;

/**
 * Covers prices, limits, coupons and tax in quotes.
 */
class QuoteTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_booking();
	}

	public function test_prices_travelers_coupon_and_tax_added_on_top(): void {
		wpitravel()->options()->update(
			array(
				'tax' => array(
					'enabled' => true,
					'rate'    => 10,
					'label'   => 'VAT',
				),
			)
		);
		$this->coupon(
			array(
				'code'          => 'spring10',
				'discount_type' => 'percent',
				'amount'        => '10',
			)
		);

		$quote = ( new QuoteCalculator() )->calculate(
			$this->selection(
				array(
					'adult' => 2,
					'child' => 1,
				)
			) + array( 'coupon' => 'SPRING10' )
		);

		$this->assertIsArray( $quote );
		$this->assertSame( '250.00', $quote['subtotal'] );
		$this->assertSame( '25.00', $quote['discount_total'] );
		$this->assertSame( '22.50', $quote['tax_total'], '10% of 225.' );
		$this->assertSame( '247.50', $quote['total'] );
		$this->assertSame( 'SPRING10', $quote['coupon'] );
		$this->assertSame( array( 'pax', 'pax', 'discount', 'tax' ), array_column( $quote['items'], 'type' ) );
		$this->assertSame( 'VAT 10%', $quote['items'][3]['label'] );
	}

	public function test_included_tax_is_shown_but_not_added(): void {
		wpitravel()->options()->update(
			array(
				'tax' => array(
					'enabled'            => true,
					'rate'               => 20,
					'prices_include_tax' => true,
				),
			)
		);

		$quote = ( new QuoteCalculator() )->calculate( $this->selection( array( 'adult' => 1 ) ) );

		$this->assertSame( '100.00', $quote['total'] );
		$this->assertSame( '16.67', $quote['tax_total'], '100 − 100 / 1.2.' );
		$this->assertTrue( $quote['tax_included'] );
	}

	public function test_zero_decimal_currency(): void {
		wpitravel()->options()->update(
			array(
				'currency' => array(
					'code'     => 'JPY',
					'decimals' => 0,
				),
			)
		);
		update_post_meta(
			$this->trip,
			'_wpitravel_pricing',
			array(
				'options' => array(
					array(
						'categories' => array(
							array(
								'label' => 'Adult',
								'price' => '12500',
							),
						),
					),
				),
			)
		);

		$quote = ( new QuoteCalculator() )->calculate( array_merge( $this->selection( array( 'adult' => 3 ) ), array( 'price_option' => '' ) ) );

		$this->assertSame( '37500', $quote['total'] );
		$this->assertSame( 'JPY', $quote['currency'] );
	}

	public function test_traveler_limits_and_seats(): void {
		$calculator = new QuoteCalculator();

		$cases = array(
			'adult minimum'  => array( array( 'child' => 2 ), 'wpitravel_pax_out_of_range' ),
			'option maximum' => array( array( 'adult' => 7 ), 'wpitravel_pax_out_of_range' ),
			'unknown type'   => array(
				array(
					'adult'  => 1,
					'senior' => 1,
				),
				'wpitravel_pax_out_of_range',
			),
			'nobody'         => array( array(), 'wpitravel_pax_out_of_range' ),
		);

		foreach ( $cases as $label => $case ) {
			$result = $calculator->calculate( $this->selection( $case[0] ) );
			$this->assertWPError( $result, $label );
			$this->assertSame( $case[1], $result->get_error_code(), $label );
		}

		update_post_meta(
			$this->trip,
			'_wpitravel_departure_rules',
			array(
				'fixed'        => array( array( 'date' => '2030-05-01' ) ),
				'capacity'     => 2,
				'advance_days' => 0,
			)
		);

		$result = $calculator->calculate( $this->selection( array( 'adult' => 3 ) ) );
		$this->assertSame( 'wpitravel_not_enough_seats', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['seats_left'] );
	}

	public function test_unavailable_dates_and_unpublished_trips(): void {
		$calculator = new QuoteCalculator();

		$wrong_day = $calculator->calculate( array_merge( $this->selection(), array( 'date' => '2030-05-02' ) ) );
		$this->assertSame( 'wpitravel_date_unavailable', $wrong_day->get_error_code() );

		wp_update_post(
			array(
				'ID'          => $this->trip,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'wpitravel_trip_not_found', $calculator->calculate( $this->selection() )->get_error_code() );
	}

	public function test_invalid_coupon_is_a_message_in_quotes(): void {
		$response = $this->api( 'POST', '/quote', $this->selection() + array( 'coupon' => 'NOPE' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '200.00', $response->get_data()['total'] );
		$this->assertSame( 'coupon', $response->get_data()['messages'][0]['type'] );
		$this->assertArrayNotHasKey( 'coupon_id', $response->get_data(), 'Internal IDs are not exposed.' );
	}

	public function test_coupon_rules(): void {
		$calculator = new QuoteCalculator();

		$this->coupon(
			array(
				'code'          => 'BIG',
				'discount_type' => 'fixed',
				'amount'        => '500',
			)
		);
		$this->coupon(
			array(
				'code'          => 'MIN300',
				'discount_type' => 'percent',
				'amount'        => '5',
				'min_subtotal'  => '300',
			)
		);
		$this->coupon(
			array(
				'code'          => 'OTHERTRIP',
				'discount_type' => 'percent',
				'amount'        => '5',
				'trip_ids'      => array( $this->trip + 1000 ),
			)
		);
		$this->coupon(
			array(
				'code'          => 'OLD',
				'discount_type' => 'percent',
				'amount'        => '5',
				'starts_at'     => '2020-01-01',
				'expires_at'    => '2020-12-31',
			)
		);
		$this->coupon(
			array(
				'code'          => 'OFF',
				'discount_type' => 'percent',
				'amount'        => '5',
				'status'        => 'inactive',
			)
		);

		$big = $calculator->calculate( $this->selection() + array( 'coupon' => 'big' ) );
		$this->assertSame( '0.00', $big['total'], 'A fixed discount never goes below zero.' );

		foreach ( array( 'MIN300', 'OTHERTRIP', 'OLD', 'OFF' ) as $code ) {
			$quote = $calculator->calculate( $this->selection() + array( 'coupon' => $code ) );
			$this->assertSame( '200.00', $quote['total'], $code );
			$this->assertCount( 1, $quote['messages'], $code );
		}
	}
}
