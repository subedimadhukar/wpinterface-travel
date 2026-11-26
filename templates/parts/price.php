<?php
/**
 * Trip price ("From $1,100").
 *
 * Override: yourtheme/wpitravel/parts/price.php
 *
 * @package WPInterface\Travel
 *
 * @var array{price: array{price: string, regular: string, sale: bool, amount?: string, regular_amount?: string}} $args
 */

defined( 'ABSPATH' ) || exit;

$wpitravel_price = $args['price'];
?>
<p class="wpitravel-price">
	<span class="wpitravel-price__label"><?php esc_html_e( 'From', 'wpinterface-travel' ); ?></span>
	<?php if ( $wpitravel_price['sale'] ) : ?>
		<del class="wpitravel-price__regular" data-wpitravel-amount="<?php echo esc_attr( (string) ( $wpitravel_price['regular_amount'] ?? '' ) ); ?>"><?php echo esc_html( $wpitravel_price['regular'] ); ?></del>
	<?php endif; ?>
	<strong class="wpitravel-price__amount" data-wpitravel-amount="<?php echo esc_attr( (string) ( $wpitravel_price['amount'] ?? '' ) ); ?>"><?php echo esc_html( $wpitravel_price['price'] ); ?></strong>
	<span class="wpitravel-price__unit"><?php esc_html_e( 'per person', 'wpinterface-travel' ); ?></span>
</p>
