<?php
/**
 * Elementor widget: trips list.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Elementor;

use Elementor\Controls_Manager;
use WPInterface\Travel\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * [wpitravel_trips] as an Elementor widget.
 */
final class TripsWidget extends Widget {

	/**
	 * Widget ID.
	 */
	public function get_name(): string {
		return 'wpitravel-trips';
	}

	/**
	 * Panel title.
	 */
	public function get_title(): string {
		return __( 'Trips', 'wpinterface-travel' );
	}

	/**
	 * Panel icon.
	 */
	public function get_icon(): string {
		return 'eicon-posts-grid';
	}

	/**
	 * Shortcode it prints.
	 */
	protected function shortcode(): string {
		return 'wpitravel_trips';
	}

	/**
	 * Controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section( 'content', array( 'label' => __( 'Trips', 'wpinterface-travel' ) ) );

		$this->add_control(
			'number',
			array(
				'label'   => __( 'Number of trips', 'wpinterface-travel' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 48,
				'default' => 6,
			)
		);
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'wpinterface-travel' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'grid' => __( 'Grid', 'wpinterface-travel' ),
					'list' => __( 'List', 'wpinterface-travel' ),
				),
				'default' => 'grid',
			)
		);
		$this->add_control(
			'columns',
			array(
				'label'     => __( 'Columns', 'wpinterface-travel' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 4,
				'default'   => 3,
				'condition' => array( 'layout' => 'grid' ),
			)
		);
		$this->add_control(
			'sort',
			array(
				'label'   => __( 'Sort by', 'wpinterface-travel' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					''           => __( 'Recommended', 'wpinterface-travel' ),
					'date'       => __( 'Newest', 'wpinterface-travel' ),
					'price'      => __( 'Price: low to high', 'wpinterface-travel' ),
					'price-desc' => __( 'Price: high to low', 'wpinterface-travel' ),
					'duration'   => __( 'Shortest first', 'wpinterface-travel' ),
					'rating'     => __( 'Top rated', 'wpinterface-travel' ),
					'title'      => __( 'Name', 'wpinterface-travel' ),
				),
				'default' => '',
			)
		);

		foreach ( array(
			'destination' => array( PostTypes::DESTINATION, __( 'Destinations', 'wpinterface-travel' ) ),
			'activity'    => array( PostTypes::ACTIVITY, __( 'Activities', 'wpinterface-travel' ) ),
			'trip_type'   => array( PostTypes::TRIP_TYPE, __( 'Trip types', 'wpinterface-travel' ) ),
		) as $key => $taxonomy ) {
			$this->add_control(
				$key,
				array(
					'label'       => $taxonomy[1],
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => self::terms( $taxonomy[0] ),
					'label_block' => true,
				)
			);
		}

		$this->add_control(
			'filters',
			array(
				'label'       => __( 'Follow the search form', 'wpinterface-travel' ),
				'description' => __( 'Apply filters from a Trip Search widget on this page.', 'wpinterface-travel' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => '',
			)
		);

		foreach ( array(
			'show_price'   => __( 'Price', 'wpinterface-travel' ),
			'show_rating'  => __( 'Rating', 'wpinterface-travel' ),
			'show_excerpt' => __( 'Excerpt', 'wpinterface-travel' ),
		) as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'   => $label,
					'type'    => Controls_Manager::SWITCHER,
					'default' => 'yes',
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Shortcode attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function atts( array $settings ): array {
		$list = static fn( $value ): string => implode( ',', array_map( 'sanitize_title', array_map( 'strval', (array) $value ) ) );

		return array(
			'number'       => (string) absint( $settings['number'] ?? 6 ),
			'columns'      => (string) absint( $settings['columns'] ?? 3 ),
			'layout'       => 'list' === ( $settings['layout'] ?? '' ) ? 'list' : 'grid',
			'sort'         => sanitize_key( (string) ( $settings['sort'] ?? '' ) ),
			'destination'  => $list( $settings['destination'] ?? array() ),
			'activity'     => $list( $settings['activity'] ?? array() ),
			'trip_type'    => $list( $settings['trip_type'] ?? array() ),
			'filters'      => self::yes( $settings['filters'] ?? '' ),
			'show_price'   => self::yes( $settings['show_price'] ?? 'yes' ),
			'show_rating'  => self::yes( $settings['show_rating'] ?? 'yes' ),
			'show_excerpt' => self::yes( $settings['show_excerpt'] ?? 'yes' ),
		);
	}

	/**
	 * Terms of a taxonomy, by slug.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<string, string>
	 */
	private static function terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 100,
			)
		);

		return is_array( $terms ) ? wp_list_pluck( $terms, 'name', 'slug' ) : array();
	}
}
