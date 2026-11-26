<?php
/**
 * Importer for Yatra.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Import;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Trips of Yatra 3.x, which keeps trips in its own tables (`yatra_trips`
 * and related; `yatra_new_*` before 3.0.5) instead of posts. Checked
 * against version 3.1. Yatra 2.x (post type `tour`) is not supported.
 */
final class YatraImporter extends Importer {

	/**
	 * ID.
	 */
	public function id(): string {
		return 'yatra';
	}

	/**
	 * Source name.
	 */
	public function label(): string {
		return 'Yatra';
	}

	/**
	 * Table name, or '' when Yatra 3 has no data here.
	 *
	 * @param string $name Short name, e.g. trips.
	 */
	private static function table( string $name ): string {
		global $wpdb;

		foreach ( array( 'yatra_', 'yatra_new_' ) as $prefix ) {
			if ( self::table_exists( $wpdb->prefix . $prefix . 'trips' ) ) {
				$table = $wpdb->prefix . $prefix . $name;

				return self::table_exists( $table ) ? $table : '';
			}
		}

		return '';
	}

	/**
	 * Source trips (not deleted).
	 *
	 * @return list<int>
	 */
	protected function source_ids(): array {
		global $wpdb;

		$table = self::table( 'trips' );

		if ( '' === $table ) {
			return array();
		}

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' ORDER BY id", $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Yatra's table.
	}

