<?php
/**
 * Sample trips.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Setup;

use WP_Error;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds three complete example trips (prices, departures, itinerary, FAQs)
 * so a new site has something to look at, and removes them again. Sample
 * trips are marked with post meta, so removing them never touches the
 * agency's own trips.
 */
final class SampleData {

	public const META = '_wpitravel_sample';

	/**
	 * IDs of sample trips.
	 *
	 * @return list<int>
	 */
	public static function ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => PostTypes::TRIP,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					// Creation order: the trips are made in the same second, so dates tie.
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- A few posts.
				)
			)
		);
	}

	/**
	 * Creates the sample trips (once).
	 *
	 * @return list<int>|WP_Error New trip IDs.
	 */
	public static function create() {
		if ( self::ids() ) {
			return new WP_Error( 'wpitravel_sample_exists', __( 'The sample trips are already there.', 'wpinterface-travel' ), array( 'status' => 409 ) );
		}

		$ids = array();

		foreach ( self::trips() as $trip ) {
			$id = wp_insert_post(
				array(
					'post_type'    => PostTypes::TRIP,
					'post_status'  => 'publish',
					'post_title'   => $trip['title'],
					'post_excerpt' => $trip['excerpt'],
					'post_content' => $trip['content'],
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				self::remove();

				return $id;
			}

			update_post_meta( $id, self::META, 1 );

			foreach ( $trip['meta'] as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}

			foreach ( $trip['terms'] as $taxonomy => $names ) {
				wp_set_object_terms( $id, $names, $taxonomy );
			}

			$ids[] = (int) $id;
		}

		return $ids;
	}

	/**
	 * Deletes the sample trips, and their terms when no other trip uses them.
	 * Trips with bookings are kept (bookings must keep their trip).
	 *
	 * @return array{removed: int, kept: int}
	 */
	public static function remove(): array {
		global $wpdb;

		$removed = 0;
		$kept    = 0;
		$terms   = array();

		foreach ( self::ids() as $id ) {
			$bookings = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE trip_id = %d', \WPInterface\Travel\Install\Schema::table( 'bookings' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.

			if ( $bookings > 0 ) {
				++$kept;
				continue;
			}

			foreach ( PostTypes::taxonomies() as $taxonomy ) {
				$found = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );

				foreach ( is_array( $found ) ? $found : array() as $term_id ) {
					$terms[ (int) $term_id ] = $taxonomy;
				}
			}

			if ( wp_delete_post( $id, true ) ) {
				++$removed;
			}
		}

		foreach ( $terms as $term_id => $taxonomy ) {
			$term = get_term( $term_id, $taxonomy );

			if ( $term instanceof \WP_Term && 0 === (int) $term->count ) {
				wp_delete_term( $term_id, $taxonomy );
			}
		}

		return compact( 'removed', 'kept' );
	}

	/**
	 * The sample trips.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function trips(): array {
		$year = (int) current_time( 'Y' ) + 1;

		return array(
			array(
				'title'   => __( 'Annapurna Base Camp Trek', 'wpinterface-travel' ),
				'excerpt' => __( 'Twelve days through rhododendron forests and Gurung villages to the foot of Annapurna.', 'wpinterface-travel' ),
				'content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Walk from the lakeside town of Pokhara into the Annapurna Sanctuary, a ring of peaks over 7,000 m. Nights are spent in family-run teahouses, and an experienced local guide leads every group.', 'wpinterface-travel' ) . '</p><!-- /wp:paragraph -->',
				'terms'   => array(
					PostTypes::DESTINATION => array( __( 'Nepal', 'wpinterface-travel' ) ),
					PostTypes::ACTIVITY    => array( __( 'Trekking', 'wpinterface-travel' ) ),
					PostTypes::TRIP_TYPE   => array( __( 'Small group', 'wpinterface-travel' ) ),
				),
				'meta'    => array(
					TripMeta::CODE          => 'ABC-12',
					TripMeta::DURATION      => array(
						'days'   => 12,
						'nights' => 11,
					),
					TripMeta::DIFFICULTY    => 'moderate',
					TripMeta::GROUP_SIZE    => array(
						'min' => 2,
						'max' => 12,
					),
					TripMeta::MIN_AGE       => 12,
					TripMeta::MEETING_POINT => __( 'Hotel lobby, Lakeside, Pokhara', 'wpinterface-travel' ),
					TripMeta::HIGHLIGHTS    => array(
						__( 'Sunrise over Annapurna South from Poon Hill', 'wpinterface-travel' ),
						__( 'Two nights inside the Annapurna Sanctuary', 'wpinterface-travel' ),
						__( 'Natural hot springs at Jhinu Danda', 'wpinterface-travel' ),
					),
					TripMeta::ITINERARY     => array(
						array(
							'title'         => __( 'Arrive in Pokhara', 'wpinterface-travel' ),
							'description'   => __( 'Meet your guide and the group for a briefing and dinner by the lake.', 'wpinterface-travel' ),
							'meals'         => array( 'dinner' ),
							'accommodation' => __( 'Lakeside hotel', 'wpinterface-travel' ),
						),
						array(
							'title'         => __( 'Drive to Nayapul, walk to Ulleri', 'wpinterface-travel' ),
							'description'   => __( 'A short drive, then stone steps up through terraced farms.', 'wpinterface-travel' ),
							'meals'         => array( 'breakfast', 'lunch', 'dinner' ),
							'accommodation' => __( 'Teahouse', 'wpinterface-travel' ),
						),
						array(
							'title'         => __( 'Poon Hill and Tadapani', 'wpinterface-travel' ),
							'description'   => __( 'An early start for sunrise over the Himalaya, then forest trails to Tadapani.', 'wpinterface-travel' ),
							'meals'         => array( 'breakfast', 'lunch', 'dinner' ),
							'accommodation' => __( 'Teahouse', 'wpinterface-travel' ),
						),
						array(
							'title'         => __( 'Annapurna Base Camp', 'wpinterface-travel' ),
							'description'   => __( 'Through Machapuchare Base Camp into the sanctuary at 4,130 m.', 'wpinterface-travel' ),
							'meals'         => array( 'breakfast', 'lunch', 'dinner' ),
							'accommodation' => __( 'Teahouse', 'wpinterface-travel' ),
						),
					),
					TripMeta::INCLUDES      => array(
						__( 'Licensed English-speaking guide and porters', 'wpinterface-travel' ),
						__( 'All teahouse nights and meals on the trek', 'wpinterface-travel' ),
						__( 'Trekking permits (ACAP and TIMS)', 'wpinterface-travel' ),
					),
					TripMeta::EXCLUDES      => array(
						__( 'International flights', 'wpinterface-travel' ),
						__( 'Travel insurance (required)', 'wpinterface-travel' ),
					),
					TripMeta::FAQS          => array(
						array(
							'question' => __( 'How fit do I need to be?', 'wpinterface-travel' ),
							'answer'   => __( 'You should be comfortable walking 5–7 hours a day with a light daypack. No climbing experience is needed.', 'wpinterface-travel' ),
						),
						array(
							'question' => __( 'What about altitude sickness?', 'wpinterface-travel' ),
							'answer'   => __( 'The itinerary climbs slowly, and guides are trained to spot symptoms early.', 'wpinterface-travel' ),
						),
					),
					TripMeta::MAP           => array(
						'lat'     => 28.5308,
						'lng'     => 83.8780,
						'zoom'    => 10,
						'address' => __( 'Annapurna Sanctuary, Nepal', 'wpinterface-travel' ),
					),
					TripMeta::PRICING       => self::pricing( '1450', '1290', '1100' ),
					TripMeta::SCHEDULE      => array(
						'recurring'    => array(
							array(
								'start'    => $year . '-03-01',
								'end'      => $year . '-05-31',
								'weekdays' => array( 6 ),
							),
							array(
								'start'    => $year . '-09-15',
								'end'      => $year . '-11-30',
								'weekdays' => array( 6 ),
							),
						),
						'capacity'     => 12,
						'cutoff_hours' => 72,
						'advance_days' => 540,
					),
				),
			),
			array(
				'title'   => __( 'Lisbon Food and History Walk', 'wpinterface-travel' ),
				'excerpt' => __( 'Four hours of pastries, petiscos and stories through Alfama and Baixa.', 'wpinterface-travel' ),
				'content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Taste your way through two of Lisbon’s oldest neighbourhoods with a local guide, stopping at family bakeries, a tinned-fish shop and a tasca for wine.', 'wpinterface-travel' ) . '</p><!-- /wp:paragraph -->',
				'terms'   => array(
					PostTypes::DESTINATION => array( __( 'Portugal', 'wpinterface-travel' ) ),
					PostTypes::ACTIVITY    => array( __( 'Food tour', 'wpinterface-travel' ), __( 'Walking tour', 'wpinterface-travel' ) ),
					PostTypes::TRIP_TYPE   => array( __( 'Day tour', 'wpinterface-travel' ) ),
				),
				'meta'    => array(
					TripMeta::CODE          => 'LIS-FOOD',
					TripMeta::DURATION      => array( 'hours' => 4 ),
					TripMeta::DIFFICULTY    => 'easy',
					TripMeta::GROUP_SIZE    => array(
						'min' => 1,
						'max' => 10,
					),
					TripMeta::MEETING_POINT => __( 'Praça do Comércio, by the arch', 'wpinterface-travel' ),
					TripMeta::HIGHLIGHTS    => array(
						__( 'Eight tastings, including pastéis de nata warm from the oven', 'wpinterface-travel' ),
						__( 'Hidden viewpoints over the Tagus', 'wpinterface-travel' ),
					),
					TripMeta::INCLUDES      => array(
						__( 'All food and two glasses of wine', 'wpinterface-travel' ),
						__( 'Local guide', 'wpinterface-travel' ),
					),
					TripMeta::EXCLUDES      => array( __( 'Hotel pickup', 'wpinterface-travel' ) ),
					TripMeta::FAQS          => array(
						array(
							'question' => __( 'Can you cater for vegetarians?', 'wpinterface-travel' ),
							'answer'   => __( 'Yes. Tell us when you book and we will swap the meat and fish tastings.', 'wpinterface-travel' ),
						),
					),
					TripMeta::MAP           => array(
						'lat'     => 38.7077,
						'lng'     => -9.1365,
						'zoom'    => 15,
						'address' => __( 'Praça do Comércio, Lisbon', 'wpinterface-travel' ),
					),
					TripMeta::PRICING       => self::pricing( '85', '', '45' ),
					TripMeta::SCHEDULE      => array(
						'recurring'    => array(
							array(
								'weekdays' => array( 2, 4, 6 ),
								'times'    => array( '10:00', '16:00' ),
							),
						),
						'capacity'     => 10,
						'cutoff_hours' => 12,
						'advance_days' => 180,
					),
				),
			),
			array(
				'title'   => __( 'Serengeti Migration Safari', 'wpinterface-travel' ),
				'excerpt' => __( 'Six days following the great migration across the Serengeti and into Ngorongoro Crater.', 'wpinterface-travel' ),
				'content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Game drives in a private 4x4 with a pop-up roof, nights in tented camps, and a full day in the Ngorongoro Crater.', 'wpinterface-travel' ) . '</p><!-- /wp:paragraph -->',
				'terms'   => array(
					PostTypes::DESTINATION => array( __( 'Tanzania', 'wpinterface-travel' ) ),
					PostTypes::ACTIVITY    => array( __( 'Wildlife safari', 'wpinterface-travel' ) ),
					PostTypes::TRIP_TYPE   => array( __( 'Private tour', 'wpinterface-travel' ) ),
				),
				'meta'    => array(
					TripMeta::CODE       => 'TZ-SAF6',
					TripMeta::DURATION   => array(
						'days'   => 6,
						'nights' => 5,
					),
					TripMeta::DIFFICULTY => 'easy',
					TripMeta::GROUP_SIZE => array(
						'min' => 2,
						'max' => 6,
					),
					TripMeta::MIN_AGE    => 6,
					TripMeta::HIGHLIGHTS => array(
						__( 'River crossings during the migration season', 'wpinterface-travel' ),
						__( 'A full day in Ngorongoro Crater', 'wpinterface-travel' ),
					),
					TripMeta::INCLUDES   => array(
						__( 'Private 4x4 with driver-guide', 'wpinterface-travel' ),
						__( 'Park fees and all meals on safari', 'wpinterface-travel' ),
					),
					TripMeta::EXCLUDES   => array( __( 'Visa and tips', 'wpinterface-travel' ) ),
					TripMeta::MAP        => array(
						'lat'     => -2.3333,
						'lng'     => 34.8333,
						'zoom'    => 8,
						'address' => __( 'Serengeti National Park, Tanzania', 'wpinterface-travel' ),
					),
					TripMeta::PRICING    => self::pricing( '2890', '', '1450' ),
					TripMeta::SCHEDULE   => array(
						'fixed'        => array(
							array( 'date' => $year . '-07-05' ),
							array( 'date' => $year . '-07-19' ),
							array( 'date' => $year . '-08-02' ),
							array( 'date' => $year . '-08-16' ),
						),
						'capacity'     => 6,
						'cutoff_hours' => 336,
						'advance_days' => 540,
					),
				),
			),
		);
	}

	/**
	 * Pricing with an adult and a child price.
	 *
	 * @param string $adult Adult price.
	 * @param string $sale  Adult sale price ('' for none).
	 * @param string $child Child price.
	 * @return array<string, mixed>
	 */
	private static function pricing( string $adult, string $sale, string $child ): array {
		return array(
			'options' => array(
				array(
					'key'        => 'standard',
					'label'      => __( 'Standard', 'wpinterface-travel' ),
					'categories' => array(
						array(
							'key'        => 'adult',
							'label'      => __( 'Adult', 'wpinterface-travel' ),
							'price'      => $adult,
							'sale_price' => $sale,
							'min'        => 1,
						),
						array(
							'key'   => 'child',
							'label' => __( 'Child', 'wpinterface-travel' ),
							'price' => $child,
						),
					),
				),
			),
		);
	}
}
