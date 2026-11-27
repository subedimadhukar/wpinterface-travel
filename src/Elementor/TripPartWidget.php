<?php
/**
 * Base for the Elementor widgets that show one part of a trip.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * One trip part ([wpitravel_trip_*], booking form, reviews, enquiry), for
 * the current trip or a chosen one.
 */
abstract class TripPartWidget extends Widget {

	/**
	 * Widget ID and title, icon and shortcode, set by each widget.
	 */
	protected const NAME      = '';
	protected const ICON      = 'eicon-info-box';
	protected const SHORTCODE = '';

	/**
	 * Widget ID.
	 */
	public function get_name(): string {
		return static::NAME;
	}

	/**
	 * Panel icon.
	 */
	public function get_icon(): string {
		return static::ICON;
	}

	/**
	 * Shortcode it prints.
	 */
	protected function shortcode(): string {
		return static::SHORTCODE;
	}

	/**
	 * Controls: the trip, and any the widget adds.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content', array( 'label' => $this->get_title() ) );
		$this->trip_control();
		$this->more_controls();
		$this->end_controls_section();
	}

	/**
	 * Extra controls (none by default).
	 */
	protected function more_controls(): void {
	}

	/**
	 * Shortcode attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function atts( array $settings ): array {
		return array( 'trip' => self::trip_id( $settings ) );
	}
}
