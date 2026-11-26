<?php
/**
 * Email layout.
 *
 * Override by copying to yourtheme/wpitravel/emails/layout.php.
 *
 * Available: $heading (plain text), $body (HTML), $footer (HTML), $site_name.
 *
 * @package WPInterface\Travel
 *
 * @var string $heading
 * @var string $body
 * @var string $footer
 * @var string $site_name
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php echo is_rtl() ? 'dir="rtl"' : ''; ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $heading ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
	<tr>
		<td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;">
				<tr>
					<td style="padding:24px 28px 8px;font-size:13px;color:#6b7280;"><?php echo esc_html( $site_name ); ?></td>
				</tr>
				<tr>
					<td style="padding:0 28px;"><h1 style="font-size:20px;line-height:1.3;margin:8px 0 16px;color:#111827;"><?php echo esc_html( $heading ); ?></h1></td>
				</tr>
				<tr>
					<td style="padding:0 28px 24px;"><?php echo wp_kses_post( $body ); ?></td>
				</tr>
			</table>
			<?php if ( '' !== $footer ) : ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
					<tr>
						<td style="padding:16px 28px;font-size:12px;color:#6b7280;"><?php echo wp_kses_post( $footer ); ?></td>
					</tr>
				</table>
			<?php endif; ?>
		</td>
	</tr>
</table>
</body>
</html>
