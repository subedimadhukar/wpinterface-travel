<?php
/**
 * Settings tests.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Options;

/**
 * Covers defaults, dot-path reads and type-safe updates.
 */
class OptionsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Options::OPTION );
	}

	public function test_defaults_fill_missing_values(): void {
		$options = new Options();

		$this->assertSame( 'USD', $options->get( 'currency.code' ) );
		$this->assertSame( 2, $options->get( 'currency.decimals' ) );
		$this->assertFalse( $options->get( 'general.delete_data_on_uninstall' ) );
		$this->assertSame( 'fallback', $options->get( 'missing.path', 'fallback' ) );
	}

	public function test_update_casts_types_and_drops_unknown_keys(): void {
		$options = new Options();

		$saved = $options->update(
			array(
				'currency' => array(
					'code'     => '<b>EUR</b>',
					'decimals' => '0',
				),
				'tax'      => array( 'rate' => '13.5' ),
				'general'  => array( 'delete_data_on_uninstall' => 'true' ),
				'unknown'  => array( 'x' => 1 ),
			)
		);

		$this->assertIsArray( $saved );
		$this->assertSame( 'EUR', $saved['currency']['code'] );
		$this->assertSame( 0, $saved['currency']['decimals'] );
		$this->assertSame( 13.5, $saved['tax']['rate'] );
		$this->assertTrue( $saved['general']['delete_data_on_uninstall'] );
		$this->assertArrayNotHasKey( 'unknown', $saved );

		$fresh = new Options();
		$this->assertSame( 'EUR', $fresh->get( 'currency.code' ) );
		$this->assertSame( '.', $fresh->get( 'currency.decimal_separator' ), 'Untouched settings keep their values.' );
	}

	public function test_non_array_option_falls_back_to_defaults(): void {
		update_option( Options::OPTION, 'corrupted' );

		$this->assertSame( 'USD', ( new Options() )->get( 'currency.code' ) );
	}
}
