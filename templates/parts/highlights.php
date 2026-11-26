<?php
/**
 * Trip highlights.
 *
 * Override: yourtheme/wpitravel/parts/highlights.php
 *
 * @package WPInterface\Travel
 *
 * @var array{items: list<string>} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="wpitravel-section wpitravel-highlights">
	<h2 class="wpitravel-section__title"><?php esc_html_e( 'Highlights', 'wpinterface-travel' ); ?></h2>
	<ul class="wpitravel-checklist">
		<?php foreach ( $args['items'] as $wpitravel_item ) : ?>
			<li><?php echo esc_html( $wpitravel_item ); ?></li>
		<?php endforeach; ?>
	</ul>
</section>
