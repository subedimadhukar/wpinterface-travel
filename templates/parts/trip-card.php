<?php
/**
 * One trip in a listing.
 *
 * Override: yourtheme/wpitravel/parts/trip-card.php
 *
 * @package WPInterface\Travel
 *
 * @var array{card: array<string, mixed>, show: array{price: bool, rating: bool, excerpt: bool}} $args
 */

defined( 'ABSPATH' ) || exit;

$wpitravel_card = $args['card'];
?>
<article class="wpitravel-card" data-trip-id="<?php echo esc_attr( (string) $wpitravel_card['id'] ); ?>">
	<a class="wpitravel-card__media" href="<?php echo esc_url( $wpitravel_card['url'] ); ?>" tabindex="-1" aria-hidden="true">
		<?php
		if ( $wpitravel_card['image'] ) {
			echo wp_kses_post( $wpitravel_card['image'] );
		} else {
			echo '<span class="wpitravel-card__placeholder"></span>';
		}
		?>
	</a>
	<div class="wpitravel-card__body">
		<?php if ( $wpitravel_card['destinations'] ) : ?>
			<p class="wpitravel-card__eyebrow"><?php echo esc_html( implode( ', ', $wpitravel_card['destinations'] ) ); ?></p>
		<?php endif; ?>
		<h3 class="wpitravel-card__title"><a href="<?php echo esc_url( $wpitravel_card['url'] ); ?>"><?php echo esc_html( $wpitravel_card['title'] ); ?></a></h3>
		<p class="wpitravel-card__meta">
			<?php echo esc_html( implode( ' · ', array_filter( array( $wpitravel_card['duration'], $wpitravel_card['difficulty'] ) ) ) ); ?>
			<?php if ( $args['show']['rating'] && $wpitravel_card['rating']['count'] > 0 ) : ?>
				<span class="wpitravel-card__rating">
					<span aria-hidden="true">★</span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: average rating, 2: number of reviews. */
							__( '%1$s (%2$d)', 'wpinterface-travel' ),
							number_format_i18n( $wpitravel_card['rating']['average'], 1 ),
							$wpitravel_card['rating']['count']
						)
					);
					?>
					<span class="screen-reader-text"><?php esc_html_e( 'out of 5 stars', 'wpinterface-travel' ); ?></span>
				</span>
			<?php endif; ?>
		</p>
		<?php if ( $args['show']['excerpt'] && '' !== $wpitravel_card['excerpt'] ) : ?>
			<p class="wpitravel-card__excerpt"><?php echo esc_html( $wpitravel_card['excerpt'] ); ?></p>
		<?php endif; ?>
		<?php if ( $args['show']['price'] && $wpitravel_card['price'] ) : ?>
			<p class="wpitravel-card__price">
				<?php esc_html_e( 'From', 'wpinterface-travel' ); ?>
				<?php if ( $wpitravel_card['price']['sale'] ) : ?>
					<del data-wpitravel-amount="<?php echo esc_attr( (string) ( $wpitravel_card['price']['regular_amount'] ?? '' ) ); ?>"><?php echo esc_html( $wpitravel_card['price']['regular'] ); ?></del>
				<?php endif; ?>
				<strong data-wpitravel-amount="<?php echo esc_attr( (string) ( $wpitravel_card['price']['amount'] ?? '' ) ); ?>"><?php echo esc_html( $wpitravel_card['price']['price'] ); ?></strong>
			</p>
		<?php endif; ?>
	</div>
</article>
