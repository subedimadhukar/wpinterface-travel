<?php
/**
 * Upcoming departures with seats left and a link to book each one.
 *
 * Override: yourtheme/wpitravel/parts/departures.php
 *
 * @package WPInterface\Travel
 *
 * @var array{departures: list<array{date: string, end_date: string, time: string, seats_left: int|null, bookable: bool, price: string, amount: string, url: string}>} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="wpitravel-section wpitravel-departures">
	<h2 class="wpitravel-section__title"><?php esc_html_e( 'Upcoming departures', 'wpinterface-travel' ); ?></h2>
	<ul class="wpitravel-departures__list">
		<?php foreach ( $args['departures'] as $wpitravel_departure ) : ?>
			<?php
			$wpitravel_start = date_i18n( get_option( 'date_format' ), (int) strtotime( $wpitravel_departure['date'] . ' 12:00:00' ) );
			$wpitravel_end   = $wpitravel_departure['end_date'] !== $wpitravel_departure['date'] ? date_i18n( get_option( 'date_format' ), (int) strtotime( $wpitravel_departure['end_date'] . ' 12:00:00' ) ) : '';
			?>
			<li class="wpitravel-departures__item<?php echo $wpitravel_departure['bookable'] ? '' : ' is-full'; ?>">
				<span class="wpitravel-departures__date">
					<time datetime="<?php echo esc_attr( $wpitravel_departure['date'] ); ?>"><?php echo esc_html( $wpitravel_start ); ?></time>
					<?php if ( '' !== $wpitravel_end ) : ?>
						– <time datetime="<?php echo esc_attr( $wpitravel_departure['end_date'] ); ?>"><?php echo esc_html( $wpitravel_end ); ?></time>
					<?php endif; ?>
					<?php if ( '' !== $wpitravel_departure['time'] ) : ?>
						<span class="wpitravel-departures__time"><?php echo esc_html( $wpitravel_departure['time'] ); ?></span>
					<?php endif; ?>
				</span>
				<span class="wpitravel-departures__seats">
					<?php
					if ( ! $wpitravel_departure['bookable'] ) {
						esc_html_e( 'Full', 'wpinterface-travel' );
					} elseif ( null !== $wpitravel_departure['seats_left'] ) {
						/* translators: %d: seats left. */
						echo esc_html( sprintf( _n( '%d seat left', '%d seats left', $wpitravel_departure['seats_left'], 'wpinterface-travel' ), $wpitravel_departure['seats_left'] ) );
					}
					?>
				</span>
				<?php if ( '' !== $wpitravel_departure['price'] ) : ?>
					<span class="wpitravel-departures__price">
						<?php esc_html_e( 'From', 'wpinterface-travel' ); ?>
						<strong data-wpitravel-amount="<?php echo esc_attr( $wpitravel_departure['amount'] ); ?>"><?php echo esc_html( $wpitravel_departure['price'] ); ?></strong>
					</span>
				<?php endif; ?>
				<?php if ( $wpitravel_departure['bookable'] ) : ?>
					<a class="wpitravel-departures__book wp-element-button" href="<?php echo esc_url( $wpitravel_departure['url'] ); ?>">
						<?php esc_html_e( 'Book', 'wpinterface-travel' ); ?>
						<span class="screen-reader-text">
							<?php
							/* translators: %s: departure date. */
							echo esc_html( sprintf( __( 'the departure on %s', 'wpinterface-travel' ), $wpitravel_start ) );
							?>
						</span>
					</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
