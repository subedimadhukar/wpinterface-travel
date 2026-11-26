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
<style>
	* { box-sizing: border-box; }
	body { margin: 0; background: #f4f4f5; color: #111827; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", Helvetica, Arial, sans-serif; }
	.sheet { max-width: 780px; margin: 24px auto; background: #fff; padding: 40px; border-radius: 8px; }
	header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid #111827; padding-bottom: 16px; }
	header img { max-height: 60px; width: auto; }
	h1 { font-size: 26px; margin: 0 0 4px; }
	h2 { font-size: 15px; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; margin: 28px 0 8px; }
	.number { font-size: 22px; font-weight: 700; text-align: end; }
	.status { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 13px; font-weight: 600; }
	.status.is-pending { background: #fef3c7; color: #92400e; }
	dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 24px; margin: 0; }
	dt { color: #4b5563; }
	dd { margin: 0; }
	table { width: 100%; border-collapse: collapse; }
	td { padding: 6px 0; border-bottom: 1px solid #e5e7eb; }
	td.amount { text-align: end; white-space: nowrap; }
	tr.total td { font-weight: 700; border-bottom: 0; border-top: 2px solid #111827; }
	.notice { margin-top: 24px; padding: 12px 16px; background: #fef3c7; border-radius: 6px; }
	.actions { max-width: 780px; margin: 0 auto 24px; text-align: center; }
	.actions button { font: inherit; padding: 10px 20px; border-radius: 6px; border: 0; background: #111827; color: #fff; cursor: pointer; }
	@media print {
		body { background: #fff; }
		.sheet { margin: 0; padding: 0; max-width: none; border-radius: 0; }
		.actions { display: none; }
	}
</style>
</head>
<body>
<main class="sheet">
	<header>
		<div>
			<?php echo $wpitravel_logo ? wp_kses_post( $wpitravel_logo ) : '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'; ?>
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
<p class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'wpinterface-travel' ); ?></button></p>
</body>
</html>
