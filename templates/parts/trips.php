<?php
/**
 * Trip listing.
 *
 * Override: yourtheme/wpitravel/parts/trips.php
 *
 * @package WPInterface\Travel
 *
 * @var array{cards: list<array<string, mixed>>, columns: int, layout: string, show: array<string, bool>, pagination: string} $args
 */

use WPInterface\Travel\Frontend\Templates;

defined( 'ABSPATH' ) || exit;
?>
<div class="wpitravel-trips">
	<?php if ( ! $args['cards'] ) : ?>
		<p class="wpitravel-trips__empty"><?php esc_html_e( 'No trips match your search. Try fewer filters.', 'wpinterface-travel' ); ?></p>
	<?php else : ?>
		<div class="wpitravel-trips__items wpitravel-trips__items--<?php echo esc_attr( $args['layout'] ); ?>" style="--wpitravel-columns: <?php echo (int) $args['columns']; ?>">
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- The part escapes its output.
			foreach ( $args['cards'] as $wpitravel_card ) {
				echo Templates::part(
					'parts/trip-card',
					array(
						'card' => $wpitravel_card,
						'show' => $args['show'],
					)
				);
			}
			// phpcs:enable
			?>
		</div>
		<?php if ( '' !== $args['pagination'] ) : ?>
			<nav class="wpitravel-pagination" aria-label="<?php esc_attr_e( 'More trips', 'wpinterface-travel' ); ?>">
				<?php echo wp_kses_post( $args['pagination'] ); ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
