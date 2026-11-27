<?php
/**
 * Elementor widget: trip price.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_price] as an Elementor widget.
 */
final class TripPriceWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-price';
	protected const ICON      = 'eicon-price-list';
	protected const SHORTCODE = 'wpitravel_trip_price';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Price', 'wpinterface-travel' );
	}
}
