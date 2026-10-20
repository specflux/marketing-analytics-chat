<?php
/**
 * AI Chat Interface Template
 *
 * @package Specflux_Marketing_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Specflux_Marketing_Analytics\Chat\Chat_Ajax_Handler;
use Specflux_Marketing_Analytics\Chat\Chat_Manager;
use Specflux_Marketing_Analytics\Chat\Pending_Actions;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\Reports\Weekly_Prompt_Builder;

$chat_manager = new Chat_Manager();
$user_id      = get_current_user_id();

// Get conversations.
$conversations = $chat_manager->get_conversations( $user_id, 20 );

// Get active conversation.
$conversation_nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
$active_conversation_id = null;
if ( isset( $_GET['conversation_id'] ) && $conversation_nonce && wp_verify_nonce( $conversation_nonce, 'specflux_mac_conversation' ) ) {
	$active_conversation_id = absint( wp_unslash( $_GET['conversation_id'] ) );
}
$active_conversation     = $active_conversation_id ? $chat_manager->get_conversation( $active_conversation_id ) : null;

// Ownership gate: never render another user's conversation (IDOR guard).
if ( $active_conversation && (int) $active_conversation->user_id !== $user_id ) {
	$active_conversation    = null;
	$active_conversation_id = null;
}

$messages                = $active_conversation_id ? $chat_manager->get_messages( $active_conversation_id ) : array();
$conversation_link_nonce = wp_create_nonce( 'specflux_mac_conversation' );

if ( ! function_exists( 'specflux_mac_format_chat_markdown' ) ) {
	/**
	 * Apply lightweight inline Markdown to stored chat text.
	 *
	 * Covers the common inline cases (links, bold, inline code) so history on
	 * reload matches the live JS renderer instead of showing literal markup.
	 * The result is sanitized by wp_kses_post() at the call site.
	 *
	 * @param string $text Raw message text.
	 * @return string Text with inline Markdown converted to HTML.
	 */
	function specflux_mac_format_chat_markdown( $text ) {
		// Links: [label](https://url).
		$text = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text );
		// Bold: **text** or __text__.
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/s', '<strong>$1</strong>', $text );
		// Inline code: `code`.
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return $text;
	}
}

?>

<div class="aui" data-brand="specflux">
<?php Ui_Kit::render_header( 'specflux-mac-ai-assistant' ); ?>

