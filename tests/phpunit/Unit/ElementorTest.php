<?php
/**
 * Elementor widgets.
 *
 * Runs only where Elementor is installed next to the plugin and loaded by
 * the test bootstrap (WPITRAVEL_TESTS_ELEMENTOR=1); skipped otherwise.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Tests\Unit;

use WP_UnitTestCase;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Elementor\Elementor;

require_once __DIR__ . '/BookingFixtures.php';

/**
 * Every widget registers and prints the same HTML as its shortcode.
 */
class ElementorTest extends WP_UnitTestCase {

	use BookingFixtures;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is not loaded.' );
		}

		$this->set_up_booking();
		update_post_meta(
			$this->trip,
			TripMeta::SCHEDULE,
			array(
				'fixed'        => array( array( 'date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ) ),
				'capacity'     => 8,
				'advance_days' => 0,
			)
		);
		update_post_meta( $this->trip, TripMeta::ITINERARY, array( array( 'title' => 'Arrive' ), array( 'title' => 'Walk' ) ) );
	}

	public function test_every_widget_is_registered_in_the_travel_category(): void {
		$types = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();

		foreach ( Elementor::WIDGETS as $class ) {
			$name = ( new ( 'WPInterface\\Travel\\Elementor\\' . $class )() )->get_name();

			$this->assertArrayHasKey( $name, $types, $class );
			$this->assertSame( array( 'wpinterface-travel' ), $types[ $name ]->get_categories() );
		}

		$this->assertArrayHasKey( 'wpinterface-travel', \Elementor\Plugin::$instance->elements_manager->get_categories() );
	}

	public function test_widgets_print_the_shortcodes_html(): void {
		$cases = array(
			'wpitravel-trips'           => array( array( 'number' => 3 ), 'wpitravel-card' ),
			'wpitravel-trip-search'     => array( array( 'fields' => array( 'q', 'price' ) ), 'name="trip_q"' ),
			'wpitravel-trip-itinerary'  => array(
				array(
					'trip' => (string) $this->trip,
					'open' => 'yes',
				),
				'Arrive',
			),
			'wpitravel-trip-departures' => array(
				array(
					'trip'   => (string) $this->trip,
					'number' => 1,
				),
				'wpitravel-departures__book',
			),
			'wpitravel-trip-price'      => array( array( 'trip' => (string) $this->trip ), 'wpitravel-price' ),
			'wpitravel-booking-form'    => array( array( 'trip' => (string) $this->trip ), 'id="wpitravel-booking"' ),
		);

		foreach ( $cases as $type => $case ) {
			$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
				array(
					'id'         => substr( md5( $type ), 0, 7 ),
					'elType'     => 'widget',
					'widgetType' => $type,
					'settings'   => $case[0],
				)
			);
			$this->assertNotNull( $widget, $type );

			ob_start();
			$widget->print_element();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( $case[1], $html, $type );
		}

		$itinerary = do_shortcode( '[wpitravel_trip_itinerary trip="' . $this->trip . '" open="yes"]' );
		$this->assertStringContainsString( 'open', $itinerary );
	}

	public function test_a_password_protected_trip_prints_nothing(): void {
		wp_update_post(
			array(
				'ID'            => $this->trip,
				'post_password' => 'secret',
			)
		);

		$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			array(
				'id'         => 'pw12345',
				'elType'     => 'widget',
				'widgetType' => 'wpitravel-trip-itinerary',
				'settings'   => array( 'trip' => (string) $this->trip ),
			)
		);

		ob_start();
		$widget->print_element();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Arrive', $html );
	}
}
