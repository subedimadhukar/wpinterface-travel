<?php
/**
 * Trip facts.
 *
 * Override: yourtheme/wpitravel/parts/facts.php
 *
 * @package WPInterface\Travel
 *
 * @var array{facts: list<array{key: string, label: string, value: string}>} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<dl class="wpitravel-facts">
	<?php foreach ( $args['facts'] as $wpitravel_fact ) : ?>
		<div class="wpitravel-facts__item wpitravel-facts__item--<?php echo esc_attr( $wpitravel_fact['key'] ); ?>">
			<dt><?php echo esc_html( $wpitravel_fact['label'] ); ?></dt>
			<dd><?php echo esc_html( $wpitravel_fact['value'] ); ?></dd>
		</div>
	<?php endforeach; ?>
</dl>
