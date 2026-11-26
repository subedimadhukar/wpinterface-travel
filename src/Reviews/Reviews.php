<?php
/**
 * Trip reviews.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Reviews;

use WP_Comment;
use WP_Error;
use WP_Post;
use WPInterface\Travel\Booking\BookingAccess;
use WPInterface\Travel\Booking\BookingRepository;
use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Emails\Triggers;
use WPInterface\Travel\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews are comments of type `wpitravel_review` on trips, with comment
 * meta `rating` (1–5), `title`, `booking_id` and `verified`.
 *
 * By default only customers whose trip has ended can review, once per
 * booking, from the link in their booking (verified reviews, published
 * at once). Sites can also allow anyone (held for moderation). Core spam
 * checks (disallowed words, Akismet) still apply.
 */
final class Reviews {

	public const TYPE        = 'wpitravel_review';
	public const RATING_META = '_wpitravel_rating';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		// Trips use reviews, not the theme's comment form.
		add_filter( 'comments_open', array( $this, 'close_trip_comments' ), 10, 2 );
		add_filter( 'pings_open', array( $this, 'close_trip_comments' ), 10, 2 );

		add_action( 'transition_comment_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'deleted_comment', array( $this, 'on_deleted' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_edit' ), 10, 1 );

		add_filter( 'admin_comment_types_dropdown', array( $this, 'admin_type' ) );
		add_filter( 'manage_edit-comments_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'admin_column' ), 10, 2 );
	}

	/**
	 * Whether a booking may be reviewed now.
	 *
	 * @param array<string, mixed> $booking Booking.
	 */
	public static function can_review( array $booking ): bool {
		if ( ! wpitravel()->options()->get( 'reviews.enabled', true ) ) {
			return false;
		}

		if ( ! in_array( $booking['status'], array( 'confirmed', 'completed' ), true ) || Triggers::end_date( $booking ) >= current_time( 'Y-m-d' ) ) {
			return false;
		}

		return 0 === (int) get_comments(
			array(
				'type'       => self::TYPE,
				'post_id'    => (int) $booking['trip_id'],
				'meta_key'   => 'booking_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- One lookup when a customer opens a booking.
				'meta_value' => (string) $booking['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- See above.
				'count'      => true,
				'status'     => 'all',
			)
		);
	}

	/**
	 * Saves a review.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $data    rating, title, content; booking_key + token (verified) or name + email.
	 * @return array<string, mixed>|WP_Error The review and whether it is published.
	 */
	public function create( int $trip_id, array $data ) {
		$trip = get_post( $trip_id );

		if ( ! wpitravel()->options()->get( 'reviews.enabled', true ) || ! $trip instanceof WP_Post || PostTypes::TRIP !== $trip->post_type || 'publish' !== $trip->post_status ) {
			return self::error( 'wpitravel_trip_not_found', __( 'Trip not found.', 'wpinterface-travel' ), 404 );
		}

		$rating  = (int) ( $data['rating'] ?? 0 );
		$title   = substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 120 );
		$content = trim( sanitize_textarea_field( (string) ( $data['content'] ?? '' ) ) );

		if ( $rating < 1 || $rating > 5 ) {
			return self::error( 'wpitravel_invalid_review', __( 'Choose 1 to 5 stars.', 'wpinterface-travel' ), 400, 'rating' );
		}

		if ( mb_strlen( $content ) < 10 || mb_strlen( $content ) > 5000 ) {
			return self::error( 'wpitravel_invalid_review', __( 'Write a review of 10 to 5000 characters.', 'wpinterface-travel' ), 400, 'content' );
		}

		$author = $this->author( $trip_id, $data );

		if ( is_wp_error( $author ) ) {
			return $author;
		}

		$comment = array(
			'comment_post_ID'      => $trip_id,
			'comment_author'       => $author['name'],
			'comment_author_email' => $author['email'],
			'comment_author_url'   => '',
			'comment_author_IP'    => RateLimiter::client_ip(),
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'comment_content'      => $content,
			'comment_type'         => self::TYPE,
			'comment_parent'       => 0,
			'user_id'              => get_current_user_id(),
			'comment_date'         => current_time( 'mysql' ),
			'comment_date_gmt'     => current_time( 'mysql', true ),
		);

		// Core checks: duplicates, flooding, disallowed words, spam plugins.
		$check = wp_allow_comment( $comment, true );

		if ( is_wp_error( $check ) ) {
			return self::error( 'wpitravel_invalid_review', $check->get_error_message(), 400 );
		}

		$verified = null !== $author['booking'];
		$approved = in_array( $check, array( 'spam', 'trash' ), true )
			? $check
			: ( $verified && wpitravel()->options()->get( 'reviews.auto_approve_verified', true ) ? 1 : 0 );

		$comment['comment_approved'] = $approved;
		$comment['comment_meta']     = array(
			'rating'     => $rating,
			'title'      => $title,
			'verified'   => $verified ? 1 : 0,
			'booking_id' => $verified ? (int) $author['booking']['id'] : 0,
		);

		$comment_id = wp_insert_comment( wp_slash( $comment ) );

		if ( ! $comment_id ) {
			return self::error( 'wpitravel_review_failed', __( 'Your review could not be saved. Try again.', 'wpinterface-travel' ), 500 );
		}

		self::recalculate( $trip_id );

		if ( $verified ) {
			( new BookingRepository() )->log( (int) $author['booking']['id'], 'review_written', __( 'Customer wrote a review.', 'wpinterface-travel' ), array( 'comment_id' => $comment_id ) );
		}

		return array(
			'id'        => (int) $comment_id,
			'published' => 1 === $approved,
		);
	}

	/**
	 * Rating summary of a trip.
	 *
	 * @param int $trip_id Trip.
	 * @return array{average: float, count: int, distribution: array<int, int>}
	 */
	public static function summary( int $trip_id ): array {
		$stored = get_post_meta( $trip_id, self::RATING_META, true );

		return is_array( $stored ) && isset( $stored['count'] )
			? $stored
			: array(
				'average'      => 0.0,
				'count'        => 0,
				'distribution' => array_fill_keys( range( 1, 5 ), 0 ),
			);
	}

	/**
	 * Published reviews, newest first.
	 *
	 * @param int $trip_id  Trip.
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return list<array<string, mixed>>
	 */
	public static function list( int $trip_id, int $page = 1, int $per_page = 10 ): array {
		$comments = get_comments(
			array(
				'type'    => self::TYPE,
				'post_id' => $trip_id,
				'status'  => 'approve',
				'number'  => $per_page,
				'offset'  => max( 0, $page - 1 ) * $per_page,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);

		return array_map(
			static fn( WP_Comment $comment ): array => array(
				'id'       => (int) $comment->comment_ID,
				'author'   => $comment->comment_author,
				'date'     => mysql2date( 'Y-m-d', $comment->comment_date ),
				'rating'   => (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
				'title'    => (string) get_comment_meta( (int) $comment->comment_ID, 'title', true ),
				'content'  => $comment->comment_content,
				'verified' => (bool) get_comment_meta( (int) $comment->comment_ID, 'verified', true ),
			),
			is_array( $comments ) ? $comments : array()
		);
	}

	/**
	 * Recomputes a trip's average and count from published reviews.
	 *
	 * @param int $trip_id Trip.
	 */
	public static function recalculate( int $trip_id ): void {
		$ids = get_comments(
			array(
				'type'    => self::TYPE,
				'post_id' => $trip_id,
				'status'  => 'approve',
				'fields'  => 'ids',
				'number'  => 0,
			)
		);

		$distribution = array_fill_keys( range( 1, 5 ), 0 );

		foreach ( (array) $ids as $id ) {
			$rating = (int) get_comment_meta( (int) $id, 'rating', true );

			if ( $rating >= 1 && $rating <= 5 ) {
				++$distribution[ $rating ];
			}
		}

		$count = array_sum( $distribution );
		$sum   = 0;

		foreach ( $distribution as $stars => $number ) {
			$sum += $stars * $number;
		}

		update_post_meta(
			$trip_id,
			self::RATING_META,
			array(
				'average'      => $count ? round( $sum / $count, 1 ) : 0.0,
				'count'        => $count,
				'distribution' => $distribution,
			)
		);
	}

	/**
	 * Closes the core comment form on trips.
	 *
	 * @param bool $open    Open.
	 * @param int  $post_id Post ID.
	 */
	public function close_trip_comments( $open, $post_id ): bool {
		return PostTypes::TRIP === get_post_type( (int) $post_id ) ? false : (bool) $open;
	}

	/**
	 * Recalculates when a review is approved, held, spammed or trashed.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_transition( $new_status, $old_status, $comment ): void {
		if ( $comment instanceof WP_Comment && self::TYPE === $comment->comment_type ) {
			self::recalculate( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Recalculates after a review is deleted.
	 *
	 * @param string     $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_deleted( $comment_id, $comment ): void {
		$this->on_transition( '', '', $comment );
	}

	/**
	 * Recalculates after a review is edited.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_edit( $comment_id ): void {
		$this->on_transition( '', '', get_comment( (int) $comment_id ) );
	}

	/**
	 * Adds "Trip reviews" to the Comments screen's type filter.
	 *
	 * @param array<string, string> $types Types.
	 * @return array<string, string>
	 */
	public function admin_type( $types ): array {
		$types               = is_array( $types ) ? $types : array();
		$types[ self::TYPE ] = __( 'Trip reviews', 'wpinterface-travel' );

		return $types;
	}

	/**
	 * Adds a Rating column to the Comments screen.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function admin_columns( $columns ): array {
		$columns                     = is_array( $columns ) ? $columns : array();
		$columns['wpitravel_rating'] = __( 'Rating', 'wpinterface-travel' );

		return $columns;
	}

	/**
	 * Prints the rating of reviews.
	 *
	 * @param string $column     Column.
	 * @param int    $comment_id Comment ID.
	 */
	public function admin_column( $column, $comment_id ): void {
		if ( 'wpitravel_rating' !== $column || self::TYPE !== get_comment_type( (int) $comment_id ) ) {
			return;
		}

		$rating = (int) get_comment_meta( (int) $comment_id, 'rating', true );

		printf(
			'<span aria-label="%1$s">%2$s</span>%3$s',
			/* translators: %d: stars out of 5. */
			esc_attr( sprintf( __( '%d out of 5 stars', 'wpinterface-travel' ), $rating ) ),
			esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ),
			get_comment_meta( (int) $comment_id, 'verified', true ) ? '<br><small>' . esc_html__( 'Verified booking', 'wpinterface-travel' ) . '</small>' : ''
		);
	}

	/**
	 * The reviewer: a verified booking, or a named guest when the site allows anyone.
	 *
	 * @param int                  $trip_id Trip.
	 * @param array<string, mixed> $data    Request data.
	 * @return array{name: string, email: string, booking: array<string, mixed>|null}|WP_Error
	 */
	private function author( int $trip_id, array $data ) {
		$key = (string) ( $data['booking_key'] ?? '' );

		if ( '' !== $key ) {
			$booking = ( new BookingRepository() )->find_by_key( $key );

			if ( ! $booking || (int) $booking['trip_id'] !== $trip_id || ! BookingAccess::is_customer( $booking, (string) ( $data['token'] ?? '' ) ) ) {
				return self::error( 'wpitravel_review_not_allowed', __( 'We could not find your booking for this trip.', 'wpinterface-travel' ), 403 );
			}

			if ( ! self::can_review( $booking ) ) {
				return self::error( 'wpitravel_review_not_allowed', __( 'This booking cannot be reviewed (yet), or was already reviewed.', 'wpinterface-travel' ), 409 );
			}

			$customer = ( new BookingRepository() )->customer( $booking );

			return array(
				// First name and initial only, for privacy.
				'name'    => trim( $customer['first_name'] . ' ' . mb_substr( $customer['last_name'], 0, 1 ) . ( '' !== $customer['last_name'] ? '.' : '' ) ),
				'email'   => $customer['email'],
				'booking' => $booking,
			);
		}

		if ( 'anyone' !== wpitravel()->options()->get( 'reviews.who', 'verified' ) ) {
			return self::error( 'wpitravel_review_not_allowed', __( 'Only travelers who booked this trip can review it, from the link in their booking.', 'wpinterface-travel' ), 403 );
		}

		$name  = substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 100 );
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );

		if ( '' === $name || ! is_email( $email ) ) {
			return self::error( 'wpitravel_invalid_review', __( 'Enter your name and a valid email address.', 'wpinterface-travel' ), 400, 'email' );
		}

		return array(
			'name'    => $name,
			'email'   => $email,
			'booking' => null,
		);
	}

	/**
	 * Error helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param string $field   Field.
	 */
	private static function error( string $code, string $message, int $status, string $field = '' ): WP_Error {
		$data = array( 'status' => $status );

		if ( '' !== $field ) {
			$data['field'] = $field;
		}

		return new WP_Error( $code, $message, $data );
	}
}
