<?php
/**
 * Block theme templates.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WPInterface\Travel\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Gives block themes a trip page, trip archive and destination/activity/
 * trip type pages built from the plugin's blocks. A theme's own template of
 * the same name wins, and site owners can edit these in the Site Editor.
 */
final class BlockTemplates {

	public const PLUGIN = 'wpinterface-travel';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_templates' ), 20 );
	}

	/**
	 * Registers the templates.
	 */
	public function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		foreach ( self::templates() as $slug => $template ) {
			register_block_template( self::PLUGIN . '//' . $slug, $template );
		}
	}

	/**
	 * Template slugs and their definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function templates(): array {
		$templates = array(
			'single-' . PostTypes::TRIP  => array(
				'title'       => __( 'Single Trip', 'wpinterface-travel' ),
				'description' => __( 'Displays a single trip with its itinerary, booking form and reviews.', 'wpinterface-travel' ),
				'post_types'  => array( PostTypes::TRIP ),
				'content'     => self::single(),
			),
			'archive-' . PostTypes::TRIP => array(
				'title'       => __( 'Trip Archive', 'wpinterface-travel' ),
				'description' => __( 'Lists all trips with search and filters.', 'wpinterface-travel' ),
				'content'     => self::archive(),
			),
		);

		$names = array(
			PostTypes::DESTINATION => __( 'Destination', 'wpinterface-travel' ),
			PostTypes::ACTIVITY    => __( 'Activity', 'wpinterface-travel' ),
			PostTypes::TRIP_TYPE   => __( 'Trip Type', 'wpinterface-travel' ),
		);

		foreach ( $names as $taxonomy => $name ) {
			$templates[ 'taxonomy-' . $taxonomy ] = array(
				'title'       => $name,
				/* translators: %s: taxonomy name, e.g. Destination. */
				'description' => sprintf( __( 'Lists the trips of a %s.', 'wpinterface-travel' ), $name ),
				'content'     => self::archive(),
			);
		}

		/**
		 * Filters the block templates registered for block themes.
		 *
		 * @param array $templates Slug => title, description, content.
		 */
		return (array) apply_filters( 'wpitravel_block_templates', $templates );
	}

	/**
	 * Single trip markup.
	 */
	private static function single(): string {
		return self::wrap(
			'<!-- wp:group {"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide"><!-- wp:post-terms {"term":"' . PostTypes::DESTINATION . '","fontSize":"small"} /-->
<!-- wp:post-title {"level":1} /--></div>
<!-- /wp:group -->
<!-- wp:wpitravel/trip-gallery {"align":"wide"} /-->
<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|60"}}}} -->
<div class="wp-block-columns alignwide"><!-- wp:column {"width":"64%"} -->
<div class="wp-block-column" style="flex-basis:64%"><!-- wp:wpitravel/trip-facts /-->
<!-- wp:post-content {"layout":{"type":"default"}} /-->
<!-- wp:wpitravel/trip-highlights /-->
<!-- wp:wpitravel/trip-itinerary /-->
<!-- wp:wpitravel/trip-departures /-->
<!-- wp:wpitravel/trip-includes /-->
<!-- wp:wpitravel/trip-map /-->
<!-- wp:wpitravel/trip-faqs /-->
<!-- wp:wpitravel/trip-reviews /--></div>
<!-- /wp:column -->
<!-- wp:column {"width":"36%","className":"wpitravel-trip__sidebar"} -->
<div class="wp-block-column wpitravel-trip__sidebar" style="flex-basis:36%"><!-- wp:group {"className":"wpitravel-trip__sticky","layout":{"type":"default"}} -->
<div class="wp-block-group wpitravel-trip__sticky"><!-- wp:wpitravel/trip-price /-->
<!-- wp:wpitravel/booking-form /--></div>
<!-- /wp:group --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->'
		);
	}

	/**
	 * Archive markup.
	 */
	private static function archive(): string {
		return self::wrap(
			'<!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide"} /-->
<!-- wp:term-description {"align":"wide"} /-->
<!-- wp:wpitravel/trip-search {"target":"current","align":"wide"} /-->
<!-- wp:wpitravel/trips {"inherit":true,"align":"wide"} /-->'
		);
	}

	/**
	 * Adds the theme's header and footer around the main content.
	 *
	 * @param string $main Main content blocks.
	 */
	private static function wrap( string $main ): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">' . $main . '</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
