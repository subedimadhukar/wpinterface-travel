<?php
/**
 * Elementor widget: trip faqs.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_faqs] as an Elementor widget.
 */
final class TripFaqsWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-faqs';
	protected const ICON      = 'eicon-help-o';
	protected const SHORTCODE = 'wpitravel_trip_faqs';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip FAQs', 'wpinterface-travel' );
	}
}
