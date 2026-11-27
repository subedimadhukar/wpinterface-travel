<?php
/**
 * Announcement bar.
 *
 * Override in a theme at wpitravel/parts/announcement-bar.php.
 *
 * @package WPInterface\Travel
 *
 * @var array<string, mixed> $args bar, messages, classes, key, start, end, count, move.
 */

defined( 'ABSPATH' ) || exit;

$wpitravel_bar      = $args['bar'];
$wpitravel_messages = $args['messages'];
$wpitravel_many     = count( $wpitravel_messages ) > 1;
?>
<div class="<?php echo esc_attr( implode( ' ', $args['classes'] ) ); ?>" role="region" aria-label="<?php esc_attr_e( 'Announcement', 'wpinterface-travel' ); ?>" data-wpitravel-bar="<?php echo esc_attr( $args['key'] ); ?>" data-bar-id="<?php echo esc_attr( (string) $wpitravel_bar['id'] ); ?>" data-rotate="<?php echo esc_attr( (string) (int) $wpitravel_bar['rotate'] ); ?>" data-dismiss-days="<?php echo esc_attr( (string) (int) $wpitravel_bar['dismiss_days'] ); ?>"<?php echo $args['start'] ? ' data-start="' . esc_attr( (string) $args['start'] ) . '"' : ''; ?><?php echo $args['end'] ? ' data-end="' . esc_attr( (string) $args['end'] ) . '"' : ''; ?><?php echo $args['move'] ? ' data-move="top"' : ''; ?><?php echo ! empty( $wpitravel_bar['rules'] ) ? ' data-rules="' . esc_attr( (string) wp_json_encode( $wpitravel_bar['rules'] ) ) . '"' : ''; ?>>
	<div class="wpitravel-bar__inner">
		<?php if ( $wpitravel_many ) : ?>
			<button type="button" class="wpitravel-bar__nav wpitravel-bar__prev" data-bar-step="-1" hidden>
				<span class="screen-reader-text"><?php esc_html_e( 'Previous message', 'wpinterface-travel' ); ?></span>
				<span class="wpitravel-bar__icon" aria-hidden="true"></span>
			</button>
		<?php endif; ?>

		<div class="wpitravel-bar__messages" aria-live="off">
			<?php foreach ( $wpitravel_messages as $wpitravel_index => $wpitravel_message ) : ?>
				<p class="wpitravel-bar__message<?php echo 0 === $wpitravel_index ? ' is-current' : ''; ?>"<?php echo 0 === $wpitravel_index ? '' : ' hidden'; ?>>
					<?php if ( '' !== (string) $wpitravel_message['text'] ) : ?>
						<span class="wpitravel-bar__text"><?php echo wp_kses( (string) $wpitravel_message['text'], \WPInterface\Travel\Announcements\AnnouncementBar::text_kses() ); ?></span>
					<?php endif; ?>

					<?php if ( $args['count'] && 0 === $wpitravel_index ) : ?>
						<span class="wpitravel-bar__countdown" data-countdown="<?php echo esc_attr( (string) $args['count'] ); ?>">
							<?php if ( '' !== (string) $wpitravel_bar['count_label'] ) : ?>
								<span class="wpitravel-bar__count-label"><?php echo esc_html( (string) $wpitravel_bar['count_label'] ); ?></span>
							<?php endif; ?>
							<time class="wpitravel-bar__count" datetime="<?php echo esc_attr( wp_date( 'c', (int) $args['count'] ) ); ?>"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) $args['count'] ) ); ?></time>
						</span>
					<?php endif; ?>

					<?php if ( '' !== (string) $wpitravel_message['label'] && '' !== (string) $wpitravel_message['url'] ) : ?>
						<a class="wpitravel-bar__button" href="<?php echo esc_url( (string) $wpitravel_message['url'] ); ?>"<?php echo ! empty( $wpitravel_message['new_tab'] ) ? ' target="_blank" rel="noopener"' : ''; ?> data-bar-click><?php echo esc_html( (string) $wpitravel_message['label'] ); ?>
						<?php
						if ( ! empty( $wpitravel_message['new_tab'] ) ) :
							?>
							<span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'wpinterface-travel' ); ?></span><?php endif; ?></a>
					<?php endif; ?>

					<?php
					/**
					 * Fires at the end of a message, for extras such as a coupon code.
					 *
					 * @param array<string, mixed> $message Message.
					 * @param array<string, mixed> $bar     Bar.
					 */
					do_action( 'wpitravel_announcement_message_end', $wpitravel_message, $wpitravel_bar );
					?>
				</p>
			<?php endforeach; ?>
		</div>

		<?php if ( $wpitravel_many ) : ?>
			<button type="button" class="wpitravel-bar__nav wpitravel-bar__next" data-bar-step="1" hidden>
				<span class="screen-reader-text"><?php esc_html_e( 'Next message', 'wpinterface-travel' ); ?></span>
				<span class="wpitravel-bar__icon" aria-hidden="true"></span>
			</button>
			<button type="button" class="wpitravel-bar__nav wpitravel-bar__pause" aria-pressed="false" hidden>
				<span class="screen-reader-text"><?php esc_html_e( 'Pause messages', 'wpinterface-travel' ); ?></span>
				<span class="wpitravel-bar__icon" aria-hidden="true"></span>
			</button>
		<?php endif; ?>

		<?php if ( ! empty( $wpitravel_bar['dismissible'] ) ) : ?>
			<button type="button" class="wpitravel-bar__close" data-bar-close>
				<span class="screen-reader-text"><?php esc_html_e( 'Close the announcement', 'wpinterface-travel' ); ?></span>
				<span class="wpitravel-bar__icon" aria-hidden="true"></span>
			</button>
		<?php endif; ?>
	</div>
</div>
