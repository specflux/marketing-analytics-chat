<?php
/**
 * Shared app header partial
 *
 * Expects $specflux_mac_current (page slug) from Ui_Kit::render_header().
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Connection_Promoter;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;

$specflux_mac_promoter  = new Connection_Promoter();
$specflux_mac_connected = count( $specflux_mac_promoter->get_connected_platforms() );
$specflux_mac_total     = $specflux_mac_promoter->get_platform_total();
$specflux_mac_all_ok    = $specflux_mac_total > 0 && $specflux_mac_connected >= $specflux_mac_total;
?>
<?php echo Ui_Kit::sprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG sprite shipped inside the plugin. ?>
<header class="aui-header">
	<div class="aui-brand">
		<span class="aui-mark"><?php echo Ui_Kit::icon( 'chart', 'aui-i--lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
		<h1><?php esc_html_e( 'Marketing Analytics Chat', 'specflux-marketing-analytics-chat' ); ?></h1>
	</div>
	<nav class="aui-nav" aria-label="<?php echo esc_attr__( 'Marketing Analytics Chat', 'specflux-marketing-analytics-chat' ); ?>">
		<?php foreach ( Ui_Kit::pages() as $specflux_mac_slug => $specflux_mac_label ) : ?>
			<?php
			// Settings requires full admin capability; do not link a page the user cannot open.
			if ( 'specflux-mac-settings' === $specflux_mac_slug && ! current_user_can( 'manage_options' ) ) {
				continue;
			}
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $specflux_mac_slug ) ); ?>"<?php echo $specflux_mac_current === $specflux_mac_slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $specflux_mac_label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="aui-header-end">
		<span class="aui-pill<?php echo $specflux_mac_all_ok ? ' aui-pill--ok' : ''; ?>">
			<?php
			/* translators: 1: number of connected platforms, 2: total number of platforms. */
			echo esc_html( sprintf( __( '%1$d of %2$d connected', 'specflux-marketing-analytics-chat' ), $specflux_mac_connected, $specflux_mac_total ) );
			?>
		</span>
		<span class="aui-chip"><?php echo esc_html( 'v' . SPECFLUX_MAC_VERSION ); ?></span>
	</div>
</header>
<hr class="wp-header-end">
