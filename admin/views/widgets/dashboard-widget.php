<?php
/**
 * Dashboard Widget View
 *
 * Displays a summary of marketing analytics on the WordPress dashboard.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

$credential_manager = new Credential_Manager();
$platforms          = array( 'clarity', 'ga4', 'gsc' );
$recent_anomalies   = get_option( 'specflux_mac_recent_anomalies', array() );
$widget_data        = get_transient( 'specflux_mac_widget_data' );
?>
<div class="smac-widget">
	<!-- Platform Status -->
	<div class="smac-widget-section">
		<h4 class="smac-widget-heading">
			<?php esc_html_e( 'Platform Status', 'specflux-marketing-analytics-chat' ); ?>
		</h4>
		<div class="smac-widget-platforms">
			<?php foreach ( $platforms as $platform_key ) : ?>
				<?php $is_connected = $credential_manager->has_credentials( $platform_key ); ?>
				<span class="smac-widget-badge <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
					<?php echo esc_html( strtoupper( $platform_key ) ); ?>
				</span>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Recent Anomalies -->
	<?php if ( ! empty( $recent_anomalies ) ) : ?>
		<div class="smac-widget-section">
			<h4 class="smac-widget-heading">
				<?php esc_html_e( 'Recent Anomalies', 'specflux-marketing-analytics-chat' ); ?>
			</h4>
			<ul style="margin: 0; padding: 0; list-style: none;">
				<?php
				$display_anomalies = array_slice( $recent_anomalies, 0, 3 );
				foreach ( $display_anomalies as $anomaly ) :
					$severity_class = isset( $anomaly['severity'] ) ? $anomaly['severity'] : 'low';
					?>
					<li style="padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px;">
						<span class="smac-widget-severity <?php echo esc_attr( $severity_class ); ?>">
							<?php echo esc_html( ucfirst( $severity_class ) ); ?>
						</span>
						<?php
						printf(
							/* translators: 1: anomaly type (spike/drop), 2: metric name, 3: platform name */
							esc_html__( '%1$s in %2$s (%3$s)', 'specflux-marketing-analytics-chat' ),
							esc_html( ucfirst( isset( $anomaly['type'] ) ? $anomaly['type'] : '' ) ),
							esc_html( isset( $anomaly['metric'] ) ? $anomaly['metric'] : '' ),
							esc_html( isset( $anomaly['platform'] ) ? strtoupper( $anomaly['platform'] ) : '' )
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<!-- Quick Action -->
	<div class="smac-widget-section" style="margin-top: 15px; text-align: center;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-marketing-analytics-chat-ai-assistant' ) ); ?>" class="button button-primary" style="width: 100%; text-align: center;">
			<?php esc_html_e( 'Open AI Assistant', 'specflux-marketing-analytics-chat' ); ?>
		</a>
	</div>

	<!-- Refresh Button -->
	<div class="smac-widget-footer">
		<button type="button" class="button button-small smac-refresh-widget">
			<span class="dashicons dashicons-update" style="font-size: 14px; margin-top: 3px;"></span>
			<?php esc_html_e( 'Refresh', 'specflux-marketing-analytics-chat' ); ?>
		</button>
	</div>
</div>
