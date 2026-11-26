<?php
/**
 * Sortable trip values.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Content;

use WPInterface\Travel\Pricing\Pricing;
use WPInterface\Travel\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps plain numeric copies of a trip's from-price, length in days and
 * average rating in post meta, so listings can filter and sort by them
 * in SQL. They are recomputed whenever pricing, duration or reviews change.
 */
final class TripIndex {

	public const PRICE  = '_wpitravel_price_from';
	public const DAYS   = '_wpitravel_duration_days';
	public const RATING = '_wpitravel_rating_avg';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Reindexes a trip when its pricing, duration or rating changes.
	 *
	 * @param int|int[] $meta_id  Meta ID(s).
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ): void {
		if ( in_array( $meta_key, array( TripMeta::PRICING, TripMeta::DURATION, Reviews::RATING_META ), true ) && PostTypes::TRIP === get_post_type( (int) $post_id ) ) {
			self::update( (int) $post_id );
		}
	}

	/**
	 * Recomputes the indexed values of one trip.
	 *
	 * @param int $trip_id Trip.
	 */
	public static function update( int $trip_id ): void {
		$decimals = (int) wpitravel()->options()->get( 'currency.decimals', 2 );
		$price    = Pricing::from_price( Pricing::sanitize( get_post_meta( $trip_id, TripMeta::PRICING, true ), $decimals ), '', $decimals );
		$duration = TripMeta::sanitize_duration( get_post_meta( $trip_id, TripMeta::DURATION, true ) );
		$rating   = Reviews::summary( $trip_id );

		// These keys are not watched by on_meta_change(), so writing them cannot loop.
		if ( $price ) {
			update_metadata( 'post', $trip_id, self::PRICE, $price['price'] );
		} else {
			delete_metadata( 'post', $trip_id, self::PRICE );
		}

		update_metadata( 'post', $trip_id, self::DAYS, max( $duration['days'], $duration['hours'] > 0 ? 1 : 0 ) );
		update_metadata( 'post', $trip_id, self::RATING, $rating['count'] > 0 ? (string) $rating['average'] : '0' );
	}

	/**
	 * Indexes every trip (install, upgrade).
	 */
	public static function rebuild(): void {
		global $wpdb;

		// Every trip in every language, without query filters (translation
		// plugins would otherwise skip some).
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", PostTypes::TRIP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time rebuild.

		foreach ( $ids as $id ) {
			self::update( (int) $id );
		}
	}
}
