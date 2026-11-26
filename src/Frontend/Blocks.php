<?php
/**
 * Blocks.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WP_Block;
use WPInterface\Travel\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's blocks from blocks/{name}/block.json. All are
 * dynamic: PHP renders them through Parts, so they always match the
 * classic templates and shortcodes.
 *
 * Trip blocks show the trip given by their tripId attribute, or else the
 * post they are placed in (postId context), which is how block theme
 * templates use them.
 */
final class Blocks {

	/**
	 * Block name => section in Parts::sections().
	 */
	public const TRIP_BLOCKS = array(
		'trip-price'      => 'price',
		'trip-facts'      => 'facts',
		'trip-highlights' => 'highlights',
		'trip-itinerary'  => 'itinerary',
		'trip-includes'   => 'includes',
		'trip-faqs'       => 'faqs',
		'trip-gallery'    => 'gallery',
		'trip-map'        => 'map',
		'booking-form'    => 'booking',
		'trip-reviews'    => 'reviews',
		'trip-enquiry'    => 'enquiry',
	);

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	/**
	 * Registers the script and style handles named in block.json.
	 */
	public function register_assets(): void {
		foreach ( array(
			'blocks'   => 'wpitravel-blocks-editor',
			'frontend' => 'wpitravel-frontend',
		) as $entry => $handle ) {
			$asset_file = WPITRAVEL_PATH . 'assets/build/' . $entry . '.asset.php';

			if ( ! is_readable( $asset_file ) ) {
				continue;
			}

			$asset = require $asset_file;

			if ( 'blocks' === $entry ) {
				wp_register_script( $handle, WPITRAVEL_URL . 'assets/build/blocks.js', $asset['dependencies'], $asset['version'], true );
				wp_set_script_translations( $handle, 'wpinterface-travel' );
				wp_add_inline_script( $handle, 'var wpitravelBlocks = ' . wp_json_encode( self::editor_data(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
				wp_register_style( $handle, WPITRAVEL_URL . 'assets/build/blocks.css', array(), $asset['version'] );
				wp_style_add_data( $handle, 'rtl', 'replace' );
				continue;
			}

			wp_register_style( $handle, WPITRAVEL_URL . 'assets/build/style-frontend.css', array(), $asset['version'] );
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}

	/**
	 * Registers each block with its render callback.
	 */
	public function register_blocks(): void {
		register_block_type( WPITRAVEL_PATH . 'blocks/trips', array( 'render_callback' => array( $this, 'render_trips' ) ) );
		register_block_type( WPITRAVEL_PATH . 'blocks/trip-search', array( 'render_callback' => array( $this, 'render_search' ) ) );

		foreach ( self::TRIP_BLOCKS as $block => $section ) {
			register_block_type(
				WPITRAVEL_PATH . 'blocks/' . $block,
				array(
					'render_callback' => fn( array $attributes, string $content, WP_Block $instance ): string => $this->render_section( $section, $attributes, $instance ),
				)
			);
		}
	}

	/**
	 * Adds the "Travel" block category.
	 *
	 * @param array<int, array<string, mixed>> $categories Categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function category( $categories ): array {
		$categories = is_array( $categories ) ? $categories : array();

		array_unshift(
			$categories,
			array(
				'slug'  => 'wpitravel',
				'title' => __( 'Travel', 'wpinterface-travel' ),
				'icon'  => null,
			)
		);

		return $categories;
	}

	/**
	 * Renders a trip section block.
	 *
	 * @param string               $section    Section name.
	 * @param array<string, mixed> $attributes Attributes.
	 * @param WP_Block             $block      Block instance.
	 */
	public function render_section( string $section, array $attributes, WP_Block $block ): string {
		$trip_id = absint( $attributes['tripId'] ?? 0 );

		if ( ! $trip_id && PostTypes::TRIP === ( $block->context['postType'] ?? '' ) ) {
			$trip_id = absint( $block->context['postId'] ?? 0 );
		}

		$trip = TripData::resolve( $trip_id );

		if ( ! $trip || ! TripData::is_viewable( $trip ) ) {
			return self::placeholder( __( 'Shows the trip\'s details on a trip page, or pick a trip in the block settings.', 'wpinterface-travel' ) );
		}

		$sections = Parts::sections();
		$html     = (string) call_user_func( $sections[ $section ], $trip->ID, array( 'open' => ! empty( $attributes['open'] ) ) );

		if ( '' === $html ) {
			return self::placeholder( __( 'This trip has nothing to show here yet.', 'wpinterface-travel' ) );
		}

		return self::wrap( $html, 'wpitravel-block-' . $section );
	}

	/**
	 * Renders the trips block.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	public function render_trips( array $attributes ): string {
		return self::wrap(
			Parts::trips(
				array(
					'number'       => (int) ( $attributes['number'] ?? 6 ),
					'columns'      => (int) ( $attributes['columns'] ?? 3 ),
					'layout'       => (string) ( $attributes['layout'] ?? 'grid' ),
					'sort'         => (string) ( $attributes['sort'] ?? '' ),
					'destination'  => (string) ( $attributes['destination'] ?? '' ),
					'activity'     => (string) ( $attributes['activity'] ?? '' ),
					'trip_type'    => (string) ( $attributes['tripType'] ?? '' ),
					'ids'          => array_map( 'absint', (array) ( $attributes['ids'] ?? array() ) ),
					'inherit'      => ! empty( $attributes['inherit'] ),
					'filters'      => ! empty( $attributes['filters'] ),
					'show_price'   => $attributes['showPrice'] ?? true,
					'show_rating'  => $attributes['showRating'] ?? true,
					'show_excerpt' => $attributes['showExcerpt'] ?? true,
				)
			),
			'wpitravel-block-trips'
		);
	}

	/**
	 * Renders the search block.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	public function render_search( array $attributes ): string {
		$atts = array(
			'fields' => array_values( array_filter( (array) ( $attributes['fields'] ?? array() ), 'is_string' ) ),
			'target' => (string) ( $attributes['target'] ?? 'archive' ),
		);

		if ( '' !== (string) ( $attributes['buttonText'] ?? '' ) ) {
			$atts['button'] = (string) $attributes['buttonText'];
		}

		return self::wrap( Parts::search( $atts ), 'wpitravel-block-search' );
	}

	/**
	 * Wraps block output with the block's wrapper attributes (classes, spacing).
	 *
	 * @param string $html       Inner HTML.
	 * @param string $class_name Extra class.
	 */
	private static function wrap( string $html, string $class_name ): string {
		// Loaded only when a block renders; classic themes also load it in the head
		// for pages whose content has these blocks (Templates::enqueue()).
		Templates::enqueue_frontend_style();

		$attributes = \WP_Block_Supports::$block_to_render ? get_block_wrapper_attributes( array( 'class' => $class_name ) ) : 'class="' . esc_attr( $class_name ) . '"';

		return '<div ' . $attributes . '>' . $html . '</div>';
	}

	/**
	 * A note shown only in the editor; empty on the site.
	 *
	 * @param string $message Message.
	 */
	private static function placeholder( string $message ): string {
		if ( ! wp_is_serving_rest_request() ) {
			return '';
		}

		return '<div class="wpitravel-block-placeholder">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Data for the block editor's controls.
	 *
	 * @return array<string, mixed>
	 */
	private static function editor_data(): array {
		return array(
			'sorts' => array(
				array(
					'value' => '',
					'label' => __( 'Recommended', 'wpinterface-travel' ),
				),
				array(
					'value' => 'date',
					'label' => __( 'Newest', 'wpinterface-travel' ),
				),
				array(
					'value' => 'price',
					'label' => __( 'Price: low to high', 'wpinterface-travel' ),
				),
				array(
					'value' => 'price-desc',
					'label' => __( 'Price: high to low', 'wpinterface-travel' ),
				),
				array(
					'value' => 'duration',
					'label' => __( 'Shortest first', 'wpinterface-travel' ),
				),
				array(
					'value' => 'rating',
					'label' => __( 'Top rated', 'wpinterface-travel' ),
				),
				array(
					'value' => 'title',
					'label' => __( 'Name', 'wpinterface-travel' ),
				),
			),
		);
	}
}
