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

<div class="wrap specflux-marketing-analytics-chat">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Assistant', 'specflux-marketing-analytics-chat' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Chat with an AI assistant about your marketing analytics data. The assistant can access Clarity, Google Analytics, and Search Console data.', 'specflux-marketing-analytics-chat' ); ?>
	</p>

	<div class="chat-container">
		<!-- Conversation Sidebar -->
		<div class="chat-sidebar">
			<div class="sidebar-header">
				<button type="button" id="new-conversation" class="button button-primary button-block">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'New Conversation', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</div>

			<div class="conversation-list">
				<?php if ( empty( $conversations ) ) : ?>
					<div class="no-conversations">
						<p><?php esc_html_e( 'No conversations yet. Start a new conversation!', 'specflux-marketing-analytics-chat' ); ?></p>
					</div>
				<?php else : ?>
					<?php foreach ( $conversations as $conversation ) : ?>
						<?php
						$is_active = $active_conversation_id === (int) $conversation->id;
						$class     = $is_active ? 'conversation-item active' : 'conversation-item';
						?>
						<div class="<?php echo esc_attr( $class ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=specflux-mac-ai-assistant&conversation_id=' . $conversation->id . '&_wpnonce=' . $conversation_link_nonce ) ); ?>" class="conversation-link">
								<div class="conversation-title"><?php echo esc_html( $conversation->title ); ?></div>
								<div class="conversation-date"><?php echo esc_html( human_time_diff( strtotime( $conversation->updated_at ), time() ) . ' ago' ); ?></div>
							</a>
							<button
								type="button"
								class="delete-conversation"
								data-conversation-id="<?php echo esc_attr( $conversation->id ); ?>"
								data-conversation-title="<?php echo esc_attr( $conversation->title ); ?>"
								title="<?php esc_attr_e( 'Delete conversation', 'specflux-marketing-analytics-chat' ); ?>"
							>
								<span class="dashicons dashicons-trash"></span>
							</button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- Chat Area -->
		<div class="chat-main">
			<?php if ( $active_conversation ) : ?>
				<!-- Message Area -->
				<div class="chat-messages" id="chat-messages">
					<?php if ( empty( $messages ) ) : ?>
						<div class="welcome-message">
							<h2><?php esc_html_e( 'Welcome to your AI Analytics Assistant!', 'specflux-marketing-analytics-chat' ); ?></h2>
							<p><?php esc_html_e( 'Ask me anything about your marketing analytics data. I can help with:', 'specflux-marketing-analytics-chat' ); ?></p>
							<ul>
								<li><?php esc_html_e( 'Traffic trends and performance metrics', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'Search Console rankings and queries', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'User behavior insights from Clarity', 'specflux-marketing-analytics-chat' ); ?></li>
								<li><?php esc_html_e( 'Period comparisons and analysis', 'specflux-marketing-analytics-chat' ); ?></li>
							</ul>
						</div>
					<?php else : ?>
						<?php foreach ( $messages as $message ) : ?>
							<?php
							$msg_role      = $message->role;
							$msg_has_text  = '' !== trim( (string) $message->content );
							$msg_has_tools = ! empty( $message->tool_calls );

							// Tool result rows render as a compact collapsible chip, not a bubble.
							if ( 'tool' === $msg_role ) :
								$tool_pretty  = (string) $message->content;
								$tool_decoded = json_decode( $tool_pretty, true );
								if ( null !== $tool_decoded ) {
									$tool_pretty = wp_json_encode( $tool_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
								}
								?>
								<div class="message message-tool-activity">
									<details class="tool-activity">
										<summary>
											<span class="dashicons dashicons-admin-tools"></span>
											<?php
											/* translators: %s: Tool name */
											echo esc_html( sprintf( __( 'Used tool: %s', 'specflux-marketing-analytics-chat' ), $message->tool_name ) );
											?>
										</summary>
										<pre class="tool-result"><?php echo esc_html( $tool_pretty ); ?></pre>
									</details>
								</div>
								<?php
								continue;
							endif;

							// Skip empty assistant/system bubbles that carry no text and no tool calls.
							if ( ! $msg_has_text && ! $msg_has_tools ) {
								continue;
							}

							$is_user      = 'user' === $msg_role;
							$avatar_label = $is_user ? __( 'You', 'specflux-marketing-analytics-chat' ) : __( 'AI Assistant', 'specflux-marketing-analytics-chat' );
							?>
							<div class="message message-<?php echo esc_attr( $msg_role ); ?>">
								<div class="message-avatar" role="img" aria-label="<?php echo esc_attr( $avatar_label ); ?>">
									<?php if ( $is_user ) : ?>
										<span class="dashicons dashicons-admin-users"></span>
									<?php else : ?>
										<span class="dashicons dashicons-superhero"></span>
									<?php endif; ?>
								</div>
								<div class="message-content">
									<?php if ( $msg_has_text ) : ?>
										<div class="message-text" data-md="<?php echo esc_attr( (string) $message->content ); ?>"><?php echo wp_kses_post( wpautop( specflux_mac_format_chat_markdown( (string) $message->content ) ) ); ?></div>
									<?php endif; ?>
									<?php if ( $msg_has_tools ) : ?>
										<div class="tool-calls">
											<span class="tool-calls-label">
												<span class="dashicons dashicons-admin-tools"></span>
												<?php
												$tool_count = count( $message->tool_calls );
												/* translators: %d: number of tools used */
												echo esc_html( sprintf( _n( 'Used %d tool', 'Used %d tools', $tool_count, 'specflux-marketing-analytics-chat' ), $tool_count ) );
												?>
											</span>
											<ul>
												<?php foreach ( $message->tool_calls as $tool_call ) : ?>
													<?php $tc_name = (string) ( $tool_call['name'] ?? 'tool' ); ?>
													<li><?php echo esc_html( Chat_Ajax_Handler::normalize_ability_name( $tc_name ) ); ?></li>
												<?php endforeach; ?>
											</ul>
										</div>
									<?php endif; ?>
									<div class="message-time">
										<?php echo esc_html( human_time_diff( strtotime( $message->created_at ), time() ) . ' ago' ); ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Message Input -->
				<div class="chat-input-container">
					<form id="chat-form" method="post">
						<?php wp_nonce_field( 'specflux_mac_admin', 'chat_nonce' ); ?>
						<input type="hidden" name="conversation_id" value="<?php echo esc_attr( $active_conversation_id ); ?>">

						<div class="suggested-prompts" id="suggested-prompts">
							<button type="button" class="suggested-prompt" data-prompt="<?php esc_attr_e( 'Show me traffic trends for the last 30 days', 'specflux-marketing-analytics-chat' ); ?>">
								<?php esc_html_e( 'Show me traffic trends for the last 30 days', 'specflux-marketing-analytics-chat' ); ?>
							</button>
							<button type="button" class="suggested-prompt" data-prompt="<?php esc_attr_e( 'What are my top performing pages?', 'specflux-marketing-analytics-chat' ); ?>">
								<?php esc_html_e( 'What are my top performing pages?', 'specflux-marketing-analytics-chat' ); ?>
							</button>
							<button type="button" class="suggested-prompt" data-prompt="<?php esc_attr_e( 'Compare this week vs last week', 'specflux-marketing-analytics-chat' ); ?>">
								<?php esc_html_e( 'Compare this week vs last week', 'specflux-marketing-analytics-chat' ); ?>
							</button>
						</div>

						<div class="input-wrapper">
							<textarea
								id="message-input"
								name="message"
								placeholder="<?php esc_attr_e( 'Ask about your analytics data...', 'specflux-marketing-analytics-chat' ); ?>"
								rows="3"
								required
							></textarea>
							<button type="submit" id="send-button" class="button button-primary">
								<span class="dashicons dashicons-arrow-up-alt2"></span>
								<?php esc_html_e( 'Send', 'specflux-marketing-analytics-chat' ); ?>
							</button>
						</div>
						<p class="input-hint">
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
				<!-- No Conversation Selected -->
				<div class="no-conversation-selected">
					<div class="empty-state">
						<span class="dashicons dashicons-format-chat"></span>
						<h2><?php esc_html_e( 'No Conversation Selected', 'specflux-marketing-analytics-chat' ); ?></h2>
						<p><?php esc_html_e( 'Select a conversation from the sidebar or start a new one.', 'specflux-marketing-analytics-chat' ); ?></p>
						<button type="button" id="new-conversation-main" class="button button-primary button-large">
							<span class="dashicons dashicons-plus-alt2"></span>
							<?php esc_html_e( 'Start New Conversation', 'specflux-marketing-analytics-chat' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