<div class="wrap specflux-marketing-analytics-chat aui-body">
	<p class="aui-page-intro">
		<?php esc_html_e( 'Chat with an AI assistant about your marketing analytics data. The assistant answers from the sources connected on the Connections page: Google Analytics 4, Search Console, Microsoft Clarity, Bing Webmaster Tools, PageSpeed Insights, and your own posts and SEO plugin.', 'specflux-marketing-analytics-chat' ); ?>
	</p>

	<div class="chat-container smac-chat-layout">
		<!-- Conversation list -->
		<aside class="chat-sidebar aui-rail" aria-label="<?php esc_attr_e( 'Conversations', 'specflux-marketing-analytics-chat' ); ?>">
			<div class="aui-card smac-chat-convos">
				<div class="aui-card-h">
					<h2 class="aui-card-t aui-grow"><?php esc_html_e( 'Conversations', 'specflux-marketing-analytics-chat' ); ?></h2>
					<button type="button" id="new-conversation" class="aui-btn aui-btn--primary aui-btn--sm">
						<?php echo Ui_Kit::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
						<?php esc_html_e( 'New conversation', 'specflux-marketing-analytics-chat' ); ?>
					</button>
				</div>

				<div class="conversation-list aui-card-b--flush">
					<?php if ( empty( $conversations ) ) : ?>
						<div class="no-conversations aui-empty">
							<p><?php esc_html_e( 'No conversations yet. Start a new conversation!', 'specflux-marketing-analytics-chat' ); ?></p>
						</div>
					<?php else : ?>
						<ul class="smac-convo-list">
						<?php foreach ( $conversations as $conversation ) : ?>
							<?php
							$is_active = $active_conversation_id === (int) $conversation->id;
							$class     = $is_active ? 'conversation-item active' : 'conversation-item';
							?>
							<li class="<?php echo esc_attr( $class ); ?>">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-ai-assistant&conversation_id=' . $conversation->id . '&_wpnonce=' . $conversation_link_nonce ) ); ?>" class="conversation-link"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
									<span class="conversation-title"><?php echo esc_html( $conversation->title ); ?></span>
									<span class="conversation-date">
										<?php
										/* translators: %s: human-readable time difference, for example "3 hours" */
										echo esc_html( sprintf( __( '%s ago', 'specflux-marketing-analytics-chat' ), human_time_diff( strtotime( $conversation->updated_at ), time() ) ) );
										?>
									</span>
								</a>
								<button
									type="button"
									class="delete-conversation aui-btn aui-btn--ghost aui-btn--sm aui-btn--icon"
									data-conversation-id="<?php echo esc_attr( $conversation->id ); ?>"
									data-conversation-title="<?php echo esc_attr( $conversation->title ); ?>"
									title="<?php esc_attr_e( 'Delete conversation', 'specflux-marketing-analytics-chat' ); ?>"
									aria-label="<?php esc_attr_e( 'Delete conversation', 'specflux-marketing-analytics-chat' ); ?>"
								>
									<?php echo Ui_Kit::icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
								</button>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</aside>

		<!-- Chat area -->
		<div class="chat-main aui-card smac-chat-main">
			<?php if ( $active_conversation ) : ?>
				<!-- Messages -->
				<div class="chat-messages aui-chat" id="chat-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation', 'specflux-marketing-analytics-chat' ); ?>">
					<?php if ( empty( $messages ) ) : ?>
						<div class="welcome-message aui-empty">
							<span class="aui-tile"><?php echo Ui_Kit::icon( 'robot', 'aui-i--lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
							<h2><?php esc_html_e( 'Welcome to your AI Analytics Assistant!', 'specflux-marketing-analytics-chat' ); ?></h2>
							<p><?php esc_html_e( 'Ask me anything about your marketing analytics data. I can help with:', 'specflux-marketing-analytics-chat' ); ?></p>
							<ul class="smac-welcome-list">
								<li><?php esc_html_e( 'Traffic trends and performance metrics', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'Search Console rankings and queries', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'User behavior insights from Clarity', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'Period comparisons and analysis', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'SEO fixes for your posts, page speed, and Bing search', 'specflux-marketing-analytics-chat' ); ?></li>
							</ul>
						</div>
					<?php else : ?>
						<?php foreach ( $messages as $message ) : ?>
							<?php
							$msg_role      = $message->role;
							$msg_has_text  = '' !== trim( (string) $message->content );
							$msg_has_tools = ! empty( $message->tool_calls );
							/* translators: %s: human-readable time difference, for example "3 hours" */
							$msg_time = sprintf( __( '%s ago', 'specflux-marketing-analytics-chat' ), human_time_diff( strtotime( $message->created_at ), time() ) );

							// Tool result rows render as a compact collapsible result block, not a bubble.
							if ( 'tool' === $msg_role ) :
								$tool_pretty  = (string) $message->content;
								$tool_decoded = json_decode( $tool_pretty, true );
								if ( null !== $tool_decoded ) {
									$tool_pretty = wp_json_encode( $tool_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
								}
								?>
								<div class="message message-tool-activity aui-msg aui-msg--agent">
									<span class="aui-avatar smac-avatar--ghost" aria-hidden="true"></span>
									<div class="aui-msg-body">
										<details class="tool-activity aui-result">
											<summary class="aui-result-h">
												<?php echo Ui_Kit::icon( 'gears' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
												<?php
												/* translators: %s: Tool name */
												echo esc_html( sprintf( __( 'Used tool: %s', 'specflux-marketing-analytics-chat' ), $message->tool_name ) );
												?>
												<span class="aui-badge aui-badge--ok aui-end"><?php esc_html_e( 'Ran', 'specflux-marketing-analytics-chat' ); ?></span>
											</summary>
											<div class="aui-result-b"><pre class="tool-result"><?php echo esc_html( $tool_pretty ); ?></pre></div>
										</details>
									</div>
								</div>
								<?php
								continue;
							endif;

							// Skip empty assistant/system bubbles that carry no text and no tool calls.
							if ( ! $msg_has_text && ! $msg_has_tools ) {
								continue;
							}

							$is_user = 'user' === $msg_role;

							if ( 'system' === $msg_role ) :
								?>
								<div class="message message-system aui-notice aui-notice--info">
									<?php echo Ui_Kit::icon( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
									<div class="message-text" data-md="<?php echo esc_attr( (string) $message->content ); ?>"><?php echo wp_kses_post( wpautop( specflux_mac_format_chat_markdown( (string) $message->content ) ) ); ?></div>
								</div>
								<?php
								continue;
							endif;

							if ( $is_user ) :
								?>
								<div class="message message-user aui-msg aui-msg--user">
									<div class="smac-user-wrap">
										<div class="aui-bubble">
											<div class="message-text" data-md="<?php echo esc_attr( (string) $message->content ); ?>"><?php echo wp_kses_post( wpautop( specflux_mac_format_chat_markdown( (string) $message->content ) ) ); ?></div>
										</div>
										<div class="message-time"><?php echo esc_html( $msg_time ); ?></div>
									</div>
								</div>
								<?php
								continue;
							endif;
							?>
							<div class="message message-<?php echo esc_attr( $msg_role ); ?> aui-msg aui-msg--agent">
								<span class="aui-avatar message-avatar" role="img" aria-label="<?php esc_attr_e( 'AI Assistant', 'specflux-marketing-analytics-chat' ); ?>"><?php echo Ui_Kit::icon( 'robot' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
								<div class="message-content aui-msg-body">
									<?php if ( $msg_has_text ) : ?>
										<div class="message-text" data-md="<?php echo esc_attr( (string) $message->content ); ?>"><?php echo wp_kses_post( wpautop( specflux_mac_format_chat_markdown( (string) $message->content ) ) ); ?></div>
									<?php endif; ?>
									<?php if ( $msg_has_tools ) : ?>
										<div class="tool-calls">
											<span class="tool-calls-label">
												<?php
												$tool_count = count( $message->tool_calls );
												/* translators: %d: number of tools used */
												echo esc_html( sprintf( _n( 'Used %d tool', 'Used %d tools', $tool_count, 'specflux-marketing-analytics-chat' ), $tool_count ) );
												?>
											</span>
											<ul>
												<?php foreach ( $message->tool_calls as $tool_call ) : ?>
													<?php $tc_name = (string) ( $tool_call['name'] ?? 'tool' ); ?>
													<li class="aui-chip"><?php echo esc_html( Chat_Ajax_Handler::normalize_ability_name( $tc_name ) ); ?></li>
												<?php endforeach; ?>
											</ul>
										</div>
									<?php endif; ?>
									<?php
									$msg_actions = ( 'assistant' === $msg_role && is_array( $message->metadata ) && ! empty( $message->metadata['actions'] ) && is_array( $message->metadata['actions'] ) ) ? $message->metadata['actions'] : array();
									foreach ( $msg_actions as $msg_action ) :
										if ( empty( $msg_action['id'] ) ) {
											continue;
										}
										$action_state  = Pending_Actions::status_for_display( (string) $msg_action['id'], get_current_user_id() );
										$action_status = $action_state['status'];
										$status_labels = array(
											'pending'   => __( 'Needs approval', 'specflux-marketing-analytics-chat' ),
											'applied'   => __( 'Applied', 'specflux-marketing-analytics-chat' ),
											'dismissed' => __( 'Dismissed', 'specflux-marketing-analytics-chat' ),
											'expired'   => __( 'Expired', 'specflux-marketing-analytics-chat' ),
										);
										$badge_tones   = array(
											'pending' => 'warn',
											'applied' => 'ok',
										);
										$status_text   = $status_labels[ $action_status ] ?? $action_status;
										$badge_tone    = $badge_tones[ $action_status ] ?? '';
										?>
										<div class="smac-action aui-approval-mini smac-action--<?php echo esc_attr( $action_status ); ?>" data-action-id="<?php echo esc_attr( (string) $msg_action['id'] ); ?>">
											<div class="aui-result-h">
												<?php echo Ui_Kit::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
												<?php esc_html_e( 'Proposed change', 'specflux-marketing-analytics-chat' ); ?>
												<span class="smac-action-badge aui-badge<?php echo $badge_tone ? ' aui-badge--' . esc_attr( $badge_tone ) : ''; ?> aui-end"><?php echo esc_html( $status_text ); ?></span>
											</div>
											<?php if ( 'pending' === $action_status ) : ?>
												<div class="aui-result-b">
													<div class="smac-action-summary"><?php echo esc_html( (string) ( $msg_action['summary'] ?? '' ) ); ?></div>
													<div class="smac-action-status" role="status"><?php echo esc_html( (string) $action_state['result'] ); ?></div>
												</div>
												<div class="aui-result-f smac-action-buttons">
													<button type="button" class="aui-btn aui-btn--primary aui-btn--sm smac-action-apply"><?php echo esc_html( (string) ( $msg_action['label'] ?? __( 'Apply', 'specflux-marketing-analytics-chat' ) ) ); ?></button>
													<button type="button" class="aui-btn aui-btn--secondary aui-btn--sm smac-action-dismiss"><?php esc_html_e( 'Dismiss', 'specflux-marketing-analytics-chat' ); ?></button>
												</div>
											<?php else : ?>
												<div class="aui-result-b">
													<div class="smac-action-status" role="status">
														<?php echo esc_html( trim( $status_text . ': ' . (string) ( $msg_action['summary'] ?? '' ) . ( $action_state['result'] ? ' - ' . $action_state['result'] : '' ) ) ); ?>
													</div>
												</div>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
									<div class="message-time"><?php echo esc_html( $msg_time ); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Message input -->
				<div class="chat-input-container smac-chat-input">
					<form id="chat-form" method="post">
						<?php wp_nonce_field( 'specflux_mac_admin', 'chat_nonce' ); ?>
						<input type="hidden" name="conversation_id" value="<?php echo esc_attr( $active_conversation_id ); ?>">

						<?php if ( empty( $messages ) ) : ?>
							<div class="suggested-prompts aui-suggest" id="suggested-prompts">
								<?php foreach ( Weekly_Prompt_Builder::suggestions( Widget_Headline::cached() ) as $specflux_mac_chip ) : ?>
									<button type="button" class="suggested-prompt" data-prompt="<?php echo esc_attr( $specflux_mac_chip ); ?>">
										<?php echo esc_html( $specflux_mac_chip ); ?>
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div class="input-wrapper aui-composer">
							<label class="aui-sr" for="message-input"><?php esc_html_e( 'Message', 'specflux-marketing-analytics-chat' ); ?></label>
							<textarea
								id="message-input"
								name="message"
								placeholder="<?php esc_attr_e( 'Ask about your analytics data...', 'specflux-marketing-analytics-chat' ); ?>"
								rows="2"
								required
							></textarea>
							<button type="submit" id="send-button" class="aui-btn aui-btn--primary">
								<?php echo Ui_Kit::icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
								<?php esc_html_e( 'Send', 'specflux-marketing-analytics-chat' ); ?>
							</button>
						</div>
						<p class="input-hint aui-muted">
							<?php
							printf(
								/* translators: 1: Enter key label, 2: Shift+Enter key label */
								esc_html__( 'Press %1$s to send, %2$s for a new line.', 'specflux-marketing-analytics-chat' ),
								'<kbd>' . esc_html__( 'Enter', 'specflux-marketing-analytics-chat' ) . '</kbd>',
								'<kbd>' . esc_html__( 'Shift + Enter', 'specflux-marketing-analytics-chat' ) . '</kbd>'
							);
							?>
						</p>
					</form>
				</div>
			<?php else : ?>
				<!-- No conversation selected -->
				<div class="no-conversation-selected">
					<div class="empty-state aui-empty">
						<span class="aui-tile"><?php echo Ui_Kit::icon( 'chat', 'aui-i--lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?></span>
						<h2><?php esc_html_e( 'No Conversation Selected', 'specflux-marketing-analytics-chat' ); ?></h2>
						<p><?php esc_html_e( 'Select a conversation from the list or start a new one.', 'specflux-marketing-analytics-chat' ); ?></p>
						<button type="button" id="new-conversation-main" class="aui-btn aui-btn--primary">
							<?php echo Ui_Kit::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
							<?php esc_html_e( 'Start New Conversation', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
</div>
