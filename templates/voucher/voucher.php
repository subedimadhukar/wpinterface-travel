<?php
/**
 * Printable voucher.
 *
 * Override by copying to yourtheme/wpitravel/voucher.php.
 *
 * Available: $view (BookingView::for_customer()), $meeting_point, $currency.
 *
 * @package WPInterface\Travel
 *
 * @var array<string, mixed> $view
 * @var string               $meeting_point
 * @var array<string, mixed> $currency
 */

use WPInterface\Travel\Frontend\Templates;
use WPInterface\Travel\Support\Money;

defined( 'ABSPATH' ) || exit;

$wpitravel_money = static fn( string $amount ): string => Money::format( $amount, $currency );
$wpitravel_date  = static fn( string $date ): string => '' !== $date ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $date . ' 12:00:00 UTC' ), new DateTimeZone( 'UTC' ) ) : '';
$wpitravel_logo  = get_custom_logo();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>
<?php
/* translators: %s: booking number. */
echo esc_html( sprintf( __( 'Voucher %s', 'wpinterface-travel' ), $view['number'] ) );
?>
</title>
<?php
wp_register_style( 'wpitravel-voucher', WPITRAVEL_URL . 'assets/css/voucher.css', array(), WPITRAVEL_VERSION );
wp_print_styles( 'wpitravel-voucher' );
?>
</head>
<body>
<main class="sheet">
	<header>
		<div>
			<?php echo $wpitravel_logo ? wp_kses( $wpitravel_logo, Templates::allowed_html() ) : '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'; ?>
			<h1><?php esc_html_e( 'Booking voucher', 'wpinterface-travel' ); ?></h1>
		</div>
		<div>
			<div class="number"><?php echo esc_html( $view['number'] ); ?></div>
			<span class="status <?php echo 'pending' === $view['status'] ? 'is-pending' : ''; ?>"><?php echo esc_html( $view['status_label'] ); ?></span>
		</div>
	</header>

	<h2><?php esc_html_e( 'Trip', 'wpinterface-travel' ); ?></h2>
	<dl>
		<dt><?php esc_html_e( 'Trip', 'wpinterface-travel' ); ?></dt>
		<dd><?php echo esc_html( $view['trip']['title'] ); ?></dd>
		<dt><?php esc_html_e( 'Date', 'wpinterface-travel' ); ?></dt>
		<dd>
			<?php
			echo esc_html( $wpitravel_date( $view['date'] ) . ( '' !== $view['time'] ? ' · ' . $view['time'] : '' ) );
			if ( $view['end_date'] !== $view['date'] ) {
				echo esc_html( ' – ' . $wpitravel_date( $view['end_date'] ) );
			}
			?>
		</dd>
		<?php if ( '' !== $meeting_point ) : ?>
			<dt><?php esc_html_e( 'Meeting point', 'wpinterface-travel' ); ?></dt>
			<dd><?php echo esc_html( $meeting_point ); ?></dd>
		<?php endif; ?>
		<?php if ( ! empty( $view['pickup'] ) ) : ?>
			<dt><?php esc_html_e( 'Pickup', 'wpinterface-travel' ); ?></dt>
			<dd>
				<?php echo esc_html( $view['pickup']['name'] . ( '' !== $view['pickup']['time'] ? ', ' . $view['pickup']['time'] : '' ) ); ?>
				<?php if ( '' !== $view['pickup']['note'] ) : ?>
					<br><?php echo esc_html( $view['pickup']['note'] ); ?>
				<?php endif; ?>
			</dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Travelers', 'wpinterface-travel' ); ?></dt>
		<dd><?php echo esc_html( (string) $view['pax'] ); ?></dd>
	</dl>

	<h2><?php esc_html_e( 'Lead traveler', 'wpinterface-travel' ); ?></h2>
	<dl>
		<dt><?php esc_html_e( 'Name', 'wpinterface-travel' ); ?></dt>
		<dd><?php echo esc_html( trim( $view['contact']['first_name'] . ' ' . $view['contact']['last_name'] ) ); ?></dd>
		<?php
		$wpitravel_others = array_filter( $view['travelers'], static fn( $traveler ) => ! $traveler['lead'] );
		if ( $wpitravel_others ) :
			?>
			<dt><?php esc_html_e( 'Also traveling', 'wpinterface-travel' ); ?></dt>
			<dd><?php echo esc_html( implode( ', ', array_map( static fn( $traveler ) => trim( $traveler['first_name'] . ' ' . $traveler['last_name'] ), $wpitravel_others ) ) ); ?></dd>
		<?php endif; ?>
	</dl>

	<h2><?php esc_html_e( 'Price', 'wpinterface-travel' ); ?></h2>
	<table>
		<?php foreach ( $view['items'] as $wpitravel_item ) : ?>
			<tr>
				<td><?php echo esc_html( 'pax' === $wpitravel_item['type'] ? $wpitravel_item['label'] . ' × ' . $wpitravel_item['quantity'] : $wpitravel_item['label'] ); ?></td>
				<td class="amount"><?php echo esc_html( $wpitravel_item['included'] ? '(' . $wpitravel_money( $wpitravel_item['total'] ) . ')' : $wpitravel_money( $wpitravel_item['total'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr class="total">
			<td><?php esc_html_e( 'Total', 'wpinterface-travel' ); ?></td>
			<td class="amount"><?php echo esc_html( $wpitravel_money( $view['total'] ) ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Paid', 'wpinterface-travel' ); ?></td>
			<td class="amount"><?php echo esc_html( $wpitravel_money( $view['amount_paid'] ) ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Balance', 'wpinterface-travel' ); ?></td>
			<td class="amount"><?php echo esc_html( $wpitravel_money( $view['balance'] ) ); ?></td>
		</tr>
	</table>

	<?php if ( 'pending' === $view['status'] ) : ?>
		<p class="notice"><?php esc_html_e( 'This booking is not confirmed yet. Please bring the confirmed voucher on the day.', 'wpinterface-travel' ); ?></p>
	<?php endif; ?>
</main>
<p class="actions"><button type="button" id="wpitravel-print"><?php esc_html_e( 'Print or save as PDF', 'wpinterface-travel' ); ?></button></p>
<?php wp_print_inline_script_tag( "document.getElementById('wpitravel-print').addEventListener('click',function(){window.print();});" ); ?>
</body>
</html>
