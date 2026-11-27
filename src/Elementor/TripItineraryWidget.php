<?php
/**
 * Elementor widget: trip itinerary.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_itinerary] as an Elementor widget.
 */
final class TripItineraryWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-itinerary';
	protected const ICON      = 'eicon-time-line';
	protected const SHORTCODE = 'wpitravel_trip_itinerary';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Itinerary', 'wpinterface-travel' );
	}

	/**
	 * "Expand all days".
	 */
	protected function more_controls(): void {
		$this->add_control(
			'open',
			array(
				'label' => __( 'Expand all days', 'wpinterface-travel' ),
				'type'  => Controls_Manager::SWITCHER,
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
			'trip' => self::trip_id( $settings ),
			'open' => self::yes( $settings['open'] ?? '' ),
		);
	}
}
