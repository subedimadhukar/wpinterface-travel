<?php
/**
 * Structured data and basic meta tags.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WP_Post;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Departures\Availability;
use WPInterface\Travel\Reviews\Reviews;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Prints TouristTrip JSON-LD on trip pages. When no SEO plugin is active it
 * also prints a meta description and Open Graph tags; SEO plugins handle
 * those themselves, so the plugin stays out of their way.
 */
final class Schema {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_head' ), 5 );
		add_action( 'wp_footer', array( $this, 'print_json_ld' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_args' ), 10, 2 );
	}

	/**
	 * Leaves password-protected trips out of the WordPress sitemap.
	 *
	 * @param array<string, mixed> $args      Query arguments.
	 * @param string               $post_type Post type.
	 * @return array<string, mixed>
	 */
	public function sitemap_args( $args, $post_type ): array {
		$args = (array) $args;

		if ( PostTypes::TRIP === $post_type ) {
			$args['has_password'] = false;
		}

		return $args;
	}

	/**
	 * The trip of a single trip page whose details may be shown, or null
	 * (password-protected trips show nothing until unlocked).
	 */
	private static function current_trip(): ?WP_Post {
		$trip = is_singular( PostTypes::TRIP ) ? get_queried_object() : null;

		return $trip instanceof WP_Post && ! post_password_required( $trip ) ? $trip : null;
	}

	/**
	 * Prints the meta description and Open Graph tags on single trip pages.
	 */
	public function print_head(): void {
		$trip = self::current_trip();

		if ( $trip && ! self::seo_plugin_active() ) {
			echo wp_kses(
				self::meta_tags( $trip ),
				array(
					'meta' => array(
						'name'     => true,
						'property' => true,
						'content'  => true,
					),
				)
			);
		}
	}

	/**
	 * Prints the TouristTrip JSON-LD in the footer.
	 */
	public function print_json_ld(): void {
		$trip = self::current_trip();
		$data = $trip ? self::data( $trip ) : array();

		if ( $data ) {
			wp_print_inline_script_tag(
				(string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ),
				array( 'type' => 'application/ld+json' )
			);
		}
	}

	/**
	 * TouristTrip data for a trip.
	 *
	 * @param WP_Post $trip Trip.
	 * @return array<string, mixed>
	 */
	public static function data( WP_Post $trip ): array {
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'TouristTrip',
			'name'        => wp_strip_all_tags( get_the_title( $trip ) ),
			'description' => self::description( $trip ),
			'url'         => get_permalink( $trip ),
			'provider'    => array(
				'@type' => 'Organization',
				'name'  => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'url'   => home_url( '/' ),
			),
		);

		$image = get_the_post_thumbnail_url( $trip, 'full' );

		if ( $image ) {
			$data['image'] = $image;
		}

		$activities = get_the_terms( $trip, PostTypes::ACTIVITY );

		if ( is_array( $activities ) && $activities ) {
			$data['touristType'] = wp_list_pluck( $activities, 'name' );
		}

		$days = TripData::itinerary( $trip->ID );

		if ( $days ) {
			$data['itinerary'] = array(
				'@type'           => 'ItemList',
				'numberOfItems'   => count( $days ),
				'itemListElement' => array_map(
					static fn( array $day, int $index ): array => array(
						'@type'    => 'ListItem',
						'position' => $index + 1,
						'item'     => array(
							'@type'       => 'TouristDestination',
							'name'        => $day['title'],
							'description' => wp_trim_words( wp_strip_all_tags( $day['description'] ), 40 ),
						),
					),
					$days,
					array_keys( $days )
				),
			);
		}

		$price = TripData::price( $trip->ID );

		if ( $price ) {
			$data['offers'] = self::offers( $trip, $price );
		}

		$rating = Reviews::summary( $trip->ID );

		if ( $rating['count'] > 0 ) {
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $rating['average'],
				'reviewCount' => $rating['count'],
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}

		/**
		 * Filters a trip's JSON-LD. Return an empty array to print nothing.
		 *
		 * @param array   $data JSON-LD data.
		 * @param WP_Post $trip Trip.
		 */
		return (array) apply_filters( 'wpitravel_schema', $data, $trip );
	}

	/**
	 * Price and availability over the next year's departures: one Offer
	 * when there are no departures to count, otherwise an AggregateOffer
	 * with the lowest and highest "from" price and the bookable departures.
	 *
	 * @param WP_Post                    $trip  Trip.
	 * @param array<string, string|bool> $price "From" price (TripData::price()).
	 * @return array<string, mixed>
	 */
	private static function offers( WP_Post $trip, array $price ): array {
		$availability = new Availability();
		$today        = $availability->today();
		$departures   = $availability->for_trip( Multilingual::original_trip_id( $trip->ID ), $today, gmdate( 'Y-m-d', (int) strtotime( $today . ' +365 days' ) ) );
		$prices       = array();

		foreach ( $departures as $departure ) {
			if ( ! empty( $departure['bookable'] ) && null !== $departure['from_price'] ) {
				$prices[] = (float) $departure['from_price'];
			}
		}

		$offer = array(
			'priceCurrency' => (string) $price['currency'],
			'url'           => get_permalink( $trip ),
			'availability'  => $prices || ! $departures ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
		);

		if ( ! $prices ) {
			return array(
				'@type' => 'Offer',
				'price' => (string) $price['amount'],
			) + $offer;
		}

		return array(
			'@type'      => 'AggregateOffer',
			'lowPrice'   => (string) min( $prices ),
			'highPrice'  => (string) max( $prices ),
			'offerCount' => count( $prices ),
		) + $offer;
	}

	/**
	 * Meta description and Open Graph tags.
	 *
	 * @param WP_Post $trip Trip.
	 */
	public static function meta_tags( WP_Post $trip ): string {
		$tags = array(
			'description'    => self::description( $trip ),
			'og:type'        => 'website',
			'og:title'       => wp_strip_all_tags( get_the_title( $trip ) ),
			'og:description' => self::description( $trip ),
			'og:url'         => (string) get_permalink( $trip ),
			'og:site_name'   => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'og:image'       => (string) get_the_post_thumbnail_url( $trip, 'large' ),
		);
		$html = '';

		foreach ( array_filter( $tags ) as $name => $content ) {
			$attribute = 0 === strpos( $name, 'og:' ) ? 'property' : 'name';
			$html     .= sprintf( '<meta %1$s="%2$s" content="%3$s">' . "\n", $attribute, esc_attr( $name ), esc_attr( $content ) );
		}

		return $html;
	}

	/**
	 * Whether an SEO plugin that prints its own meta tags is active.
	 */
	public static function seo_plugin_active(): bool {
		$active = defined( 'WPSEO_VERSION' ) // Yoast SEO.
			|| class_exists( 'RankMath' ) // Rank Math.
			|| defined( 'AIOSEO_VERSION' ) // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' ) // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' ) // The SEO Framework.
			|| defined( 'WPINTERFACE_SEO_VERSION' ); // WPInterface SEO.

		/**
		 * Filters whether an SEO plugin handles meta tags. When true, only the
		 * JSON-LD is printed.
		 *
		 * @param bool $active Whether one is active.
		 */
		return (bool) apply_filters( 'wpitravel_seo_plugin_active', $active );
	}

	/**
	 * A plain description of up to 30 words.
	 *
	 * @param WP_Post $trip Trip.
	 */
	private static function description( WP_Post $trip ): string {
		$text = has_excerpt( $trip ) ? $trip->post_excerpt : strip_shortcodes( $trip->post_content );

		return wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( $text ) ), 30 );
	}
}
