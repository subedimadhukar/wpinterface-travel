<?php
/**
 * Trip map. OpenStreetMap is loaded only when the visitor clicks, so no
 * data goes to a third party before they choose to see the map.
 *
 * Override: yourtheme/wpitravel/parts/map.php
 *
 * @package WPInterface\Travel
 *
 * @var array{map: array{lat: float, lng: float, zoom: int, address: string}, embed: string, link: string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="wpitravel-section wpitravel-map">
	<h2 class="wpitravel-section__title"><?php esc_html_e( 'Map', 'wpinterface-travel' ); ?></h2>
	<?php if ( '' !== $args['map']['address'] ) : ?>
		<p class="wpitravel-map__address"><?php echo esc_html( $args['map']['address'] ); ?></p>
	<?php endif; ?>
	<div class="wpitravel-map__frame" data-wpitravel-map="<?php echo esc_url( $args['embed'] ); ?>">
		<button type="button" class="wpitravel-map__load">
			<?php esc_html_e( 'Show map', 'wpinterface-travel' ); ?>
			<small><?php esc_html_e( 'Loads OpenStreetMap', 'wpinterface-travel' ); ?></small>
		</button>
	</div>
	<p><a href="<?php echo esc_url( $args['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in OpenStreetMap', 'wpinterface-travel' ); ?></a></p>
</section>
