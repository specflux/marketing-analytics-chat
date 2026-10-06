<?php
/**
 * Connections page panel: default panel for registry-defined platforms
 *
 * Used when a registered platform has no panel file and nothing is hooked to
 * `specflux_mac_render_connection_panel_{slug}`. Expects $panel_definition and
 * $credential_manager from connections.php.
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

$generic_slug     = $panel_definition['slug'];
$generic_auth     = $panel_definition['auth'];
$generic_saved    = $credential_manager->get_credentials( $generic_slug );
$generic_saved    = is_array( $generic_saved ) ? $generic_saved : array();
$generic_required = array_diff( $panel_definition['fields'], $panel_definition['optional_fields'] );

if ( 'api_key' === $generic_auth && ! empty( $generic_required ) ) {
	$generic_connected = $credential_manager->has_credentials( $generic_slug );
} else {
	$generic_tester    = new Connection_Tester();
	$generic_result    = $generic_tester->test_connection( $generic_slug );
	$generic_connected = ! empty( $generic_result['success'] );
}
?>
<div class="connection-panel smac-generic-panel" data-platform="<?php echo esc_attr( $generic_slug ); ?>">
	<h3>
		<?php echo esc_html( $panel_definition['label'] ); ?>
		<?php esc_html_e( 'Configuration', 'specflux-marketing-analytics-chat' ); ?>
		<?php if ( $generic_connected ) : ?>
			<span class="status-badge heading-connected">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Connected', 'specflux-marketing-analytics-chat' ); ?>
			</span>
		<?php else : ?>
			<span class="status-badge heading-disconnected">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Not Connected', 'specflux-marketing-analytics-chat' ); ?>
			</span>
		<?php endif; ?>
	</h3>

	<?php if ( '' !== $panel_definition['description'] ) : ?>
		<p><?php echo esc_html( $panel_definition['description'] ); ?></p>
	<?php endif; ?>

	<?php if ( 'api_key' === $generic_auth ) : ?>
		<table class="form-table">
			<?php foreach ( $panel_definition['fields'] as $generic_field ) : ?>
				<?php
				$generic_secret   = Platform_Registry::is_secret_field( $generic_field );
				$generic_has      = ! empty( $generic_saved[ $generic_field ] );
				$generic_optional = in_array( $generic_field, $panel_definition['optional_fields'], true );
				if ( $generic_secret ) {
					$generic_value = $generic_has ? Platform_Registry::SECRET_MASK : '';
				} else {
					$generic_value = $generic_has ? $generic_saved[ $generic_field ] : '';
				}
				$generic_input_id = 'smac_' . $generic_slug . '_' . $generic_field;
				?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $generic_input_id ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $generic_field ) ) ); ?></label>
					</th>
					<td>
						<input
							type="<?php echo esc_attr( $generic_secret ? 'password' : 'text' ); ?>"
							id="<?php echo esc_attr( $generic_input_id ); ?>"
							class="regular-text smac-generic-field"
							data-field="<?php echo esc_attr( $generic_field ); ?>"
							value="<?php echo esc_attr( $generic_value ); ?>"
							autocomplete="off"
						/>
						<?php if ( $generic_optional ) : ?>
							<p class="description"><?php esc_html_e( 'Optional.', 'specflux-marketing-analytics-chat' ); ?></p>
						<?php elseif ( $generic_secret && $generic_has ) : ?>
							<p class="description success">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Securely stored and encrypted. Leave unchanged to keep the current value.', 'specflux-marketing-analytics-chat' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php if ( '' !== $panel_definition['help_url'] ) : ?>
			<p class="description">
				<a href="<?php echo esc_url( $panel_definition['help_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Where do I find these credentials?', 'specflux-marketing-analytics-chat' ); ?></a>
			</p>
		<?php endif; ?>

		<p class="submit">
			<button type="button" class="button button-primary smac-generic-save">
				<?php esc_html_e( 'Save & Test', 'specflux-marketing-analytics-chat' ); ?>
			</button>
			<?php if ( ! empty( $generic_saved ) ) : ?>
				<button type="button" class="button button-secondary smac-generic-disconnect">
					<?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			<?php endif; ?>
			<span class="smac-generic-status" role="status" aria-live="polite"></span>
		</p>
	<?php endif; ?>
</div>
