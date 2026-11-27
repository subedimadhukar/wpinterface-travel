<?php
/**
 * Elementor widget: trip search.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trip_search] as an Elementor widget.
 */
final class TripSearchWidget extends Widget {

	/**
	 * Fields it can show, in order.
	 */
	private const FIELDS = array( 'q', 'destination', 'activity', 'trip_type', 'difficulty', 'duration', 'price', 'sort' );

	/**
	 * Widget ID.
	 */
	public function get_name(): string {
		return 'wpitravel-trip-search';
	}

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trip Search', 'wpinterface-travel' );
	}

	/**
	 * Panel icon.
	 */
	public function get_icon(): string {
		return 'eicon-search';
	}

	/**
	 * Shortcode it prints.
	 */
	protected function shortcode(): string {
		return 'wpitravel_trip_search';
	}

	/**
	 * Controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content', array( 'label' => __( 'Trip Search', 'wpinterface-travel' ) ) );

		$this->add_control(
			'fields',
			array(
				'label'       => __( 'Fields', 'wpinterface-travel' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'q'           => __( 'Search words', 'wpinterface-travel' ),
					'destination' => __( 'Destination', 'wpinterface-travel' ),
					'activity'    => __( 'Activity', 'wpinterface-travel' ),
					'trip_type'   => __( 'Trip type', 'wpinterface-travel' ),
					'difficulty'  => __( 'Difficulty', 'wpinterface-travel' ),
					'duration'    => __( 'Length', 'wpinterface-travel' ),
					'price'       => __( 'Price', 'wpinterface-travel' ),
					'sort'        => __( 'Sort', 'wpinterface-travel' ),
				),
				'default'     => array( 'q', 'destination', 'activity', 'trip_type', 'duration', 'price', 'sort' ),
			)
		);
		$this->add_control(
			'target',
			array(
				'label'   => __( 'Show results on', 'wpinterface-travel' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'archive' => __( 'The trips page', 'wpinterface-travel' ),
					'current' => __( 'This page (with a Trips widget)', 'wpinterface-travel' ),
				),
				'default' => 'archive',
			)
		);
		$this->add_control(
			'button',
			array(
				'label' => __( 'Button text', 'wpinterface-travel' ),
				'type'  => Controls_Manager::TEXT,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Shortcode attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function atts( array $settings ): array {
		$fields = array_values( array_intersect( self::FIELDS, array_map( 'strval', (array) ( $settings['fields'] ?? array() ) ) ) );

		return array(
			'fields' => implode( ',', $fields ? $fields : self::FIELDS ),
			'target' => 'current' === ( $settings['target'] ?? '' ) ? 'current' : 'archive',
			'button' => sanitize_text_field( (string) ( $settings['button'] ?? '' ) ),
		);
	}
}
