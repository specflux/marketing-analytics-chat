<?php
/**
 * License Settings Tab
 *
 * @package Marketing_Analytics_MCP
 * @subpackage Admin\Views\Settings
 */

// Don't allow direct access.
if ( ! defined( 'WPINC' ) ) {
	die;
}

use Marketing_Analytics_MCP\Licensing\License_Manager;

$license_manager = new License_Manager();
$license_data    = $license_manager->get_license_data();
$is_active       = ! empty( $license_data['license_key'] ) && 'active' === ( $license_data['status'] ?? '' );
?>

<div class="license-settings">

	<?php if ( $is_active ) : ?>
		<!-- Active License Display -->
		<div class="mac-card" style="border-left: 4px solid var(--mac-success);">
			<h2>
				<span class="dashicons dashicons-yes-alt" style="color: var(--mac-success);"></span>
				<?php esc_html_e( 'Pro License Active', 'marketing-analytics-chat' ); ?>
			</h2>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'marketing-analytics-chat' ); ?></th>
					<td>
						<span class="status-badge connected">
							<?php esc_html_e( 'Active', 'marketing-analytics-chat' ); ?>
						</span>
					</td>
				</tr>
				<?php if ( ! empty( $license_data['customer_name'] ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Customer', 'marketing-analytics-chat' ); ?></th>
					<td><?php echo esc_html( $license_data['customer_name'] ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( ! empty( $license_data['customer_email'] ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'marketing-analytics-chat' ); ?></th>
					<td><?php echo esc_html( $license_data['customer_email'] ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( ! empty( $license_data['product_name'] ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plan', 'marketing-analytics-chat' ); ?></th>
					<td>
						<?php echo esc_html( $license_data['product_name'] ); ?>
						<?php if ( ! empty( $license_data['variant_name'] ) ) : ?>
							&mdash; <?php echo esc_html( $license_data['variant_name'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( ! empty( $license_data['activated_at'] ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Activated', 'marketing-analytics-chat' ); ?></th>
					<td><?php echo esc_html( $license_data['activated_at'] ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'License Key', 'marketing-analytics-chat' ); ?></th>
					<td>
						<code><?php echo esc_html( substr( $license_data['license_key'], 0, 8 ) . '••••••••' . substr( $license_data['license_key'], -4 ) ); ?></code>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" class="button" id="mac-validate-license">
					<?php esc_html_e( 'Validate License', 'marketing-analytics-chat' ); ?>
				</button>
				<button type="button" class="button" id="mac-deactivate-license" style="color: var(--mac-error);">
					<?php esc_html_e( 'Deactivate License', 'marketing-analytics-chat' ); ?>
				</button>
			</p>
			<div id="mac-license-message" style="display: none;"></div>
		</div>

	<?php else : ?>
		<!-- License Activation Form -->
		<div class="mac-card">
			<h2><?php esc_html_e( 'Activate Pro License', 'marketing-analytics-chat' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Enter your license key to unlock pro features. You can find your license key in your purchase confirmation email.', 'marketing-analytics-chat' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="mac-license-key"><?php esc_html_e( 'License Key', 'marketing-analytics-chat' ); ?></label>
					</th>
					<td>
						<input type="text" id="mac-license-key" class="large-text"
							placeholder="<?php esc_attr_e( 'Enter your license key...', 'marketing-analytics-chat' ); ?>" />
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" class="button button-primary" id="mac-activate-license">
					<?php esc_html_e( 'Activate License', 'marketing-analytics-chat' ); ?>
				</button>
			</p>
			<div id="mac-license-message" style="display: none;"></div>
		</div>

		<!-- Pro Features Upsell -->
		<div class="mac-card" style="margin-top: var(--mac-space-lg);">
			<h2><?php esc_html_e( 'Pro Features', 'marketing-analytics-chat' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upgrade to Pro to unlock powerful analytics features:', 'marketing-analytics-chat' ); ?>
			</p>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--mac-space-base); margin-top: var(--mac-space-base);">
				<div class="mac-card" style="background: var(--mac-surface-alt);">
					<h3>
						<span class="dashicons dashicons-chart-area" style="color: var(--mac-primary);"></span>
						<?php esc_html_e( 'AI Forecasting', 'marketing-analytics-chat' ); ?>
					</h3>
					<p><?php esc_html_e( 'Predict future traffic, conversions, and revenue trends using AI-powered forecasting models.', 'marketing-analytics-chat' ); ?></p>
				</div>
				<div class="mac-card" style="background: var(--mac-surface-alt);">
					<h3>
						<span class="dashicons dashicons-store" style="color: var(--mac-primary);"></span>
						<?php esc_html_e( 'Advanced E-commerce', 'marketing-analytics-chat' ); ?>
					</h3>
					<p><?php esc_html_e( 'Deep WooCommerce analytics with cohort analysis, LTV calculations, and purchase funnel insights.', 'marketing-analytics-chat' ); ?></p>
				</div>
				<div class="mac-card" style="background: var(--mac-surface-alt);">
					<h3>
						<span class="dashicons dashicons-groups" style="color: var(--mac-primary);"></span>
						<?php esc_html_e( 'Agency Features', 'marketing-analytics-chat' ); ?>
					</h3>
					<p><?php esc_html_e( 'White-label reports, PDF export, and multi-client dashboard for marketing agencies.', 'marketing-analytics-chat' ); ?></p>
				</div>
			</div>

			<p style="margin-top: var(--mac-space-lg);">
				<a href="https://jetwpsites.com/marketing-analytics-pro/" target="_blank" class="button button-primary button-hero">
					<?php esc_html_e( 'Get Pro License', 'marketing-analytics-chat' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
	var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var nonce = '<?php echo esc_js( wp_create_nonce( 'marketing-analytics-chat-admin' ) ); ?>';

	function showMessage(type, message) {
		var $msg = $('#mac-license-message');
		$msg.removeClass('notice-success notice-error notice-warning')
			.addClass('notice notice-' + type + ' is-dismissible')
			.html('<p>' + message + '</p>')
			.slideDown(300);
	}

	// Activate license.
	$('#mac-activate-license').on('click', function() {
		var $btn = $(this);
		var key = $('#mac-license-key').val().trim();

		if (!key) {
			showMessage('error', '<?php echo esc_js( __( 'Please enter a license key.', 'marketing-analytics-chat' ) ); ?>');
			return;
		}

		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Activating...', 'marketing-analytics-chat' ) ); ?>');

		$.post(ajaxUrl, {
			action: 'marketing_analytics_mcp_activate_license',
			nonce: nonce,
			license_key: key
		}, function(response) {
			if (response.success) {
				showMessage('success', response.data.message);
				setTimeout(function() {
					location.reload();
				}, 1500);
			} else {
				showMessage('error', response.data.message || '<?php echo esc_js( __( 'Activation failed.', 'marketing-analytics-chat' ) ); ?>');
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate License', 'marketing-analytics-chat' ) ); ?>');
			}
		}).fail(function() {
			showMessage('error', '<?php echo esc_js( __( 'Network error. Please try again.', 'marketing-analytics-chat' ) ); ?>');
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Activate License', 'marketing-analytics-chat' ) ); ?>');
		});
	});

	// Validate license.
	$('#mac-validate-license').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Validating...', 'marketing-analytics-chat' ) ); ?>');

		$.post(ajaxUrl, {
			action: 'marketing_analytics_mcp_validate_license',
			nonce: nonce
		}, function(response) {
			if (response.success) {
				showMessage('success', response.data.message);
			} else {
				showMessage('error', response.data.message || '<?php echo esc_js( __( 'Validation failed.', 'marketing-analytics-chat' ) ); ?>');
			}
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Validate License', 'marketing-analytics-chat' ) ); ?>');
		}).fail(function() {
			showMessage('error', '<?php echo esc_js( __( 'Network error. Please try again.', 'marketing-analytics-chat' ) ); ?>');
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Validate License', 'marketing-analytics-chat' ) ); ?>');
		});
	});

	// Deactivate license.
	$('#mac-deactivate-license').on('click', function() {
		if (!confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate your license? Pro features will be disabled.', 'marketing-analytics-chat' ) ); ?>')) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Deactivating...', 'marketing-analytics-chat' ) ); ?>');

		$.post(ajaxUrl, {
			action: 'marketing_analytics_mcp_deactivate_license',
			nonce: nonce
		}, function(response) {
			if (response.success) {
				showMessage('success', response.data.message);
				setTimeout(function() {
					location.reload();
				}, 1500);
			} else {
				showMessage('error', response.data.message || '<?php echo esc_js( __( 'Deactivation failed.', 'marketing-analytics-chat' ) ); ?>');
				$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Deactivate License', 'marketing-analytics-chat' ) ); ?>');
			}
		}).fail(function() {
			showMessage('error', '<?php echo esc_js( __( 'Network error. Please try again.', 'marketing-analytics-chat' ) ); ?>');
			$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Deactivate License', 'marketing-analytics-chat' ) ); ?>');
		});
	});
});
</script>
