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

use Specflux_Marketing_Analytics\Admin\Form_Ui;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

$generic_slug     = $panel_definition['slug'];
$generic_auth     = $panel_definition['auth'];
$generic_saved    = $credential_manager->get_credentials( $generic_slug );
$generic_saved    = is_array( $generic_saved ) ? $generic_saved : array();
$generic_required = array_diff( $panel_definition['fields'], $panel_definition['optional_fields'] );

$generic_failure = '';
if ( 'api_key' === $generic_auth && ! empty( $generic_required ) ) {
	$generic_connected = $credential_manager->has_credentials( $generic_slug );
	$generic_last      = Connection_Tester::get_last_result( $generic_slug );
	if ( $generic_connected && null !== $generic_last && empty( $generic_last['success'] ) ) {
		$generic_connected = false;
		$generic_failure   = (string) $generic_last['message'];
	}
} else {
	$generic_tester    = new Connection_Tester();
	$generic_result    = $generic_tester->test_connection( $generic_slug );
	$generic_connected = ! empty( $generic_result['success'] );
}

if ( $generic_connected ) {
	$generic_state = Form_Ui::connection_state( true );
} elseif ( '' !== $generic_failure ) {
	$generic_state = array(
		'state'   => 'error',
		'label'   => __( 'Connection Failed', 'specflux-marketing-analytics-chat' ),
		'message' => $generic_failure,
	);
} else {
	$generic_state = Form_Ui::connection_state( false );
}
?>
<section class="aui-card smac-connection-card smac-generic-panel" data-platform="<?php echo esc_attr( $generic_slug ); ?>">
	<div class="aui-card-h">
		<span class="aui-tile" aria-hidden="true"><?php echo Ui_Kit::icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
		<div>
			<h2 class="aui-card-t"><?php echo esc_html( $panel_definition['label'] ); ?></h2>
			<?php if ( '' !== $panel_definition['description'] ) : ?>
				<p class="aui-card-d"><?php echo esc_html( $panel_definition['description'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php echo Form_Ui::status_badge( $generic_state['state'], $generic_state['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::status_badge(). ?>
	</div>

	<?php if ( 'api_key' === $generic_auth ) : ?>
		<div class="aui-card-b smac-fields">
			<?php if ( '' !== $generic_state['message'] ) : ?>
				<?php echo Form_Ui::notice( 'error', $generic_state['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Form_Ui::notice(). ?>
			<?php endif; ?>

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
				<div class="aui-field">
					<label class="aui-label" for="<?php echo esc_attr( $generic_input_id ); ?>"><?php echo esc_html( Platform_Registry::field_label( $panel_definition, $generic_field ) ); ?></label>
					<input
						type="<?php echo esc_attr( $generic_secret ? 'password' : ( '_url' === substr( $generic_field, -4 ) ? 'url' : 'text' ) ); ?>"
						id="<?php echo esc_attr( $generic_input_id ); ?>"
						class="aui-input smac-generic-field"
						data-field="<?php echo esc_attr( $generic_field ); ?>"
						value="<?php echo esc_attr( $generic_value ); ?>"
						autocomplete="off"
					/>
					<?php if ( $generic_optional ) : ?>
						<p class="aui-help"><?php esc_html_e( 'Optional.', 'specflux-marketing-analytics-chat' ); ?></p>
					<?php elseif ( $generic_secret && $generic_has ) : ?>
						<p class="aui-help"><?php esc_html_e( 'Securely stored and encrypted. Leave unchanged to keep the current value.', 'specflux-marketing-analytics-chat' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( '' !== $panel_definition['help_url'] ) : ?>
				<p class="aui-help">
					<a href="<?php echo esc_url( $panel_definition['help_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Where do I find these credentials?', 'specflux-marketing-analytics-chat' ); ?></a>
				</p>
			<?php endif; ?>
		</div>

		<div class="aui-card-f">
			<button type="button" class="aui-btn aui-btn--primary smac-generic-save">
				<?php esc_html_e( 'Save & Test', 'specflux-marketing-analytics-chat' ); ?>
			</button>
			<span class="smac-generic-status aui-help" role="status" aria-live="polite"></span>
			<?php if ( ! empty( $generic_saved ) ) : ?>
				<button type="button" class="aui-btn aui-btn--danger-outline aui-end" data-aui-open="smac-disconnect-<?php echo esc_attr( $generic_slug ); ?>">
					<?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $generic_saved ) ) : ?>
			<div class="aui-backdrop" id="smac-disconnect-<?php echo esc_attr( $generic_slug ); ?>" hidden>
				<div class="aui-dialog" role="dialog" aria-modal="true" aria-labelledby="smac-disconnect-<?php echo esc_attr( $generic_slug ); ?>-title">
					<h2 id="smac-disconnect-<?php echo esc_attr( $generic_slug ); ?>-title"><?php esc_html_e( 'Disconnect this platform?', 'specflux-marketing-analytics-chat' ); ?></h2>
					<p><?php esc_html_e( 'Its saved credentials are removed from this site. You can connect it again at any time.', 'specflux-marketing-analytics-chat' ); ?></p>
					<div class="aui-dialog-f">
						<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Keep connected', 'specflux-marketing-analytics-chat' ); ?></button>
						<button type="button" class="aui-btn aui-btn--danger smac-generic-disconnect" data-aui-close><?php esc_html_e( 'Disconnect', 'specflux-marketing-analytics-chat' ); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</section>
