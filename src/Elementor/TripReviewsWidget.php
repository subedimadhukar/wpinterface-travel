<?php
/**
 * Elementor widget: trip reviews.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_reviews] as an Elementor widget.
 */
final class TripReviewsWidget extends TripPartWidget {

	protected const NAME      = 'wpitravel-trip-reviews';
	protected const ICON      = 'eicon-review';
	protected const SHORTCODE = 'wpitravel_reviews';

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Reviews', 'wpinterface-travel' );
	}
}
