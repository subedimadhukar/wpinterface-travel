<?php
/**
 * Day-by-day itinerary.
 *
 * Override: yourtheme/wpitravel/parts/itinerary.php
 *
 * @package WPInterface\Travel
 *
 * @var array{days: list<array{title: string, description: string, meals: list<string>, accommodation: string}>, open: bool} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="wpitravel-section wpitravel-itinerary">
	<h2 class="wpitravel-section__title"><?php esc_html_e( 'Itinerary', 'wpinterface-travel' ); ?></h2>
	<ol class="wpitravel-itinerary__days">
		<?php foreach ( $args['days'] as $wpitravel_index => $wpitravel_day ) : ?>
			<li class="wpitravel-itinerary__day">
				<details <?php echo ( $args['open'] || 0 === $wpitravel_index ) ? 'open' : ''; ?>>
					<summary>
						<span class="wpitravel-itinerary__number">
							<?php
							/* translators: %d: day number. */
							echo esc_html( sprintf( __( 'Day %d', 'wpinterface-travel' ), $wpitravel_index + 1 ) );
							?>
						</span>
						<span class="wpitravel-itinerary__title"><?php echo esc_html( $wpitravel_day['title'] ); ?></span>
					</summary>
					<div class="wpitravel-itinerary__body">
						<?php echo wp_kses_post( wpautop( $wpitravel_day['description'] ) ); ?>
						<?php if ( $wpitravel_day['meals'] || '' !== $wpitravel_day['accommodation'] ) : ?>
							<ul class="wpitravel-itinerary__meta">
								<?php if ( $wpitravel_day['meals'] ) : ?>
									<li><strong><?php esc_html_e( 'Meals:', 'wpinterface-travel' ); ?></strong> <?php echo esc_html( implode( ', ', $wpitravel_day['meals'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( '' !== $wpitravel_day['accommodation'] ) : ?>
									<li><strong><?php esc_html_e( 'Stay:', 'wpinterface-travel' ); ?></strong> <?php echo esc_html( $wpitravel_day['accommodation'] ); ?></li>
								<?php endif; ?>
							</ul>
						<?php endif; ?>
					</div>
				</details>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
