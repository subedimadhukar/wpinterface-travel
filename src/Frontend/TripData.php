<?php
/**
 * Trip data for templates.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WP_Post;
use WP_Term;
use WPInterface\Travel\Admin\TripList;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Reviews\Reviews;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Everything templates need about a trip, already cleaned. Template files
 * receive this and only escape and print.
 */
final class TripData {

	/**
	 * Resolves a trip from an ID (0 = the current post).
	 *
	 * @param int $trip_id Trip ID or 0.
	 */
	public static function resolve( int $trip_id = 0 ): ?WP_Post {
		$post = get_post( $trip_id ? $trip_id : (int) get_the_ID() );

		return $post instanceof WP_Post && PostTypes::TRIP === $post->post_type ? $post : null;
	}

	/**
	 * Whether the current visitor may see a trip's details: published and
	 * not behind a password (or unlocked), or a draft/private trip the user
	 * may read. Staff who can edit the trip always may.
	 *
	 * @param WP_Post $trip Trip.
	 */
	public static function is_viewable( WP_Post $trip ): bool {
		if ( current_user_can( 'edit_post', $trip->ID ) ) {
			return true;
		}

		if ( 'publish' !== $trip->post_status ) {
			return current_user_can( 'read_post', $trip->ID );
		}

		return ! post_password_required( $trip );
	}

	/**
	 * Data for a trip card in listings.
	 *
	 * @param WP_Post $trip Trip.
	 * @return array<string, mixed>
	 */
	public static function card( WP_Post $trip ): array {
		$destinations = get_the_terms( $trip, PostTypes::DESTINATION );

		return array(
			'id'           => $trip->ID,
			'title'        => get_the_title( $trip ),
			'url'          => (string) get_permalink( $trip ),
			'image'        => get_the_post_thumbnail( $trip, 'medium_large', array( 'loading' => 'lazy' ) ),
			'excerpt'      => wp_trim_words( get_the_excerpt( $trip ), 22 ),
			'price'        => self::price( $trip->ID ),
			'duration'     => TripList::duration_label( TripMeta::sanitize_duration( get_post_meta( $trip->ID, TripMeta::DURATION, true ) ) ),
			'difficulty'   => self::difficulty( $trip->ID ),
			'rating'       => Reviews::summary( $trip->ID ),
			'destinations' => is_array( $destinations ) ? array_map( static fn( WP_Term $term ): string => $term->name, $destinations ) : array(),
		);
	}

	/**
	 * Formatted from-price: price, regular price (when on sale) and plain amount.
	 *
	 * @param int $trip_id Trip.
	 * @return array{price: string, regular: string, sale: bool, amount: string, regular_amount: string, currency: string}|null
	 */
	public static function price( int $trip_id ): ?array {
		$currency = (array) wpitravel()->options()->get( 'currency', array() );
		$decimals = (int) ( $currency['decimals'] ?? 2 );
		$from     = Pricing::from_price( Pricing::sanitize( get_post_meta( $trip_id, TripMeta::PRICING, true ), $decimals ), '', $decimals );

		if ( ! $from ) {
			return null;
		}

		return array(
			'price'          => Money::format( $from['price'], $currency ),
			'regular'        => Money::format( $from['regular'], $currency ),
			'sale'           => $from['sale'],
			'amount'         => $from['price'],
			'regular_amount' => $from['regular'],
			'currency'       => (string) ( $currency['code'] ?? 'USD' ),
		);
	}