	/**
	 * Reads one trip.
	 *
	 * @param int $source_id Yatra trip ID.
	 * @return array<string, mixed>|null
	 */
	protected function read( int $source_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Yatra's tables.
		$trip = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'trips' ), $source_id ), ARRAY_A );

		if ( ! is_array( $trip ) ) {
			return null;
		}

		$notes   = array();
		$content = self::table( 'trip_content' );
		$rows    = '' !== $content ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE trip_id = %d ORDER BY sort_order, id', $content, $source_id ), ARRAY_A ) : array();

		$highlights = array();
		$faqs       = array();
		$gallery    = array();

		foreach ( $rows as $row ) {
			switch ( $row['content_type'] ) {
				case 'highlight':
					$highlights[] = (string) $row['title'];
					break;
				case 'faq':
					$faqs[] = array(
						'question' => (string) $row['title'],
						'answer'   => (string) $row['description'],
					);
					break;
				case 'image':
					$id = attachment_url_to_postid( (string) $row['content_url'] );

					if ( $id ) {
						$gallery[] = $id;
					}
					break;
			}
		}

		// Itinerary.
		$itinerary = array();
		$days      = self::table( 'trip_itinerary_days' );

		if ( '' !== $days ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT title, description FROM %i WHERE trip_id = %d ORDER BY day_number, `order`, id', $days, $source_id ), ARRAY_A ) as $day ) {
				$itinerary[] = array(
					'title'       => (string) $day['title'],
					'description' => (string) $day['description'],
				);
			}
		}

		// Destinations, activities, categories (trip types) and difficulty.
		$terms           = array();
		$classifications = self::table( 'classifications' );
		$links           = self::table( 'trip_classifications' );

		if ( '' !== $classifications && '' !== $links ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT c.name, c.type FROM %i l INNER JOIN %i c ON c.id = l.classification_id WHERE l.trip_id = %d ORDER BY l.sort_order, c.name', $links, $classifications, $source_id ), ARRAY_A ) as $row ) {
				$terms[ (string) $row['type'] ][] = (string) $row['name'];
			}
		}

		$difficulty = '';

		if ( ! empty( $trip['difficulty_level'] ) && '' !== $classifications ) {
			$difficulty = self::difficulty( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $classifications, (int) $trip['difficulty_level'] ) ) );
		}

		// Fixed departures.
		$dates     = array();
		$capacity  = absint( $trip['max_travelers'] ?? 0 );
		$available = self::table( 'trip_availability_dates' );

		if ( '' !== $available ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT departure_date, seats_total FROM %i WHERE trip_id = %d', $available, $source_id ), ARRAY_A ) as $row ) {
				$dates[]  = (string) $row['departure_date'];
				$capacity = max( $capacity, absint( $row['seats_total'] ) );
			}
		}
		// phpcs:enable

		$dates    = self::future_dates( $dates );
		$schedule = $dates ? array(
			'fixed'        => $dates,
			'capacity'     => $capacity,
			'cutoff_hours' => absint( $trip['booking_deadline_hours'] ?? 0 ),
		) : array();

		if ( ! $dates ) {
			$notes[] = __( 'no upcoming fixed dates; add departures in the Departures tab.', 'wpinterface-travel' );
		}

		$pricing = $this->pricing( $trip );

		if ( ! $pricing ) {
			$notes[] = __( 'no prices found; add them in the Pricing tab.', 'wpinterface-travel' );
		}

		$map = array();

		if ( null !== $trip['starting_latitude'] && null !== $trip['starting_longitude'] && ( 0.0 !== (float) $trip['starting_latitude'] || 0.0 !== (float) $trip['starting_longitude'] ) ) {
			$map = array(
				'lat'     => (float) $trip['starting_latitude'],
				'lng'     => (float) $trip['starting_longitude'],
				'zoom'    => 10,
				'address' => (string) $trip['starting_location'],
			);
		}

		$hours = absint( $trip['duration_hours'] ?? 0 );

		return array(
			'post'      => array(
				'post_title'   => (string) $trip['title'],
				'post_name'    => (string) $trip['slug'],
				'post_content' => (string) $trip['description'],
				'post_excerpt' => (string) $trip['short_description'],
				'post_status'  => in_array( $trip['status'], array( 'publish', 'published' ), true ) ? 'publish' : 'draft',
			),
			'meta'      => array(
				TripMeta::DURATION      => $hours > 0 ? array( 'hours' => $hours ) : array(
					'days'   => absint( $trip['duration_days'] ?? 0 ),
					'nights' => absint( $trip['duration_nights'] ?? 0 ),
				),
				TripMeta::DIFFICULTY    => $difficulty,
				TripMeta::GROUP_SIZE    => array(
					'min' => absint( $trip['min_travelers'] ?? 0 ),
					'max' => absint( $trip['max_travelers'] ?? 0 ),
				),
				TripMeta::MIN_AGE       => absint( $trip['age_min'] ?? 0 ),
				TripMeta::MEETING_POINT => (string) ( $trip['starting_location'] ?? '' ),
				TripMeta::HIGHLIGHTS    => array_values( array_filter( $highlights ) ),
				TripMeta::ITINERARY     => $itinerary,
				TripMeta::INCLUDES      => self::items( $trip['included_items'] ?? '' ),
				TripMeta::EXCLUDES      => self::items( $trip['excluded_items'] ?? '' ),
				TripMeta::FAQS          => $faqs,
				TripMeta::GALLERY       => $gallery,
				TripMeta::MAP           => $map,
				TripMeta::PRICING       => $pricing,
				TripMeta::SCHEDULE      => $schedule,
			),
			'terms'     => array(
				PostTypes::DESTINATION => $terms['destination'] ?? array(),
				PostTypes::ACTIVITY    => $terms['activity'] ?? array(),
				PostTypes::TRIP_TYPE   => $terms['category'] ?? array(),
			),
			'thumbnail' => absint( $trip['featured_image'] ?? 0 ),
			'notes'     => $notes,
		);
	}

	/**
	 * Prices: one price, or one per traveler type.
	 *
	 * @param array<string, mixed> $trip Trip row.
	 * @return array<string, mixed>
	 */
	private function pricing( array $trip ): array {
		$categories = array();

		if ( 'traveler_based' === ( $trip['pricing_type'] ?? '' ) ) {
			foreach ( (array) json_decode( (string) ( $trip['price_types'] ?? '' ), true ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$regular = (string) ( $row['original_price'] ?? ( $row['price'] ?? '' ) );
				$sale    = (string) ( $row['sale_price'] ?? ( $row['discounted_price'] ?? '' ) );

				$categories[] = array( (string) ( $row['label'] ?? ( $row['category_label'] ?? '' ) ), $regular, $sale );
			}
		}

		if ( ! $categories ) {
			$sale         = (string) ( $trip['sale_price'] ?? '' );
			$sale         = '' !== $sale && (float) $sale > 0 ? $sale : (string) ( $trip['discounted_price'] ?? '' );
			$categories[] = array( '', (string) ( $trip['original_price'] ?? '' ), (float) $sale > 0 ? $sale : '' );
		}

		$option = self::price_option( '', $categories, absint( $trip['min_travelers'] ?? 0 ), absint( $trip['max_travelers'] ?? 0 ) );

		return $option && (float) $option['categories'][0]['price'] > 0 ? array( 'options' => array( $option ) ) : array();
	}

	/**
	 * Titles from Yatra's included/excluded JSON ([{title, description}]).
	 *
	 * @param mixed $json JSON.
	 * @return list<string>
	 */
	private static function items( $json ): array {
		$items = array();

		foreach ( (array) json_decode( (string) $json, true ) as $row ) {
			$items[] = is_array( $row ) ? (string) ( $row['title'] ?? '' ) : (string) $row;
		}

		return array_values( array_filter( array_map( 'trim', $items ) ) );
	}
}
