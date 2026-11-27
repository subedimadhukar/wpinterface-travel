<?php
/**
 * Reviews on trip pages.
 *
 * @package WPInterface\Travel
 */

namespace WPInterface\Travel\Frontend;

use WPInterface\Travel\Content\PostTypes;
use WPInterface\Travel\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the rating summary and the latest reviews in plain HTML (no
 * JavaScript), so search engines see them. [wpitravel_reviews trip="123"]
 * places them anywhere; trip pages get them after the booking form.
 */
final class ReviewsDisplay {

	public const SHORTCODE = 'wpitravel_reviews';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_filter( 'the_content', array( $this, 'append_to_trip' ), 30 );
	}

	/**
	 * Shortcode.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'trip' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$id   = absint( $atts['trip'] ) ? absint( $atts['trip'] ) : (int) get_the_ID();

		return $this->render( $id );
	}

	/**
	 * Adds reviews after the trip content.
	 *
	 * @param string $content Content.
	 */
	public function append_to_trip( $content ): string {
		$content = (string) $content;

		if ( ! is_singular( PostTypes::TRIP ) || ! in_the_loop() || ! is_main_query() || ! wpitravel()->options()->get( 'booking.auto_widget', true ) || has_shortcode( $content, self::SHORTCODE ) || Templates::renders_parts() ) {
			return $content;
		}

		return $content . $this->render( (int) get_the_ID() );
	}

	/**
	 * Reviews HTML. Empty when there are none.
	 *
	 * @param int $trip_id Trip.
	 */
	public function render( int $trip_id ): string {
		if ( ! wpitravel()->options()->get( 'reviews.enabled', true ) || PostTypes::TRIP !== get_post_type( $trip_id ) ) {
			return '';
		}

		$summary = Reviews::summary( $trip_id );

		// "Anyone can review": the section and its form show even before the first review.
		$open = 'anyone' === wpitravel()->options()->get( 'reviews.who', 'verified' ) && 'publish' === get_post_status( $trip_id );

		if ( $summary['count'] < 1 && ! $open ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page    = isset( $_GET['reviews_page'] ) ? max( 1, absint( wp_unslash( $_GET['reviews_page'] ) ) ) : 1;
		$pages   = (int) ceil( $summary['count'] / 10 );
		$reviews = Reviews::list( $trip_id, min( $page, max( 1, $pages ) ) );

		wp_enqueue_style( 'wpitravel-reviews', WPITRAVEL_URL . 'assets/css/reviews.css', array(), WPITRAVEL_VERSION );

		ob_start();
		?>
		<section class="wpitravel-reviews" id="wpitravel-reviews" aria-labelledby="wpitravel-reviews-title">
			<h2 id="wpitravel-reviews-title"><?php esc_html_e( 'Reviews', 'wpinterface-travel' ); ?></h2>
			<?php if ( $summary['count'] < 1 ) : ?>
				<p class="wpitravel-reviews__empty"><?php esc_html_e( 'No reviews yet. Travelled with us? Be the first to share how it went.', 'wpinterface-travel' ); ?></p>
			<?php else : ?>
			<p class="wpitravel-reviews__summary">
				<span class="wpitravel-stars" aria-hidden="true"><?php echo esc_html( self::stars( (int) round( $summary['average'] ) ) ); ?></span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: average rating, 2: number of reviews. */
						_n( '%1$s out of 5 · %2$d review', '%1$s out of 5 · %2$d reviews', $summary['count'], 'wpinterface-travel' ),
						number_format_i18n( $summary['average'], 1 ),
						$summary['count']
					)
				);
				?>
			</p>
			<ol class="wpitravel-reviews__list">
				<?php foreach ( $reviews as $review ) : ?>
					<li class="wpitravel-review">
						<p class="wpitravel-review__meta">
							<span class="wpitravel-stars" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: stars out of 5. */ __( '%d out of 5 stars', 'wpinterface-travel' ), $review['rating'] ) ); ?>"><?php echo esc_html( self::stars( $review['rating'] ) ); ?></span>
							<?php if ( '' !== $review['title'] ) : ?>
								<strong><?php echo esc_html( $review['title'] ); ?></strong>
							<?php endif; ?>
						</p>
						<div class="wpitravel-review__content"><?php echo wp_kses_post( wpautop( esc_html( $review['content'] ) ) ); ?></div>
						<p class="wpitravel-review__author">
							<?php echo esc_html( $review['author'] ); ?> ·
							<time datetime="<?php echo esc_attr( $review['date'] ); ?>"><?php echo esc_html( mysql2date( (string) get_option( 'date_format' ), $review['date'] ) ); ?></time>
							<?php if ( $review['verified'] ) : ?>
								· <?php esc_html_e( 'Verified traveler', 'wpinterface-travel' ); ?>
							<?php endif; ?>
						</p>
					</li>
				<?php endforeach; ?>
			</ol>
				<?php if ( $pages > 1 ) : ?>
				<nav class="wpitravel-reviews__pages" aria-label="<?php esc_attr_e( 'More reviews', 'wpinterface-travel' ); ?>">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'reviews_page', '%#%' ) . '#wpitravel-reviews',
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
			<?php endif; ?>
			<?php
			if ( $open ) {
				$this->form( $trip_id );
			}
			?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Prints the public review form, for "Anyone can review". Sent by
	 * assets/js/review-form.js to the reviews REST route, which checks,
	 * rate-limits and holds the review for moderation.
	 *
	 * @param int $trip_id Trip.
	 */
	private function form( int $trip_id ): void {
		$id = 'wpitravel-review-' . $trip_id;

		wp_enqueue_script( 'wpitravel-review-form', WPITRAVEL_URL . 'assets/js/review-form.js', array(), WPITRAVEL_VERSION, true );
		wp_localize_script(
			'wpitravel-review-form',
			'wpitravelReviewForm',
			array(
				'rest'   => esc_url_raw( rest_url( 'wpinterface-travel/v1/trips/' ) ),
				'thanks' => __( 'Thank you! Your review will appear once we have checked it.', 'wpinterface-travel' ),
				'live'   => __( 'Thank you! Your review is now live.', 'wpinterface-travel' ),
				'stars'  => __( 'Choose 1 to 5 stars.', 'wpinterface-travel' ),
				'failed' => __( 'Your review could not be sent. Try again.', 'wpinterface-travel' ),
			)
		);

		?>
		<details class="wpitravel-review-form">
			<summary><?php esc_html_e( 'Write a review', 'wpinterface-travel' ); ?></summary>
			<form data-wpitravel-review-form="<?php echo esc_attr( (string) $trip_id ); ?>" novalidate>
				<fieldset class="wpitravel-review-form__stars">
					<legend><?php esc_html_e( 'Your rating', 'wpinterface-travel' ); ?></legend>
					<span class="wpitravel-review-form__star-row">
					<?php for ( $star = 5; $star >= 1; $star-- ) : ?>
						<input type="radio" id="<?php echo esc_attr( $id . '-star-' . $star ); ?>" name="rating" value="<?php echo esc_attr( (string) $star ); ?>">
						<label for="<?php echo esc_attr( $id . '-star-' . $star ); ?>" title="<?php echo esc_attr( sprintf( /* translators: %d: stars out of 5. */ _n( '%d star', '%d stars', $star, 'wpinterface-travel' ), $star ) ); ?>">
							<span aria-hidden="true">★</span>
							<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %d: stars out of 5. */ _n( '%d star', '%d stars', $star, 'wpinterface-travel' ), $star ) ); ?></span>
						</label>
					<?php endfor; ?>
					</span>
				</fieldset>
				<p class="wpitravel-review-form__row">
					<span class="wpitravel-review-form__field">
						<label for="<?php echo esc_attr( $id . '-name' ); ?>"><?php esc_html_e( 'Your name', 'wpinterface-travel' ); ?></label>
						<input id="<?php echo esc_attr( $id . '-name' ); ?>" type="text" name="name" maxlength="100" autocomplete="name" required>
					</span>
					<span class="wpitravel-review-form__field">
						<label for="<?php echo esc_attr( $id . '-email' ); ?>"><?php esc_html_e( 'Email (not shown)', 'wpinterface-travel' ); ?></label>
						<input id="<?php echo esc_attr( $id . '-email' ); ?>" name="email" type="email" maxlength="100" autocomplete="email" required>
					</span>
				</p>
				<p class="wpitravel-review-form__field">
					<label for="<?php echo esc_attr( $id . '-title' ); ?>"><?php esc_html_e( 'Title (optional)', 'wpinterface-travel' ); ?></label>
					<input id="<?php echo esc_attr( $id . '-title' ); ?>" type="text" name="title" maxlength="120">
				</p>
				<p class="wpitravel-review-form__field">
					<label for="<?php echo esc_attr( $id . '-content' ); ?>"><?php esc_html_e( 'Your review', 'wpinterface-travel' ); ?></label>
					<textarea id="<?php echo esc_attr( $id . '-content' ); ?>" name="content" rows="5" minlength="10" maxlength="5000" required></textarea>
				</p>
				<p class="wpitravel-review-form__message" role="status" aria-live="polite"></p>
				<p><button type="submit" class="wp-element-button"><?php esc_html_e( 'Send review', 'wpinterface-travel' ); ?></button></p>
				<noscript><p><?php esc_html_e( 'Turn on JavaScript to send a review.', 'wpinterface-travel' ); ?></p></noscript>
			</form>
		</details>
		<?php
	}

	/**
	 * Star characters for a rating.
	 *
	 * @param int $rating 0–5.
	 */
	private static function stars( int $rating ): string {
		$rating = max( 0, min( 5, $rating ) );

		return str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
	}
}
