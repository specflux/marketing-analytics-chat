<?php
/**
 * Custom Prompts Management View
 *
 * @package Specflux_Marketing_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Prompts\Prompt_Form_Input;
use Specflux_Marketing_Analytics\Prompts\Prompt_Manager;

$prompt_manager   = new Prompt_Manager();
$custom_prompts   = $prompt_manager->get_all_prompts();
$preset_templates = $prompt_manager->get_preset_templates();

// Messages for the notices at the top of the screen: array of array( type, text ).
$smac_notices = array();

// When a save fails, the form opens again with what the user typed.
$smac_form = array(
	'open'      => false,
	'action'    => 'create_prompt',
	'prompt_id' => '',
	'values'    => array(),
);

// Handle form submissions.
$prompts_nonce = isset( $_POST['specflux_mac_prompts_nonce'] )
	? sanitize_text_field( wp_unslash( $_POST['specflux_mac_prompts_nonce'] ) )
	: '';

if ( $prompts_nonce && wp_verify_nonce( $prompts_nonce, 'specflux_mac_prompts' ) ) {
	$prompt_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

	if ( $prompt_action ) {
		switch ( $prompt_action ) {
			case 'create_prompt':
			case 'update_prompt':
				$is_update = 'update_prompt' === $prompt_action;
				$prompt_id = $is_update && isset( $_POST['prompt_id'] ) ? sanitize_text_field( wp_unslash( $_POST['prompt_id'] ) ) : '';
				$parsed    = Prompt_Form_Input::parse( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every field is sanitized inside Prompt_Form_Input::parse().

				if ( $is_update && '' === $prompt_id ) {
					$smac_notices[] = array( 'error', __( 'Missing prompt ID.', 'specflux-marketing-analytics-chat' ) );
					break;
				}

				if ( null === $parsed['data'] ) {
					$smac_notices[] = array( 'error', $parsed['error'] );
				} else {
					$result = $is_update
						? $prompt_manager->update_prompt( $prompt_id, $parsed['data'] )
						: $prompt_manager->create_prompt( $parsed['data'] );

					if ( is_wp_error( $result ) ) {
						$smac_notices[] = array( 'error', $result->get_error_message() );
					} else {
						$smac_notices[] = array(
							'success',
							$is_update
								? __( 'Prompt updated successfully!', 'specflux-marketing-analytics-chat' )
								: __( 'Prompt created successfully!', 'specflux-marketing-analytics-chat' ),
						);
						$custom_prompts = $prompt_manager->get_all_prompts(); // Refresh list.
						break;
					}
				}

				// Failed: reopen the form with the typed values.
				$smac_form['open']      = true;
				$smac_form['action']    = $prompt_action;
				$smac_form['prompt_id'] = $prompt_id;
				foreach ( array( 'prompt_name', 'prompt_label', 'prompt_description', 'prompt_instructions', 'prompt_arguments' ) as $smac_field ) {
					$smac_form['values'][ $smac_field ] = isset( $_POST[ $smac_field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $smac_field ] ) ) : '';
				}
				break;

			case 'delete_prompt':
				$prompt_id = isset( $_POST['prompt_id'] ) ? sanitize_text_field( wp_unslash( $_POST['prompt_id'] ) ) : '';
				if ( '' === $prompt_id ) {
					$smac_notices[] = array( 'error', __( 'Missing prompt ID.', 'specflux-marketing-analytics-chat' ) );
					break;
				}
				if ( $prompt_manager->delete_prompt( $prompt_id ) ) {
					$smac_notices[] = array( 'success', __( 'Prompt deleted successfully!', 'specflux-marketing-analytics-chat' ) );
					$custom_prompts = $prompt_manager->get_all_prompts(); // Refresh list.
				} else {
					$smac_notices[] = array( 'error', __( 'Failed to delete prompt.', 'specflux-marketing-analytics-chat' ) );
				}
				break;

			case 'import_preset':
				$preset_key = isset( $_POST['preset_key'] ) ? sanitize_text_field( wp_unslash( $_POST['preset_key'] ) ) : '';
				if ( '' === $preset_key ) {
					$smac_notices[] = array( 'error', __( 'Missing preset key.', 'specflux-marketing-analytics-chat' ) );
					break;
				}
				$result = $prompt_manager->import_preset( $preset_key );

				if ( is_wp_error( $result ) ) {
					$smac_notices[] = array( 'error', $result->get_error_message() );
				} else {
					$smac_notices[] = array( 'success', __( 'Preset imported successfully!', 'specflux-marketing-analytics-chat' ) );
					$custom_prompts = $prompt_manager->get_all_prompts(); // Refresh list.
				}
				break;
		}
	}
}

$smac_nonce       = wp_create_nonce( 'specflux_mac_prompts' );
$smac_is_update   = 'update_prompt' === $smac_form['action'];
$smac_value       = static function ( $field ) use ( $smac_form ) {
	return isset( $smac_form['values'][ $field ] ) ? $smac_form['values'][ $field ] : '';
};
$smac_prompt_total = count( $custom_prompts );
?>

<div class="aui" data-brand="specflux">
<?php Ui_Kit::render_header( 'specflux-mac-prompts' ); ?>

<div class="wrap smac-catalog smac-prompts">
<div class="aui-layout">
	<div class="aui-stack">
		<p class="aui-muted smac-lead">
			<?php esc_html_e( 'Create custom prompts to guide AI assistants through complex marketing analytics workflows. Prompts can combine multiple tools and provide step-by-step instructions.', 'specflux-marketing-analytics-chat' ); ?>
		</p>

		<?php foreach ( $smac_notices as $smac_notice ) : ?>
			<div class="aui-notice aui-notice--<?php echo esc_attr( $smac_notice[0] ); ?>" role="<?php echo 'error' === $smac_notice[0] ? 'alert' : 'status'; ?>">
				<?php echo Ui_Kit::icon( 'error' === $smac_notice[0] ? 'warning' : 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
				<div><?php echo esc_html( $smac_notice[1] ); ?></div>
			</div>
		<?php endforeach; ?>

		<!-- Existing Prompts -->
		<section class="aui-card" aria-labelledby="smac-prompts-title">
			<div class="aui-card-h">
				<h2 class="aui-card-t" id="smac-prompts-title"><?php esc_html_e( 'Your Custom Prompts', 'specflux-marketing-analytics-chat' ); ?> <span class="aui-count"><?php echo esc_html( number_format_i18n( $smac_prompt_total ) ); ?></span></h2>
				<button type="button" class="aui-btn aui-btn--primary aui-btn--sm aui-end" data-aui-open="smac-prompt-form" data-smac-prompt="new"><?php esc_html_e( 'New prompt', 'specflux-marketing-analytics-chat' ); ?></button>
			</div>

			<?php if ( empty( $custom_prompts ) ) : ?>
				<div class="aui-empty">
					<span class="aui-tile"><?php echo Ui_Kit::icon( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
					<h3 class="aui-card-t"><?php esc_html_e( 'No custom prompts yet', 'specflux-marketing-analytics-chat' ); ?></h3>
					<p><?php esc_html_e( 'Create your own, or import a preset template below.', 'specflux-marketing-analytics-chat' ); ?></p>
					<button type="button" class="aui-btn aui-btn--primary" data-aui-open="smac-prompt-form" data-smac-prompt="new"><?php esc_html_e( 'New prompt', 'specflux-marketing-analytics-chat' ); ?></button>
				</div>
			<?php else : ?>
				<div class="aui-table-wrap">
					<table class="aui-table smac-prompt-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'specflux-marketing-analytics-chat' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Description', 'specflux-marketing-analytics-chat' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Arguments', 'specflux-marketing-analytics-chat' ); ?></th>
								<th scope="col" class="aui-r"><?php esc_html_e( 'Actions', 'specflux-marketing-analytics-chat' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $custom_prompts as $prompt_id => $prompt ) : ?>
								<?php $smac_label = isset( $prompt['label'] ) ? $prompt['label'] : ( isset( $prompt['name'] ) ? $prompt['name'] : $prompt_id ); ?>
								<tr data-prompt-id="<?php echo esc_attr( $prompt_id ); ?>">
									<td>
										<strong><?php echo esc_html( $smac_label ); ?></strong>
										<div><code class="aui-code"><?php echo esc_html( $prompt_id ); ?></code></div>
									</td>
									<td class="smac-prompt-desc"><?php echo esc_html( isset( $prompt['description'] ) ? $prompt['description'] : '' ); ?></td>
									<td>
										<?php if ( ! empty( $prompt['arguments'] ) ) : ?>
											<span class="aui-badge">
												<?php
												/* translators: %d: number of arguments */
												echo esc_html( sprintf( _n( '%d argument', '%d arguments', count( $prompt['arguments'] ), 'specflux-marketing-analytics-chat' ), count( $prompt['arguments'] ) ) );
												?>
											</span>
										<?php else : ?>
											<span class="aui-muted"><?php esc_html_e( 'None', 'specflux-marketing-analytics-chat' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<div class="aui-actions">
											<button type="button" class="aui-btn aui-btn--ghost aui-btn--sm view-prompt" data-aui-open="smac-prompt-view" data-smac-prompt="view" data-prompt-id="<?php echo esc_attr( $prompt_id ); ?>">
												<?php esc_html_e( 'View', 'specflux-marketing-analytics-chat' ); ?><span class="aui-sr"> <?php echo esc_html( $smac_label ); ?></span>
											</button>
											<button type="button" class="aui-btn aui-btn--ghost aui-btn--sm" data-aui-open="smac-prompt-form" data-smac-prompt="edit" data-prompt-id="<?php echo esc_attr( $prompt_id ); ?>">
												<?php esc_html_e( 'Edit', 'specflux-marketing-analytics-chat' ); ?><span class="aui-sr"> <?php echo esc_html( $smac_label ); ?></span>
											</button>
											<button type="button" class="aui-btn aui-btn--danger-outline aui-btn--sm" data-aui-open="smac-prompt-delete" data-smac-prompt="delete" data-prompt-id="<?php echo esc_attr( $prompt_id ); ?>" data-prompt-label="<?php echo esc_attr( $smac_label ); ?>">
												<?php esc_html_e( 'Delete', 'specflux-marketing-analytics-chat' ); ?><span class="aui-sr"> <?php echo esc_html( $smac_label ); ?></span>
											</button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<!-- Import Preset Templates -->
		<section class="aui-card" aria-labelledby="smac-presets-title">
			<div class="aui-card-h">
				<h2 class="aui-card-t" id="smac-presets-title"><?php esc_html_e( 'Import Preset Templates', 'specflux-marketing-analytics-chat' ); ?></h2>
				<p class="aui-card-d"><?php esc_html_e( 'Quick start with pre-built prompt templates for common marketing analytics workflows.', 'specflux-marketing-analytics-chat' ); ?></p>
			</div>
			<ul class="aui-groups smac-presets">
				<?php foreach ( $preset_templates as $preset_key => $preset ) : ?>
					<?php
					// Check if already imported.
					$already_imported = isset( $custom_prompts[ 'marketing-analytics/' . $preset['name'] ] );
					?>
					<li class="aui-item">
						<div class="aui-grow">
							<div class="aui-item-name"><?php echo esc_html( $preset['label'] ); ?></div>
							<div class="aui-muted"><?php echo esc_html( $preset['description'] ); ?></div>
						</div>
						<?php if ( $already_imported ) : ?>
							<span class="aui-badge aui-badge--ok"><?php echo Ui_Kit::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><?php esc_html_e( 'Already imported', 'specflux-marketing-analytics-chat' ); ?></span>
						<?php else : ?>
							<form method="post">
								<input type="hidden" name="specflux_mac_prompts_nonce" value="<?php echo esc_attr( $smac_nonce ); ?>">
								<input type="hidden" name="action" value="import_preset">
								<input type="hidden" name="preset_key" value="<?php echo esc_attr( $preset_key ); ?>">
								<button type="submit" class="aui-btn aui-btn--secondary aui-btn--sm">
									<?php esc_html_e( 'Import', 'specflux-marketing-analytics-chat' ); ?><span class="aui-sr"> <?php echo esc_html( $preset['label'] ); ?></span>
								</button>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>

	<aside class="aui-rail" aria-label="<?php echo esc_attr__( 'Sidebar', 'specflux-marketing-analytics-chat' ); ?>">
		<section class="aui-card" aria-labelledby="smac-prompts-help-title">
			<div class="aui-card-h"><h2 class="aui-card-t" id="smac-prompts-help-title"><?php esc_html_e( 'Help', 'specflux-marketing-analytics-chat' ); ?></h2></div>
			<div class="aui-card-b">
				<ul class="aui-links">
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-abilities' ) ); ?>"><?php echo Ui_Kit::icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php esc_html_e( 'Browse abilities', 'specflux-marketing-analytics-chat' ); ?></span></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-ai-assistant' ) ); ?>"><?php echo Ui_Kit::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php esc_html_e( 'Open the AI Assistant', 'specflux-marketing-analytics-chat' ); ?></span></a></li>
					<li><a href="https://github.com/specflux/marketing-analytics-chat#configuration" target="_blank" rel="noopener noreferrer"><?php echo Ui_Kit::icon( 'book' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span><?php esc_html_e( 'Getting started', 'specflux-marketing-analytics-chat' ); ?></span><?php echo Ui_Kit::icon( 'arrow-square-out', 'aui-end' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?><span class="aui-sr"> <?php esc_html_e( '(opens in a new tab)', 'specflux-marketing-analytics-chat' ); ?></span></a></li>
				</ul>
			</div>
		</section>
	</aside>
</div>
</div>

<!-- Create / edit a prompt. Field names and ids are unchanged. -->
<div class="aui-backdrop" id="smac-prompt-form"<?php echo $smac_form['open'] ? '' : ' hidden'; ?> data-title-create="<?php echo esc_attr__( 'Create Custom Prompt', 'specflux-marketing-analytics-chat' ); ?>" data-title-edit="<?php echo esc_attr__( 'Edit prompt', 'specflux-marketing-analytics-chat' ); ?>" data-submit-create="<?php echo esc_attr__( 'Create Prompt', 'specflux-marketing-analytics-chat' ); ?>" data-submit-edit="<?php echo esc_attr__( 'Save changes', 'specflux-marketing-analytics-chat' ); ?>">
	<div class="aui-dialog smac-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="smac-prompt-form-title">
		<form method="post" id="create-prompt-form">
			<h2 id="smac-prompt-form-title"><?php echo $smac_is_update ? esc_html__( 'Edit prompt', 'specflux-marketing-analytics-chat' ) : esc_html__( 'Create Custom Prompt', 'specflux-marketing-analytics-chat' ); ?></h2>

			<div class="smac-form">
				<div class="aui-field">
					<label class="aui-label" for="prompt_name"><?php esc_html_e( 'Prompt Name', 'specflux-marketing-analytics-chat' ); ?> *</label>
					<input type="text" id="prompt_name" name="prompt_name" class="aui-input" required aria-describedby="prompt_name_help"
						pattern="[a-z0-9\-]+"
						value="<?php echo esc_attr( $smac_value( 'prompt_name' ) ); ?>"
						placeholder="analyze-conversion-rate"
						title="<?php esc_attr_e( 'Use only lowercase letters, numbers, and hyphens', 'specflux-marketing-analytics-chat' ); ?>"<?php echo $smac_is_update ? ' readonly' : ''; ?>>
					<p class="aui-help" id="prompt_name_help"><?php esc_html_e( 'Unique identifier (lowercase, hyphens only). Will be prefixed with "marketing-analytics/"', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="prompt_label"><?php esc_html_e( 'Display Label', 'specflux-marketing-analytics-chat' ); ?> *</label>
					<input type="text" id="prompt_label" name="prompt_label" class="aui-input" required aria-describedby="prompt_label_help" value="<?php echo esc_attr( $smac_value( 'prompt_label' ) ); ?>" placeholder="Analyze Conversion Rate">
					<p class="aui-help" id="prompt_label_help"><?php esc_html_e( 'Human-readable name shown in MCP clients', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="prompt_description"><?php esc_html_e( 'Description', 'specflux-marketing-analytics-chat' ); ?> *</label>
					<textarea id="prompt_description" name="prompt_description" rows="3" class="aui-textarea" required aria-describedby="prompt_description_help" placeholder="Analyzes conversion rate trends and identifies optimization opportunities"><?php echo esc_textarea( $smac_value( 'prompt_description' ) ); ?></textarea>
					<p class="aui-help" id="prompt_description_help"><?php esc_html_e( 'Brief description of what this prompt does', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="prompt_instructions"><?php esc_html_e( 'Instructions', 'specflux-marketing-analytics-chat' ); ?> *</label>
					<textarea id="prompt_instructions" name="prompt_instructions" rows="6" class="aui-textarea smac-mono" required aria-describedby="prompt_instructions_help" placeholder="Step-by-step instructions for the AI assistant...&#10;&#10;1. Call marketing-analytics/get-ga4-metrics&#10;2. Analyze the data&#10;3. Provide recommendations"><?php echo esc_textarea( $smac_value( 'prompt_instructions' ) ); ?></textarea>
					<p class="aui-help" id="prompt_instructions_help"><?php esc_html_e( 'Detailed step-by-step instructions for AI. Use {{argument_name}} placeholders for dynamic values.', 'specflux-marketing-analytics-chat' ); ?></p>
				</div>

				<div class="aui-field">
					<label class="aui-label" for="prompt_arguments"><?php esc_html_e( 'Arguments (JSON)', 'specflux-marketing-analytics-chat' ); ?></label>
					<textarea id="prompt_arguments" name="prompt_arguments" rows="4" class="aui-textarea smac-mono" aria-describedby="prompt_arguments_help prompt_arguments_error" placeholder='[&#10;  {&#10;    "name": "date_range",&#10;    "type": "string",&#10;    "description": "Date range to analyze",&#10;    "required": false,&#10;    "default": "7daysAgo"&#10;  }&#10;]'><?php echo esc_textarea( $smac_value( 'prompt_arguments' ) ); ?></textarea>
					<p class="aui-error" id="prompt_arguments_error" role="alert" hidden><?php esc_html_e( 'Invalid JSON in Arguments field. Please check your syntax.', 'specflux-marketing-analytics-chat' ); ?></p>
					<p class="aui-help" id="prompt_arguments_help">
						<?php esc_html_e( 'Optional: Define arguments as JSON array. Leave empty if no arguments needed.', 'specflux-marketing-analytics-chat' ); ?>
						<button type="button" class="smac-linkbtn" id="show-argument-example" aria-expanded="false" aria-controls="argument-example"><?php esc_html_e( 'Show example', 'specflux-marketing-analytics-chat' ); ?></button>
					</p>
					<pre id="argument-example" class="smac-pre" hidden>[
	{
		"name": "conversion_event",
		"type": "string",
		"description": "GA4 event name for conversion",
		"required": true
	},
	{
		"name": "min_sessions",
		"type": "integer",
		"description": "Minimum sessions to include",
		"required": false,
		"default": 100
	}
]</pre>
				</div>
			</div>

			<div class="aui-dialog-f">
				<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Cancel', 'specflux-marketing-analytics-chat' ); ?></button>
				<button type="submit" class="aui-btn aui-btn--primary" id="smac-prompt-submit"><?php echo $smac_is_update ? esc_html__( 'Save changes', 'specflux-marketing-analytics-chat' ) : esc_html__( 'Create Prompt', 'specflux-marketing-analytics-chat' ); ?></button>
			</div>
		</form>
	</div>
</div>

<?php // Hidden fields live outside the dialog (form="..."), so the kit's focus trap only sees visible controls. ?>
<input type="hidden" form="create-prompt-form" name="specflux_mac_prompts_nonce" value="<?php echo esc_attr( $smac_nonce ); ?>">
<input type="hidden" form="create-prompt-form" name="action" id="smac-prompt-action" value="<?php echo esc_attr( $smac_form['action'] ); ?>">
<input type="hidden" form="create-prompt-form" name="prompt_id" id="smac-prompt-edit-id" value="<?php echo esc_attr( $smac_form['prompt_id'] ); ?>">

<!-- Details of one prompt -->
<div class="aui-backdrop" id="smac-prompt-view" hidden>
	<div class="aui-dialog smac-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="modal-prompt-label">
		<h2 id="modal-prompt-label"></h2>
		<p><code class="aui-code" id="modal-prompt-id"></code></p>
		<p id="modal-prompt-description"></p>

		<h3 class="smac-sub"><?php esc_html_e( 'Instructions', 'specflux-marketing-analytics-chat' ); ?></h3>
		<pre id="modal-prompt-instructions" class="smac-pre"></pre>

		<div id="modal-prompt-arguments-section">
			<h3 class="smac-sub"><?php esc_html_e( 'Arguments', 'specflux-marketing-analytics-chat' ); ?></h3>
			<ul class="smac-args" id="modal-prompt-arguments"></ul>
		</div>

		<div class="aui-dialog-f">
			<button type="button" class="aui-btn aui-btn--secondary" id="close-modal" data-aui-close><?php esc_html_e( 'Close', 'specflux-marketing-analytics-chat' ); ?></button>
		</div>
	</div>
</div>

<!-- Confirm delete -->
<div class="aui-backdrop" id="smac-prompt-delete" hidden>
	<div class="aui-dialog" role="alertdialog" aria-modal="true" aria-labelledby="smac-delete-title" aria-describedby="smac-delete-text">
		<form method="post" id="smac-delete-form">
			<h2 id="smac-delete-title"><?php esc_html_e( 'Delete prompt', 'specflux-marketing-analytics-chat' ); ?></h2>
			<p id="smac-delete-text"><?php esc_html_e( 'Are you sure you want to delete this prompt?', 'specflux-marketing-analytics-chat' ); ?></p>
			<p><strong id="smac-delete-name"></strong></p>
			<div class="aui-dialog-f">
				<button type="button" class="aui-btn aui-btn--secondary" data-aui-close><?php esc_html_e( 'Cancel', 'specflux-marketing-analytics-chat' ); ?></button>
				<button type="submit" class="aui-btn aui-btn--danger"><?php esc_html_e( 'Delete', 'specflux-marketing-analytics-chat' ); ?></button>
			</div>
		</form>
	</div>
</div>
<input type="hidden" form="smac-delete-form" name="specflux_mac_prompts_nonce" value="<?php echo esc_attr( $smac_nonce ); ?>">
<input type="hidden" form="smac-delete-form" name="action" value="delete_prompt">
<input type="hidden" form="smac-delete-form" name="prompt_id" id="smac-delete-id" value="">
</div>
