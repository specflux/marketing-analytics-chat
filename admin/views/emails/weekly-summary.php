<?php
/**
 * Weekly summary email template
 *
 * Table layout with inline CSS only, so it renders in Gmail, Outlook and Apple
 * Mail. Receives a single $vars array from Weekly_Summary_Email::render().
 *
 * @package Specflux_Marketing_Analytics
 *
 * @var array $vars Template variables.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$specflux_mac_colors = $vars['colors'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $vars['site_name'] ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f2f5;">
<tr>
<td align="center" style="padding:24px 12px;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
		<tr>
			<td style="padding:0 4px 16px;">
				<div style="font-size:13px;color:<?php echo esc_attr( $specflux_mac_colors['muted'] ); ?>;"><?php echo esc_html( $vars['site_name'] ); ?></div>
				<div style="font-size:24px;line-height:32px;font-weight:700;color:<?php echo esc_attr( $specflux_mac_colors['text'] ); ?>;"><?php esc_html_e( 'Your weekly marketing summary', 'specflux-marketing-analytics-chat' ); ?></div>
				<div style="font-size:14px;color:<?php echo esc_attr( $specflux_mac_colors['muted'] ); ?>;"><?php echo esc_html( $vars['range_label'] ); ?></div>
			</td>
		</tr>
		<?php foreach ( $vars['sections'] as $specflux_mac_section ) : ?>
		<tr>
			<td style="padding:0 0 16px;">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border:1px solid <?php echo esc_attr( $specflux_mac_colors['border'] ); ?>;border-radius:8px;">
					<tr>
						<td style="padding:20px;">
							<div style="margin:0 0 8px;font-size:16px;line-height:22px;font-weight:700;color:<?php echo esc_attr( $specflux_mac_colors['text'] ); ?>;"><?php echo esc_html( $specflux_mac_section['title'] ); ?></div>
							<?php echo $specflux_mac_section['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Section HTML is escaped when built. ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
		<?php endforeach; ?>
		<tr>
			<td align="center" style="padding:8px 0 24px;">
				<a href="<?php echo esc_url( $vars['ai_url'] ); ?>" style="display:inline-block;padding:12px 24px;background-color:<?php echo esc_attr( $specflux_mac_colors['accent'] ); ?>;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:6px;"><?php esc_html_e( 'Ask the AI about this week', 'specflux-marketing-analytics-chat' ); ?></a>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:0 12px;font-size:12px;line-height:18px;color:<?php echo esc_attr( $specflux_mac_colors['muted'] ); ?>;">
				<?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( "You're receiving this because weekly summaries are on for %s.", 'specflux-marketing-analytics-chat' ), $vars['site_name'] ) );
				?>
				<a href="<?php echo esc_url( $vars['settings_url'] ); ?>" style="color:<?php echo esc_attr( $specflux_mac_colors['muted'] ); ?>;text-decoration:underline;"><?php esc_html_e( 'Change email settings', 'specflux-marketing-analytics-chat' ); ?></a>
			</td>
		</tr>
	</table>
</td>
</tr>
</table>
</body>
</html>
