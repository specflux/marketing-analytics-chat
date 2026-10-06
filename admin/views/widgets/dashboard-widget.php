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

use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Reports\Weekly_Prompt_Builder;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Scheduler;

$credential_manager = new Credential_Manager();
$platforms          = array( 'clarity', 'ga4', 'gsc' );
$recent_anomalies   = get_option( 'specflux_mac_recent_anomalies', array() );
$connected          = array();
foreach ( $platforms as $platform_key ) {
	if ( $credential_manager->has_credentials( $platform_key ) ) {
		$connected[] = $platform_key;
	}
}
$has_connection = ! empty( $connected );
$headline_items = $has_connection ? Widget_Headline::cached() : null;
?>
<div class="smac-widget">
	<?php if ( ! $has_connection ) : ?>
		<div class="smac-widget-section">
			<?php echo Widget_Headline::render_empty_state(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer. ?>
		</div>
	<?php else : ?>
		<!-- This week (loaded automatically when nothing is cached) -->
		<div class="smac-widget-section smac-widget-headline"<?php echo null === $headline_items ? ' data-autoload="1"' : ''; ?>>
			<?php
			// Never call the APIs while the dashboard renders: show a loading state and let the script fetch.
			echo null === $headline_items ? Widget_Headline::render_loading() : Widget_Headline::render( $headline_items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer.
			?>
		</div>

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
	<?php endif; ?>

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

	<?php if ( $has_connection ) : ?>
	<!-- Quick Action -->
	<div class="smac-widget-section" style="margin-top: 15px; text-align: center;">
		<a href="<?php echo esc_url( Weekly_Prompt_Builder::assistant_url( Weekly_Prompt_Builder::from_headline( $headline_items ) ) ); ?>" class="button button-primary" style="width: 100%; text-align: center;">
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
	<?php endif; ?>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<?php echo Widget_Headline::render_email_link( ( new Weekly_Summary_Scheduler() )->is_enabled() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the renderer. ?>
	<?php endif; ?>
</div>