	/**
	 * Facts list: built-in facts first, then custom ones.
	 *
	 * @param int $trip_id Trip.
	 * @return list<array{key: string, label: string, value: string}>
	 */
	public static function facts( int $trip_id ): array {
		$facts    = array();
		$duration = TripList::duration_label( TripMeta::sanitize_duration( get_post_meta( $trip_id, TripMeta::DURATION, true ) ) );
		$group    = TripMeta::sanitize_group_size( get_post_meta( $trip_id, TripMeta::GROUP_SIZE, true ) );
		$age      = absint( get_post_meta( $trip_id, TripMeta::MIN_AGE, true ) );
		$code     = (string) get_post_meta( $trip_id, TripMeta::CODE, true );
		$places   = get_the_terms( $trip_id, PostTypes::DESTINATION );
		$types    = get_the_terms( $trip_id, PostTypes::ACTIVITY );

		$add = static function ( string $key, string $label, string $value ) use ( &$facts ): void {
			if ( '' !== $value ) {
				$facts[] = compact( 'key', 'label', 'value' );
			}
		};

		$add( 'duration', __( 'Duration', 'wpinterface-travel' ), $duration );
		$add( 'difficulty', __( 'Difficulty', 'wpinterface-travel' ), self::difficulty( $trip_id ) );
		$add( 'destination', __( 'Destination', 'wpinterface-travel' ), is_array( $places ) ? implode( ', ', wp_list_pluck( $places, 'name' ) ) : '' );
		$add( 'activity', __( 'Activities', 'wpinterface-travel' ), is_array( $types ) ? implode( ', ', wp_list_pluck( $types, 'name' ) ) : '' );

		if ( $group['min'] > 0 || $group['max'] > 0 ) {
			$add(
				'group_size',
				__( 'Group size', 'wpinterface-travel' ),
				$group['max'] > 0
					/* translators: 1: minimum, 2: maximum travelers. */
					? sprintf( __( '%1$d–%2$d people', 'wpinterface-travel' ), max( 1, $group['min'] ), $group['max'] )
					/* translators: %d: minimum travelers. */
					: sprintf( __( 'From %d people', 'wpinterface-travel' ), $group['min'] )
			);
		}

		/* translators: %d: minimum age. */
		$add( 'min_age', __( 'Minimum age', 'wpinterface-travel' ), $age > 0 ? sprintf( __( '%d years', 'wpinterface-travel' ), $age ) : '' );
		$add( 'meeting_point', __( 'Meeting point', 'wpinterface-travel' ), (string) get_post_meta( $trip_id, TripMeta::MEETING_POINT, true ) );
		$add( 'code', __( 'Trip code', 'wpinterface-travel' ), $code );

		foreach ( TripMeta::sanitize_facts( get_post_meta( $trip_id, TripMeta::FACTS, true ) ) as $index => $fact ) {
			$add( 'custom-' . $index, $fact['label'], $fact['value'] );
		}

		/**
		 * Filters the facts shown for a trip.
		 *
		 * @param array $facts   Facts (key, label, value).
		 * @param int   $trip_id Trip.
		 */
		return (array) apply_filters( 'wpitravel_trip_facts', $facts, $trip_id );
	}

	/**
	 * Difficulty label.
	 *
	 * @param int $trip_id Trip.
	 */
	public static function difficulty( int $trip_id ): string {
		$key = TripMeta::sanitize_difficulty( get_post_meta( $trip_id, TripMeta::DIFFICULTY, true ) );

		return '' !== $key ? (string) ( TripMeta::difficulties()[ $key ] ?? '' ) : '';
	}

	/**
	 * Itinerary days.
	 *
	 * @param int $trip_id Trip.
	 * @return list<array{title: string, description: string, meals: list<string>, accommodation: string}>
	 */
	public static function itinerary( int $trip_id ): array {
		$meals = TripMeta::meals();

		return array_map(
			static function ( array $day ) use ( $meals ): array {
				$day['meals'] = array_values( array_map( static fn( string $meal ): string => $meals[ $meal ] ?? $meal, $day['meals'] ) );

				return $day;
			},
			TripMeta::sanitize_itinerary( get_post_meta( $trip_id, TripMeta::ITINERARY, true ) )
		);
	}

	/**
	 * Gallery images (attachment IDs that exist).
	 *
	 * @param int $trip_id Trip.
	 * @return list<int>
	 */
	public static function gallery( int $trip_id ): array {
		return array_values( array_filter( TripMeta::sanitize_gallery( get_post_meta( $trip_id, TripMeta::GALLERY, true ) ), static fn( int $id ): bool => wp_attachment_is_image( $id ) ) );
	}

	/**
	 * Map position, or null when none is set.
	 *
	 * @param int $trip_id Trip.
	 * @return array{lat: float, lng: float, zoom: int, address: string}|null
	 */
	public static function map( int $trip_id ): ?array {
		$map = TripMeta::sanitize_map( get_post_meta( $trip_id, TripMeta::MAP, true ) );

		return ( 0.0 === $map['lat'] && 0.0 === $map['lng'] ) ? null : $map;
	}
}
