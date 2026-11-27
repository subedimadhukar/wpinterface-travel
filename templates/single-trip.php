<?php
/**
 * Single trip page for classic themes.
 *
 * Override: copy to yourtheme/wpitravel/single-trip.php. Sections are
 * rendered by WPInterface\Travel\Frontend\Parts, and each section's markup
 * can be overridden on its own in yourtheme/wpitravel/parts/.
 *
 * @package WPInterface\Travel
 */

use WPInterface\Travel\Frontend\Parts;
use WPInterface\Travel\Frontend\Templates;
use WPInterface\Travel\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$wpitravel_trip_id = (int) get_the_ID();
	$wpitravel_rating  = Reviews::summary( $wpitravel_trip_id );
	$wpitravel_places  = get_the_terms( $wpitravel_trip_id, 'wpitravel_destination' );

	/**
	 * Main column sections of the single trip template, in order.
	 *
	 * @param string[] $sections Section names (see Parts::sections()).
	 * @param int      $trip_id  Trip.
	 */
	$wpitravel_main = (array) apply_filters( 'wpitravel_single_trip_sections', array( 'facts', 'content', 'highlights', 'itinerary', 'departures', 'includes', 'map', 'faqs', 'reviews' ), $wpitravel_trip_id );

	/**
	 * Sidebar sections of the single trip template, in order.
	 *
	 * @param string[] $sections Section names.
	 * @param int      $trip_id  Trip.
	 */
	$wpitravel_side     = (array) apply_filters( 'wpitravel_single_trip_sidebar', array( 'price', 'booking' ), $wpitravel_trip_id );
	$wpitravel_sections = Parts::sections();
	?>
	<main id="primary" class="wpitravel-page wpitravel-page--single">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'wpitravel-trip' ); ?>>
			<header class="wpitravel-trip__header">
				<?php if ( is_array( $wpitravel_places ) && $wpitravel_places ) : ?>
					<p class="wpitravel-trip__eyebrow">
						<?php
						echo wp_kses_post(
							implode(
								', ',
								array_map(
									static fn( WP_Term $term ): string => sprintf( '<a href="%s">%s</a>', esc_url( (string) get_term_link( $term ) ), esc_html( $term->name ) ),
									$wpitravel_places
								)
							)
						);
						?>
					</p>
				<?php endif; ?>
				<?php the_title( '<h1 class="wpitravel-trip__title">', '</h1>' ); ?>
				<?php if ( $wpitravel_rating['count'] > 0 ) : ?>
					<p class="wpitravel-trip__rating">
						<span aria-hidden="true">★</span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: average rating, 2: number of reviews. */
								_n( '%1$s · %2$d review', '%1$s · %2$d reviews', $wpitravel_rating['count'], 'wpinterface-travel' ),
								number_format_i18n( $wpitravel_rating['average'], 1 ),
								$wpitravel_rating['count']
							)
						);
						?>
					</p>
				<?php endif; ?>
			</header>

			<?php
			if ( post_password_required() ) :
				// A password-protected trip shows only the password form until it is unlocked.
				echo '<div class="wpitravel-trip__content">';
				the_content();
				echo '</div>';
			else :
				Templates::output( Parts::gallery( $wpitravel_trip_id ) );
				?>

			<div class="wpitravel-trip__layout">
				<div class="wpitravel-trip__main">
					<?php
					foreach ( $wpitravel_main as $wpitravel_section ) {
						if ( 'content' === $wpitravel_section ) {
							echo '<div class="wpitravel-trip__content">';
							the_content();
							echo '</div>';
						} elseif ( isset( $wpitravel_sections[ $wpitravel_section ] ) ) {
							Templates::output( (string) call_user_func( $wpitravel_sections[ $wpitravel_section ], $wpitravel_trip_id, array() ) );
						}
					}
					?>
				</div>
				<aside class="wpitravel-trip__sidebar" aria-label="<?php esc_attr_e( 'Book this trip', 'wpinterface-travel' ); ?>">
					<div class="wpitravel-trip__sticky">
						<?php
						foreach ( $wpitravel_side as $wpitravel_section ) {
							if ( isset( $wpitravel_sections[ $wpitravel_section ] ) ) {
								Templates::output( (string) call_user_func( $wpitravel_sections[ $wpitravel_section ], $wpitravel_trip_id, array() ) );
							}
						}
						?>
					</div>
				</aside>
			</div>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
