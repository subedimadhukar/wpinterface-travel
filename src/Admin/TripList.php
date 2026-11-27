<?php
/**
 * Trips list table.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Admin;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Content\TripMeta;
use WPInterface\Travel\Support\Capabilities;
use WPInterface\Travel\Support\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Trip code and Duration columns to Travel → All Trips.
 */
final class TripList {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'manage_' . PostTypes::TRIP . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . PostTypes::TRIP . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
	}

	/**
	 * Adds "Bookings" under each trip: the bookings screen for that trip.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = (array) $actions;

		if ( $post instanceof \WP_Post && PostTypes::TRIP === $post->post_type && current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			$actions['wpitravel_bookings'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'    => BookingsPage::SLUG,
							'trip_id' => Multilingual::original_trip_id( $post->ID ),
						),
						admin_url( 'admin.php' )
					)
				),
				/* translators: %s: trip title. */
				esc_attr( sprintf( __( 'Bookings of “%s”', 'wpinterface-travel' ), get_the_title( $post ) ) ),
				esc_html__( 'Bookings', 'wpinterface-travel' )
			);
		}

		return $actions;
	}

	/**
	 * Inserts the columns after the title.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();
		$result  = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'title' === $key ) {
				$result['wpitravel_code']     = __( 'Trip code', 'wpinterface-travel' );
				$result['wpitravel_duration'] = __( 'Duration', 'wpinterface-travel' );
			}
		}

		return $result;
	}

	/**
	 * Prints a column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Trip ID.
	 */
	public function render_column( $column, $post_id ): void {
		if ( 'wpitravel_code' === $column ) {
			$code = (string) get_post_meta( (int) $post_id, TripMeta::CODE, true );
			echo '' !== $code ? esc_html( $code ) : '&mdash;';
		}

		if ( 'wpitravel_duration' === $column ) {
			$label = self::duration_label( TripMeta::sanitize_duration( get_post_meta( (int) $post_id, TripMeta::DURATION, true ) ) );
			echo '' !== $label ? esc_html( $label ) : '&mdash;';
		}
	}

	/**
	 * Human-readable duration, e.g. "5 days, 4 nights" or "3 hours".
	 *
	 * @param array{days: int, nights: int, hours: int} $duration Duration.
	 */
	public static function duration_label( array $duration ): string {
		$parts = array();

		if ( $duration['days'] > 0 ) {
			/* translators: %d: number of days. */
			$parts[] = sprintf( _n( '%d day', '%d days', $duration['days'], 'wpinterface-travel' ), $duration['days'] );
		}

		if ( $duration['nights'] > 0 ) {
			/* translators: %d: number of nights. */
			$parts[] = sprintf( _n( '%d night', '%d nights', $duration['nights'], 'wpinterface-travel' ), $duration['nights'] );
		}

		if ( $duration['hours'] > 0 ) {
			/* translators: %d: number of hours. */
			$parts[] = sprintf( _n( '%d hour', '%d hours', $duration['hours'], 'wpinterface-travel' ), $duration['hours'] );
		}

		return implode( ', ', $parts );
	}
}
