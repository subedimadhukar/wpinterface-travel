<?php
/**
 * Money and pricing rules.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Support\Money;

/**
 * Covers Money parsing/formatting and Pricing sanitizing.
 */
class PricingTest extends WP_UnitTestCase {

	/**
	 * Inputs a customer or admin may type, and what they become.
	 *
	 * @return array<string, array{0: mixed, 1: int, 2: string}>
	 */
	public function money_inputs(): array {
		return array(
			'plain'                    => array( '450', 2, '450.00' ),
			'comma thousands'          => array( '1,250.5', 2, '1250.50' ),
			'comma decimal'            => array( '1250,50', 2, '1250.50' ),
			'european'                 => array( '1.250,50', 2, '1250.50' ),
			'space thousands'          => array( '1 250,50', 2, '1250.50' ),
			'thousands only'           => array( '1,250', 2, '1250.00' ),
			'repeated thousands'       => array( '1.250.000', 2, '1250000.00' ),
			'single dot is decimal'    => array( '1.250', 3, '1.250' ),
			'comma with four decimals' => array( '12,3456', 3, '12.346' ),
			'zero-decimal currency'    => array( '12500.6', 0, '12501' ),
			'three-decimal currency'   => array( '12.3456', 3, '12.346' ),
			'number'                   => array( 99.999, 2, '100.00' ),
			'empty'                    => array( '', 2, '' ),
			'negative'                 => array( '-5', 2, '' ),
			'text'                     => array( 'free', 2, '' ),
		);
	}

	/**
	 * @dataProvider money_inputs
	 *
	 * @param mixed  $input    Input.
	 * @param int    $decimals Decimals.
	 * @param string $expected Expected output.
	 */
	public function test_money_normalize( $input, int $decimals, string $expected ): void {
		$this->assertSame( $expected, Money::normalize( $input, $decimals ) );
	}

	public function test_money_minor_units_and_format(): void {
		$this->assertSame( 125050, Money::to_minor( '1250.50' ) );
		$this->assertSame( '1250.50', Money::from_minor( 125050 ) );

		$eur = array(
			'code'               => 'EUR',
			'position'           => 'right_space',
			'thousand_separator' => ' ',
			'decimal_separator'  => ',',
			'decimals'           => 2,
		);
		$this->assertSame( '1 250,50 €', Money::format( '1250.5', $eur ) );
	}

	public function test_sanitize_builds_stable_unique_keys_and_drops_bad_rows(): void {
		$pricing = Pricing::sanitize(
			array(
				'options' => array(
					array(
						'label'      => 'Private Group',
						'min_pax'    => 4,
						'max_pax'    => 2,
						'categories' => array(
							array(
								'label'      => 'Adult',
								'price'      => '500',
								'sale_price' => '450',
							),
							array(
								'label'      => 'Adult',
								'price'      => '300',
								'sale_price' => '300',
							),
							array(
								'label' => 'No price',
								'price' => '',
							),
						),
					),
					array(
						'key'        => 'standard',
						'label'      => 'Renamed later',
						'categories' => array(
							array(
								'label' => 'Adult',
								'price' => '200',
							),
						),
					),
					array(
						'label'      => 'Without prices',
						'categories' => array(),
					),
				),
			),
			2
		);

		$this->assertCount( 2, $pricing['options'], 'Options without priced categories are dropped.' );

		$private = $pricing['options'][0];
		$this->assertSame( 'private-group', $private['key'] );
		$this->assertSame( 4, $private['max_pax'], 'Max travelers is raised to the minimum.' );
		$this->assertCount( 2, $private['categories'], 'Categories without a price are dropped.' );
		$this->assertSame( array( 'adult', 'adult-2' ), array_column( $private['categories'], 'key' ) );
		$this->assertSame( '450.00', $private['categories'][0]['sale_price'] );
		$this->assertSame( '', $private['categories'][1]['sale_price'], 'A sale price that is not lower is ignored.' );

		$this->assertSame( 'standard', $pricing['options'][1]['key'], 'An existing key is kept when the label changes.' );

		$this->assertSame( $pricing, Pricing::sanitize( $pricing, 2 ), 'Sanitizing is idempotent.' );
	}

	public function test_from_price_uses_lowest_first_category(): void {
		$pricing = Pricing::sanitize(
			array(
				'options' => array(
					array(
						'label'      => 'Deluxe',
						'categories' => array(
							array(
								'label' => 'Adult',
								'price' => '900',
							),
						),
					),
					array(
						'label'      => 'Standard',
						'categories' => array(
							array(
								'label'      => 'Adult',
								'price'      => '600',
								'sale_price' => '550',
							),
							array(
								'label' => 'Child',
								'price' => '100',
							),
						),
					),
				),
			),
			2
		);

		$this->assertSame(
			array(
				'price'   => '550.00',
				'regular' => '600.00',
				'sale'    => true,
			),
			Pricing::from_price( $pricing, '', 2 )
		);
		$this->assertSame( '900.00', Pricing::from_price( $pricing, 'deluxe', 2 )['price'] );
		$this->assertNull( Pricing::from_price( Pricing::empty_value(), '', 2 ) );
	}
}
