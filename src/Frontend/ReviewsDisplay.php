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

		if ( $summary['count'] < 1 ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page    = isset( $_GET['reviews_page'] ) ? max( 1, absint( $_GET['reviews_page'] ) ) : 1;
		$pages   = (int) ceil( $summary['count'] / 10 );
		$reviews = Reviews::list( $trip_id, min( $page, max( 1, $pages ) ) );

		wp_enqueue_style( 'wpitravel-reviews', WPITRAVEL_URL . 'assets/css/reviews.css', array(), WPITRAVEL_VERSION );

		ob_start();
		?>
		<section class="wpitravel-reviews" id="wpitravel-reviews" aria-labelledby="wpitravel-reviews-title">
			<h2 id="wpitravel-reviews-title"><?php esc_html_e( 'Reviews', 'wpinterface-travel' ); ?></h2>
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
		</section>
		<?php
		return (string) ob_get_clean();
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
