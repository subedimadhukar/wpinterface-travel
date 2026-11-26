<?php
/**
 * Trip post type and taxonomies.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Content;

use WPInterface\Travel\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the trip post type and its destination, activity and trip type taxonomies.
 *
 * Trip editing uses its own capabilities (edit_wpitravel_trips…), so a site
 * can let staff manage trips without giving them blog posts, and Pro can add
 * finer-grained team roles.
 */
final class PostTypes {

	public const TRIP        = 'wpitravel_trip';
	public const DESTINATION = 'wpitravel_destination';
	public const ACTIVITY    = 'wpitravel_activity';
	public const TRIP_TYPE   = 'wpitravel_trip_type';

	/**
	 * Settings.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options Settings.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Hooks registration into `init`.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_types' ), 5 );
	}

	/**
	 * Registers the post type and taxonomies with the slugs from settings.
	 */
	public function register_types(): void {
		self::register_all( (array) $this->options->get( 'permalinks', array() ) );
	}

	/**
	 * Taxonomies attached to trips.
	 *
	 * @return list<string>
	 */
	public static function taxonomies(): array {
		return array( self::DESTINATION, self::ACTIVITY, self::TRIP_TYPE );
	}

	/**
	 * Primitive capabilities of the trip post type (capability_type wpitravel_trip).
	 *
	 * @return list<string>
	 */
	public static function trip_capabilities(): array {
		return array(
			'edit_wpitravel_trips',
			'edit_others_wpitravel_trips',
			'edit_private_wpitravel_trips',
			'edit_published_wpitravel_trips',
			'publish_wpitravel_trips',
			'read_private_wpitravel_trips',
			'delete_wpitravel_trips',
			'delete_others_wpitravel_trips',
			'delete_private_wpitravel_trips',
			'delete_published_wpitravel_trips',
		);
	}

	/**
	 * Registers everything. Static so uninstall can register the types without booting.
	 *
	 * @param array<string, mixed> $bases Permalink bases from settings.
	 */
	public static function register_all( array $bases ): void {
		$bases = wp_parse_args(
			array_filter( $bases ),
			array(
				'trip_base'        => 'trips',
				'destination_base' => 'destination',
				'activity_base'    => 'activity',
				'trip_type_base'   => 'trip-type',
			)
		);

		register_post_type( self::TRIP, self::trip_args( (string) $bases['trip_base'] ) );

		register_taxonomy(
			self::DESTINATION,
			self::TRIP,
			self::taxonomy_args(
				array(
					'name'          => _x( 'Destinations', 'taxonomy general name', 'wpinterface-travel' ),
					'singular_name' => _x( 'Destination', 'taxonomy singular name', 'wpinterface-travel' ),
					'search_items'  => __( 'Search Destinations', 'wpinterface-travel' ),
					'all_items'     => __( 'All Destinations', 'wpinterface-travel' ),
					'parent_item'   => __( 'Parent Destination', 'wpinterface-travel' ),
					'edit_item'     => __( 'Edit Destination', 'wpinterface-travel' ),
					'view_item'     => __( 'View Destination', 'wpinterface-travel' ),
					'update_item'   => __( 'Update Destination', 'wpinterface-travel' ),
					'add_new_item'  => __( 'Add New Destination', 'wpinterface-travel' ),
					'new_item_name' => __( 'New Destination Name', 'wpinterface-travel' ),
					'not_found'     => __( 'No destinations found.', 'wpinterface-travel' ),
					'back_to_items' => __( '&larr; Go to Destinations', 'wpinterface-travel' ),
				),
				(string) $bases['destination_base'],
				'destinations',
				true
			)
		);

		register_taxonomy(
			self::ACTIVITY,
			self::TRIP,
			self::taxonomy_args(
				array(
					'name'          => _x( 'Activities', 'taxonomy general name', 'wpinterface-travel' ),
					'singular_name' => _x( 'Activity', 'taxonomy singular name', 'wpinterface-travel' ),
					'search_items'  => __( 'Search Activities', 'wpinterface-travel' ),
					'all_items'     => __( 'All Activities', 'wpinterface-travel' ),
					'parent_item'   => __( 'Parent Activity', 'wpinterface-travel' ),
					'edit_item'     => __( 'Edit Activity', 'wpinterface-travel' ),
					'view_item'     => __( 'View Activity', 'wpinterface-travel' ),
					'update_item'   => __( 'Update Activity', 'wpinterface-travel' ),
					'add_new_item'  => __( 'Add New Activity', 'wpinterface-travel' ),
					'new_item_name' => __( 'New Activity Name', 'wpinterface-travel' ),
					'not_found'     => __( 'No activities found.', 'wpinterface-travel' ),
					'back_to_items' => __( '&larr; Go to Activities', 'wpinterface-travel' ),
				),
				(string) $bases['activity_base'],
				'activities',
				false
			)
		);

		register_taxonomy(
			self::TRIP_TYPE,
			self::TRIP,
			self::taxonomy_args(
				array(
					'name'          => _x( 'Trip Types', 'taxonomy general name', 'wpinterface-travel' ),
					'singular_name' => _x( 'Trip Type', 'taxonomy singular name', 'wpinterface-travel' ),
					'search_items'  => __( 'Search Trip Types', 'wpinterface-travel' ),
					'all_items'     => __( 'All Trip Types', 'wpinterface-travel' ),
					'parent_item'   => __( 'Parent Trip Type', 'wpinterface-travel' ),
					'edit_item'     => __( 'Edit Trip Type', 'wpinterface-travel' ),
					'view_item'     => __( 'View Trip Type', 'wpinterface-travel' ),
					'update_item'   => __( 'Update Trip Type', 'wpinterface-travel' ),
					'add_new_item'  => __( 'Add New Trip Type', 'wpinterface-travel' ),
					'new_item_name' => __( 'New Trip Type Name', 'wpinterface-travel' ),
					'not_found'     => __( 'No trip types found.', 'wpinterface-travel' ),
					'back_to_items' => __( '&larr; Go to Trip Types', 'wpinterface-travel' ),
				),
				(string) $bases['trip_type_base'],
				'trip-types',
				false
			)
		);
	}

