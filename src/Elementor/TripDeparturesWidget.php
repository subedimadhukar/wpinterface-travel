<?php
/**
 * Elementor widget: trip departures.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_departures] as an Elementor widget.
 */
final class TripDeparturesWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-departures';
	protected const ICON      = 'eicon-calendar';
	protected const SHORTCODE = 'wpitravel_trip_departures';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Departures', 'wpinterface-travel' );
	}

	/**
	 * How many departures.
	 */
	protected function more_controls(): void {
		$this->add_control(
			'number',
			array(
				'label'   => __( 'Number of departures', 'wpinterface-travel' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 50,
				'default' => 6,
			)
		);
	}

	/**
	 * Shortcode attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function atts( array $settings ): array {
		return array(
			'trip'   => self::trip_id( $settings ),
			'number' => (string) max( 1, absint( $settings['number'] ?? 6 ) ),
		);
	}
}
