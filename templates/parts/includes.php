<?php
/**
 * Included and excluded.
 *
 * Override: yourtheme/wpitravel/parts/includes.php
 *
 * @package WPInterface\Travel
 *
 * @var array{included: list<string>, excluded: list<string>} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="wpitravel-section wpitravel-includes">
	<?php if ( $args['included'] ) : ?>
		<div>
			<h2 class="wpitravel-section__title"><?php esc_html_e( 'Included', 'wpinterface-travel' ); ?></h2>
			<ul class="wpitravel-checklist">
				<?php foreach ( $args['included'] as $wpitravel_item ) : ?>
					<li><?php echo esc_html( $wpitravel_item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
	<?php if ( $args['excluded'] ) : ?>
		<div>
			<h2 class="wpitravel-section__title"><?php esc_html_e( 'Not included', 'wpinterface-travel' ); ?></h2>
			<ul class="wpitravel-checklist wpitravel-checklist--excluded">
				<?php foreach ( $args['excluded'] as $wpitravel_item ) : ?>
					<li><?php echo esc_html( $wpitravel_item ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</section>
