/**
 * AI Chat Interface JavaScript
 *
 * @package Marketing_Analytics_MCP
 */

(function($) {
	'use strict';

	/**
	 * Chat Interface Object
	 */
	var ChatInterface = {

		/**
		 * Initialize chat interface
		 */
		init: function() {
			this.bindEvents();
			this.renderStoredMessages();
			this.scrollToBottom();
		},

		/**
		 * Re-render server-rendered history through the same Markdown
		 * formatter used for live messages, so reloads match exactly.
		 */
		renderStoredMessages: function() {
			var self = this;
			$('#chat-messages .message-text[data-md]').each(function() {
				$(this).html(self.formatMessage($(this).attr('data-md')));
			});
		},

		/**
		 * Bind event handlers
		 */
		bindEvents: function() {
			// New conversation buttons
			$('#new-conversation, #new-conversation-main').on('click', this.createNewConversation.bind(this));

			// Delete conversation buttons
			$(document).on('click', '.delete-conversation', this.deleteConversation.bind(this));

			// Suggested prompts
			$('.suggested-prompt').on('click', this.fillSuggestedPrompt.bind(this));

			// Form submission
			$('#chat-form').on('submit', this.sendMessage.bind(this));

			// Auto-resize textarea
			$('#message-input').on('input', this.autoResizeTextarea);

			// Keyboard shortcuts: Enter sends, Shift+Enter inserts a new line.
			$('#message-input').on('keydown', function(e) {
				if (e.keyCode === 13 && !e.shiftKey) {
					e.preventDefault();
					$('#chat-form').submit();
				}
			});
		},

		/**
		 * Create a new conversation
		 */
		createNewConversation: function(e) {
			e.preventDefault();

			// Show loading state
			var $button = $(e.currentTarget);
			var originalText = $button.html();
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Creating...');

			// Create conversation via AJAX
			$.ajax({
				url: specfluxMacChat.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_create_conversation',
					nonce: specfluxMacChat.nonce,
					user_id: specfluxMacChat.userId
				},
				success: function(response) {
					if (response.success && response.data.conversation_id) {
						// Redirect to new conversation
						window.location.href = specfluxMacChat.chatPageUrl + '&conversation_id=' + response.data.conversation_id + '&_wpnonce=' + specfluxMacChat.conversationNonce;
					} else {
						alert('Failed to create conversation. Please try again.');
						$button.prop('disabled', false).html(originalText);
					}
				},
				error: function() {
					alert('Failed to create conversation. Please try again.');
					$button.prop('disabled', false).html(originalText);
				}
			});
		},


	/**
	 * Delete a conversation
	 */
	deleteConversation: function(e) {
		e.preventDefault();
		e.stopPropagation();

		var $button = $(e.currentTarget);
		var conversationId = $button.data('conversation-id');
		var conversationTitle = $button.data('conversation-title');

		// Confirm deletion
		if (!confirm('Are you sure you want to delete "' + conversationTitle + '"? This action cannot be undone.')) {
			return;
		}

		// Show loading state
		$button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span>');

		// Delete conversation via AJAX
		$.ajax({
			url: specfluxMacChat.ajaxUrl,
			type: 'POST',
			data: {
				action: 'specflux_mac_delete_conversation',
				nonce: specfluxMacChat.nonce,
				conversation_id: conversationId
			},
			success: function(response) {
				if (response.success) {
					// If this was the active conversation, redirect to chat page without conversation
					if (parseInt(specfluxMacChat.conversationId) === parseInt(conversationId)) {
						window.location.href = specfluxMacChat.chatPageUrl;
					} else {
						// Just remove from sidebar
						$button.closest('.conversation-item').fadeOut(300, function() {
							$(this).remove();
						});
					}
				} else {
					alert(response.data.message || 'Failed to delete conversation. Please try again.');
					$button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
				}
			},
			error: function() {
				alert('Failed to delete conversation. Please try again.');
				$button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>');
			}
		});
	},
		/**
		 * Fill suggested prompt into input
		 */
		fillSuggestedPrompt: function(e) {
			e.preventDefault();
			var prompt = $(e.currentTarget).data('prompt');
			$('#message-input').val(prompt).focus();
		},

		/**
		 * Send message
		 */
		sendMessage: function(e) {
			e.preventDefault();

			var $form = $(e.currentTarget);
			var $input = $('#message-input');
			var $sendButton = $('#send-button');
			var message = $input.val().trim();

			if (!message) {
				return;
			}

			// Disable form
			$input.prop('disabled', true);
			$sendButton.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Sending...');

			// Hide suggested prompts after first message
			$('#suggested-prompts').fadeOut();

			// Add user message to UI immediately
			this.addMessageToUI('user', message);
			$input.val('');

			// Show typing indicator
			this.showTypingIndicator();

			// Send message via AJAX
			$.ajax({
				url: specfluxMacChat.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_send_message',
					nonce: specfluxMacChat.nonce,
					conversation_id: specfluxMacChat.conversationId,
					message: message
				},
				success: function(response) {
					this.hideTypingIndicator();

					if (response.success && response.data) {
						// Handle multiple messages if available (tool calls generate multiple responses)
						if (response.data.messages && response.data.messages.length > 0) {
							var self = this;
							response.data.messages.forEach(function(msg, index) {
								// Add each message to UI. Tool calls render as a
								// compact chip rather than inline pseudo-text.
								if (msg.tool_calls && msg.tool_calls.length > 0) {
									self.addMessageToUI('assistant', msg.content || '', msg.usage || null, response.data.tool_metadata, null, msg.tool_calls);
								} else if (msg.content) {
									// Regular message or final response after tool calls
									// Use 'error' role if is_error flag is set for distinct styling
									var msgRole = msg.is_error ? 'error' : 'assistant';
									// Pass failed_tools for retry buttons on error messages
									var failedTools = msg.is_error ? response.data.failed_tools : null;
									self.addMessageToUI(msgRole, msg.content, msg.usage, index === 0 ? response.data.tool_metadata : null, failedTools);
								}
							});
						} else if (response.data.content) {
							// Fallback to single content (backward compatibility)
							this.addMessageToUI('assistant', response.data.content, response.data.usage, response.data.tool_metadata);
						}

						// S11: inline Agent Safety approval card.
						if (response.data.approval) {
							this.renderApprovalCard(response.data);
						}

						// Update conversation title if this was the first message
						if (response.data.new_title) {
							this.updateConversationTitle(response.data.new_title);
						}
					} else {
						this.addMessageToUI('system', 'Sorry, I encountered an error. Please try again.');
					}

					// Re-enable form
					$input.prop('disabled', false).focus();
					$sendButton.prop('disabled', false).html('<span class="dashicons dashicons-arrow-up-alt2"></span> Send');
				}.bind(this),
				error: function() {
					this.hideTypingIndicator();
					this.addMessageToUI('system', 'Sorry, I couldn\'t send your message. Please check your connection and try again.');

					// Re-enable form
					$input.prop('disabled', false).focus();
					$sendButton.prop('disabled', false).html('<span class="dashicons dashicons-arrow-up-alt2"></span> Send');
				}.bind(this)
			});
		},

		/**
		 * S11: render the inline approval card and wire its buttons.
		 */
		renderApprovalCard: function(data) {
			var self = this;
			var approval = data.approval || {};
			var $messages = $('#chat-messages');
			var cfg = window.specfluxMacChat || {};

			var $card = $(
				'<div class="senroflux-approval-card">' +
					'<p><strong>' +
						((cfg.i18n && cfg.i18n.needsApproval) || 'Needs your approval:') +
					'</strong> <code>' + String(approval.verb || '') + '</code></p>' +
					'<p class="senroflux-card-actions">' +
						'<button type="button" class="button button-primary senroflux-decide" data-decision="approve">' +
							((cfg.i18n && cfg.i18n.approve) || 'Approve') + '</button> ' +
						'<button type="button" class="button senroflux-decide" data-decision="reject">' +
							((cfg.i18n && cfg.i18n.reject) || 'Reject') + '</button> ' +
						'<a href="' + String(approval.review_url || '#') + '" target="_blank" rel="noopener">' +
							((cfg.i18n && cfg.i18n.reviewInAgentSafety) || 'Review in Agent Safety') + '</a>' +
					'</p>' +
				'</div>'
			);

			$messages.append($card);
			$messages.scrollTop($messages[0].scrollHeight);

			$card.find('.senroflux-decide').on('click', function () {
				var decision = $(this).data('decision');
				$card.find('.senroflux-card-actions').html('<em>Sending…</em>');

				$.post(
					specfluxMacChat.ajaxUrl,
					{
						action: 'specflux_mac_run_decision',
						nonce: specfluxMacChat.nonce,
						run_id: data.run_id,
						step_count: data.step_count || 0,
						decision: decision
					}
				).done(function (resp) {
					if (resp.success && resp.data) {
						$card.remove();
						if (resp.data.messages && resp.data.messages.length) {
							resp.data.messages.forEach(function (msg) {
								if (msg.content) {
									self.addMessageToUI('assistant', msg.content);
								}
							});
						}
						if (resp.data.approval) {
							self.renderApprovalCard(resp.data);
						}
					} else {
						$card.find('.senroflux-card-actions').html('<em>Error</em>');
					}
				}).fail(function () {
					$card.find('.senroflux-card-actions').html('<em>Error</em>');
				});
			});
		},

		/**
		 * Add message to UI
		 */
		addMessageToUI: function(role, content, usage, toolMetadata, failedTools, toolCalls) {
			var $messages = $('#chat-messages');
			var self = this;

			var hasText = content !== null && content !== undefined && String(content).trim() !== '';
			var hasToolCalls = toolCalls && toolCalls.length > 0;
			var hasFailed = failedTools && failedTools.length > 0;

			// Don't render empty bubbles (e.g. a tool-only turn with no prose).
			if (!hasText && !hasToolCalls && !hasFailed) {
				return;
			}

			// Remove welcome message if present
			$messages.find('.welcome-message').remove();

			var avatarIcon, roleName;
			switch (role) {
				case 'user':
					avatarIcon = 'admin-users';
					roleName = 'You';
					break;
				case 'assistant':
					avatarIcon = 'superhero';
					roleName = 'AI Assistant';
					break;
				case 'error':
					avatarIcon = 'warning';
					roleName = 'AI Assistant';
					break;
				default:
					avatarIcon = 'warning';
					roleName = 'System';
			}

			var usageHTML = '';
			if (usage && role === 'assistant') {
				usageHTML = '<div class="message-usage">' +
					'<span class="dashicons dashicons-chart-bar"></span> ' +
					usage.input_tokens + ' in / ' + usage.output_tokens + ' out';

				// Add tool metadata if available
				if (toolMetadata) {
					var toolsText = toolMetadata.tools_sent + ' tool' + (toolMetadata.tools_sent !== 1 ? 's' : '');
					if (toolMetadata.filtered) {
						usageHTML += ' <span class="tools-filtered" title="Filtered from ' + toolMetadata.total_available + ' total tools">' +
							'<span class="dashicons dashicons-filter"></span> ' + toolsText +
							'</span>';
					} else {
						usageHTML += ' <span class="tools-all" title="All available tools sent">' +
							'<span class="dashicons dashicons-admin-tools"></span> ' + toolsText +
							'</span>';
					}
				}

				usageHTML += '</div>';
			}

			// Build tool-calls chip
			var toolCallsHTML = '';
			if (hasToolCalls) {
				var toolCount = toolCalls.length;
				toolCallsHTML = '<div class="tool-calls">' +
					'<span class="tool-calls-label">' +
						'<span class="dashicons dashicons-admin-tools"></span> Used ' +
						toolCount + ' tool' + (toolCount !== 1 ? 's' : '') +
					'</span><ul>';
				toolCalls.forEach(function(tc) {
					var shortName = tc && tc.name ? String(tc.name).split('/').pop() : 'tool';
					toolCallsHTML += '<li>' + $('<div>').text(shortName).html() + '</li>';
				});
				toolCallsHTML += '</ul></div>';
			}

			// Build failed tools HTML with retry buttons
			var failedToolsHTML = '';
			if (failedTools && failedTools.length > 0) {
				failedToolsHTML = '<div class="failed-tools">' +
					'<div class="failed-tools-header"><span class="dashicons dashicons-warning"></span> Failed Tools:</div>' +
					'<ul class="failed-tools-list">';
				failedTools.forEach(function(tool, index) {
					var toolShortName = tool.name.split('/').pop();
					failedToolsHTML += '<li class="failed-tool-item">' +
						'<span class="tool-name">' + toolShortName + '</span>' +
						'<span class="tool-error">' + tool.error + '</span>' +
						'<button class="retry-tool-btn" data-tool-index="' + index + '" ' +
							'data-tool-name="' + tool.name + '" ' +
							'data-tool-args=\'' + JSON.stringify(tool.arguments) + '\'>' +
							'<span class="dashicons dashicons-update"></span> Retry' +
						'</button>' +
					'</li>';
				});
				failedToolsHTML += '</ul></div>';
			}

			var textHTML = hasText ? '<div class="message-text">' + this.formatMessage(content) + '</div>' : '';

			var messageHTML = '<div class="message message-' + role + '">' +
				'<div class="message-avatar" role="img" aria-label="' + roleName + '">' +
					'<span class="dashicons dashicons-' + avatarIcon + '"></span>' +
				'</div>' +
				'<div class="message-content">' +
					textHTML +
					toolCallsHTML +
					failedToolsHTML +
					usageHTML +
					'<div class="message-time">Just now</div>' +
				'</div>' +
			'</div>';

			var $message = $(messageHTML);
			$messages.append($message);

			// Bind retry button click handlers
			$message.find('.retry-tool-btn').on('click', function() {
				var $btn = $(this);
				var toolName = $btn.data('tool-name');
				var toolArgs = $btn.data('tool-args');
				self.retryToolCall(toolName, toolArgs, $btn);
			});

			this.scrollToBottom();
		},

		/**
		 * Retry a failed tool call
		 */
		retryToolCall: function(toolName, toolArgs, $button) {
			var self = this;
			var originalHTML = $button.html();

			// Show loading state
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Retrying...');

			$.ajax({
				url: specfluxMacChat.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_retry_tool',
					nonce: specfluxMacChat.nonce,
					conversation_id: specfluxMacChat.conversationId,
					tool_name: toolName,
					tool_arguments: JSON.stringify(toolArgs)
				},
				success: function(response) {
					if (response.success && response.data) {
						// Replace the failed tool item with success message
						var $failedItem = $button.closest('.failed-tool-item');
						$failedItem.removeClass('failed-tool-item').addClass('retry-success-item');
						$failedItem.html(
							'<span class="dashicons dashicons-yes-alt"></span> ' +
							'<span class="tool-name">' + toolName.split('/').pop() + '</span> - Success!'
						);

						// Add the result as a new message
						self.addMessageToUI('assistant', response.data.content, null, null);
					} else {
						// Show error but keep retry button
						$button.prop('disabled', false).html(originalHTML);
						alert('Retry failed: ' + (response.data ? response.data.message : 'Unknown error'));
					}
				},
				error: function() {
					$button.prop('disabled', false).html(originalHTML);
					alert('Retry failed. Please check your connection and try again.');
				}
			});
		},

		/**
		 * Format message content with markdown-like formatting.
		 *
		 * Line-based parser so lists, ordered lists, and tables group
		 * correctly. All raw text is HTML-escaped before any tags are added.
		 */
		formatMessage: function(content) {
			if (content === null || content === undefined) {
				content = '';
			}

			var esc = function(s) { return $('<div>').text(s).html(); };

			// Inline formatting applied to already-escaped text.
			var inline = function(text) {
				text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
				text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
				text = text.replace(/__(.+?)__/g, '<strong>$1</strong>');
				text = text.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
				text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
				return text;
			};

			var isTableSep = function(s) { return s && s.indexOf('-') !== -1 && /^\s*\|?[\s:\-|]+\|?\s*$/.test(s); };
			var parseRow = function(r) { return r.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(function(c) { return c.trim(); }); };

			var lines = String(content).split('\n');
			var html = '';
			var i = 0;

			while (i < lines.length) {
				var line = lines[i];

				// Fenced code block.
				if (/^```/.test(line)) {
					var code = [];
					i++;
					while (i < lines.length && !/^```/.test(lines[i])) { code.push(lines[i]); i++; }
					i++;
					html += '<pre><code>' + esc(code.join('\n')) + '</code></pre>';
					continue;
				}

				// Headers.
				var hm = line.match(/^(#{1,3})\s+(.*)$/);
				if (hm) {
					var level = hm[1].length + 1;
					html += '<h' + level + '>' + inline(esc(hm[2])) + '</h' + level + '>';
					i++;
					continue;
				}

				// Pipe table (header row + separator row).
				if (line.indexOf('|') !== -1 && i + 1 < lines.length && isTableSep(lines[i + 1])) {
					var headers = parseRow(line);
					i += 2;
					var rows = [];
					while (i < lines.length && lines[i].indexOf('|') !== -1 && lines[i].trim() !== '') {
						rows.push(parseRow(lines[i]));
						i++;
					}
					var t = '<table class="chat-table"><thead><tr>';
					headers.forEach(function(h) { t += '<th>' + inline(esc(h)) + '</th>'; });
					t += '</tr></thead><tbody>';
					rows.forEach(function(row) {
						t += '<tr>';
						row.forEach(function(c) { t += '<td>' + inline(esc(c)) + '</td>'; });
						t += '</tr>';
					});
					html += t + '</tbody></table>';
					continue;
				}

				// Unordered list.
				if (/^\s*[-*]\s+/.test(line)) {
					var items = [];
					while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) {
						items.push(lines[i].replace(/^\s*[-*]\s+/, ''));
						i++;
					}
					html += '<ul>' + items.map(function(it) { return '<li>' + inline(esc(it)) + '</li>'; }).join('') + '</ul>';
					continue;
				}

				// Ordered list.
				if (/^\s*\d+\.\s+/.test(line)) {
					var oitems = [];
					while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
						oitems.push(lines[i].replace(/^\s*\d+\.\s+/, ''));
						i++;
					}
					html += '<ol>' + oitems.map(function(it) { return '<li>' + inline(esc(it)) + '</li>'; }).join('') + '</ol>';
					continue;
				}

				// Blank line.
				if (line.trim() === '') { i++; continue; }

				// Paragraph: gather consecutive plain lines.
				var para = [];
				while (i < lines.length && lines[i].trim() !== '' &&
					!/^```/.test(lines[i]) && !/^#{1,3}\s+/.test(lines[i]) &&
					!/^\s*[-*]\s+/.test(lines[i]) && !/^\s*\d+\.\s+/.test(lines[i]) &&
					!(lines[i].indexOf('|') !== -1 && i + 1 < lines.length && isTableSep(lines[i + 1]))) {
					para.push(lines[i]);
					i++;
				}
				if (para.length) {
					html += '<p>' + inline(esc(para.join('\n'))).replace(/\n/g, '<br>') + '</p>';
				}
			}

			return '<div class="formatted-content">' + html + '</div>';
		},

		/**
		 * Show typing indicator with optional status message
		 *
		 * @param {string} status Optional status message (e.g., 'thinking', 'executing_tools')
		 */
		showTypingIndicator: function(status) {
			var $messages = $('#chat-messages');
			var statusText = '';
			var statusClass = '';

			switch (status) {
				case 'executing_tools':
					statusText = 'Executing tools...';
					statusClass = 'status-tools';
					break;
				case 'processing':
					statusText = 'Processing results...';
					statusClass = 'status-processing';
					break;
				default:
					statusText = 'Thinking...';
					statusClass = 'status-thinking';
			}

			var typingHTML = '<div class="message message-assistant typing-indicator">' +
				'<div class="message-avatar" role="img" aria-label="AI Assistant">' +
					'<span class="dashicons dashicons-superhero"></span>' +
				'</div>' +
				'<div class="message-content">' +
					'<div class="message-loading">' +
						'<span></span><span></span><span></span>' +
					'</div>' +
					'<div class="message-status ' + statusClass + '">' + statusText + '</div>' +
				'</div>' +
			'</div>';

			$messages.append(typingHTML);
			this.scrollToBottom();
		},

		/**
		 * Update typing indicator status
		 *
		 * @param {string} status New status message
		 */
		updateTypingStatus: function(status) {
			var $indicator = $('#chat-messages').find('.typing-indicator');
			if ($indicator.length) {
				var statusText = '';
				var statusClass = '';

				switch (status) {
					case 'executing_tools':
						statusText = 'Executing tools...';
						statusClass = 'status-tools';
						break;
					case 'processing':
						statusText = 'Processing results...';
						statusClass = 'status-processing';
						break;
					default:
						statusText = 'Thinking...';
						statusClass = 'status-thinking';
				}

				$indicator.find('.message-status')
					.removeClass('status-thinking status-tools status-processing')
					.addClass(statusClass)
					.text(statusText);
			}
		},

		/**
		 * Hide typing indicator
		 */
		hideTypingIndicator: function() {
			$('#chat-messages').find('.typing-indicator').remove();
		},

		/**
		 * Scroll chat to bottom
		 */
		scrollToBottom: function() {
			var $messages = $('#chat-messages');
			if ($messages.length) {
				$messages.animate({
					scrollTop: $messages[0].scrollHeight
				}, 300);
			}
		},

		/**
		 * Auto-resize textarea
		 */
		autoResizeTextarea: function() {
			var $this = $(this);
			$this.css('height', 'auto');
			var newHeight = Math.min($this[0].scrollHeight, 200);
			$this.css('height', newHeight + 'px');
		},

		/**
		 * Update conversation title in sidebar
		 */
		updateConversationTitle: function(newTitle) {
			$('.conversation-item.active .conversation-title').text(newTitle);
		}
	};

	/**
	 * Initialize on document ready
	 */
	$(document).ready(function() {
		if ($('.specflux-marketing-analytics-chat').length) {
			ChatInterface.init();
		}
	});

})(jQuery);