	/**
	 * Arguments for the trip post type.
	 *
	 * @param string $slug Rewrite slug and archive path.
	 * @return array<string, mixed>
	 */
	private static function trip_args( string $slug ): array {
		$labels = array(
			'name'                   => _x( 'Trips', 'post type general name', 'wpinterface-travel' ),
			'singular_name'          => _x( 'Trip', 'post type singular name', 'wpinterface-travel' ),
			'menu_name'              => _x( 'Travel', 'admin menu', 'wpinterface-travel' ),
			'all_items'              => __( 'All Trips', 'wpinterface-travel' ),
			'add_new'                => __( 'Add New Trip', 'wpinterface-travel' ),
			'add_new_item'           => __( 'Add New Trip', 'wpinterface-travel' ),
			'edit_item'              => __( 'Edit Trip', 'wpinterface-travel' ),
			'new_item'               => __( 'New Trip', 'wpinterface-travel' ),
			'view_item'              => __( 'View Trip', 'wpinterface-travel' ),
			'view_items'             => __( 'View Trips', 'wpinterface-travel' ),
			'search_items'           => __( 'Search Trips', 'wpinterface-travel' ),
			'not_found'              => __( 'No trips found.', 'wpinterface-travel' ),
			'not_found_in_trash'     => __( 'No trips found in Trash.', 'wpinterface-travel' ),
			'archives'               => __( 'Trip Archives', 'wpinterface-travel' ),
			'attributes'             => __( 'Trip Attributes', 'wpinterface-travel' ),
			'insert_into_item'       => __( 'Insert into trip', 'wpinterface-travel' ),
			'uploaded_to_this_item'  => __( 'Uploaded to this trip', 'wpinterface-travel' ),
			'featured_image'         => __( 'Trip cover image', 'wpinterface-travel' ),
			'set_featured_image'     => __( 'Set cover image', 'wpinterface-travel' ),
			'remove_featured_image'  => __( 'Remove cover image', 'wpinterface-travel' ),
			'use_featured_image'     => __( 'Use as cover image', 'wpinterface-travel' ),
			'filter_items_list'      => __( 'Filter trips list', 'wpinterface-travel' ),
			'items_list_navigation'  => __( 'Trips list navigation', 'wpinterface-travel' ),
			'items_list'             => __( 'Trips list', 'wpinterface-travel' ),
			'item_published'         => __( 'Trip published.', 'wpinterface-travel' ),
			'item_updated'           => __( 'Trip updated.', 'wpinterface-travel' ),
			'item_scheduled'         => __( 'Trip scheduled.', 'wpinterface-travel' ),
			'item_reverted_to_draft' => __( 'Trip reverted to draft.', 'wpinterface-travel' ),
		);

		$args = array(
			'labels'           => $labels,
			'description'      => __( 'Tours and trips that customers can book.', 'wpinterface-travel' ),
			'public'           => true,
			'show_in_rest'     => true,
			'rest_base'        => 'trips',
			'menu_icon'        => 'dashicons-palmtree',
			'menu_position'    => 26,
			'has_archive'      => $slug,
			'rewrite'          => array(
				'slug'       => $slug,
				'with_front' => false,
			),
			'supports'         => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author', 'custom-fields' ),
			'capability_type'  => array( 'wpitravel_trip', 'wpitravel_trips' ),
			'map_meta_cap'     => true,
			'delete_with_user' => false,
		);

		/**
		 * Filters the trip post type arguments.
		 *
		 * @param array $args Arguments for register_post_type().
		 */
		return (array) apply_filters( 'wpitravel_trip_post_type_args', $args );
	}

	/**
	 * Shared taxonomy arguments.
	 *
	 * @param array<string, string> $labels       Labels.
	 * @param string                $slug         Rewrite slug.
	 * @param string                $rest_base    REST base.
	 * @param bool                  $hierarchical Whether nested URLs are used.
	 * @return array<string, mixed>
	 */
	private static function taxonomy_args( array $labels, string $slug, string $rest_base, bool $hierarchical ): array {
		return array(
			'labels'            => $labels,
			'public'            => true,
			'hierarchical'      => true, // Checkbox UI for all three; only destinations nest in URLs.
			'show_in_rest'      => true,
			'rest_base'         => $rest_base,
			'show_admin_column' => true,
			'rewrite'           => array(
				'slug'         => $slug,
				'with_front'   => false,
				'hierarchical' => $hierarchical,
			),
			'capabilities'      => array(
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_wpitravel_trips',
			),
		);
	}
}
