/**
 * AI Chat Interface JavaScript
 *
 * Renders on the Admin UI kit (aui-* classes). Query parameter: ?prompt=
 * prefills the message box (never sends); with no open conversation it opens
 * a new one and carries the prompt over.
 *
 * @package Marketing_Analytics_MCP
 */

(function($) {
	'use strict';

	var cfg = window.specfluxMacChat || {};
	var i18n = cfg.i18n || {};

	/**
	 * Look up a localized string (strings come from PHP via wp_localize_script).
	 * Replaces %s / %d (or ordered %1$d) placeholders with the extra arguments.
	 */
	function t(key) {
		var str = i18n[key] !== undefined ? i18n[key] : key;
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return str.replace(/%(?:(\d+)\$)?[sd]/g, function(match, pos) {
			var idx = pos ? parseInt(pos, 10) - 1 : next++;
			return idx < args.length ? String(args[idx]) : '';
		});
	}

	/** Escape text for HTML. */
	function esc(s) {
		return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
	}

	/** Kit icon from the page sprite. */
	function icon(name, extra) {
		return '<svg class="aui-i' + (extra ? ' ' + extra : '') + '" aria-hidden="true" focusable="false"><use href="#aui-i-' + name + '"/></svg>';
	}

	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	var ChatInterface = {

		init: function() {
			this.bindEvents();
			this.renderStoredMessages();
			this.scrollToBottom();
			this.prefillFromQuery();
		},

		/**
		 * Prefill (never send) the message box from a ?prompt= query param.
		 */
		prefillFromQuery: function() {
			if (typeof URLSearchParams === 'undefined') {
				return;
			}
			var prompt = new URLSearchParams(window.location.search).get('prompt');
			if (!prompt) {
				return;
			}
			prompt = prompt.substring(0, 500);

			if ($('#message-input').length) {
				$('#message-input').val(prompt).focus();
				return;
			}

			// No conversation is open, so there is no input to fill yet: open one and carry the prompt over.
			$.post(cfg.ajaxUrl, {
				action: 'specflux_mac_create_conversation',
				nonce: cfg.nonce,
				user_id: cfg.userId
			}).done(function(response) {
				if (response.success && response.data.conversation_id) {
					window.location.href = cfg.chatPageUrl + '&conversation_id=' + response.data.conversation_id + '&_wpnonce=' + cfg.conversationNonce + '&prompt=' + encodeURIComponent(prompt);
				}
			});
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

		bindEvents: function() {
			// Proposed-action cards (live and server-rendered) share delegated handlers.
			var self = this;
			$('#chat-messages').on('click', '.smac-action-apply', function() {
				self.sendActionDecision($(this).closest('.smac-action'), 'apply');
			});
			$('#chat-messages').on('click', '.smac-action-dismiss', function(e) {
				e.preventDefault();
				if (!$(this).prop('disabled')) {
					self.sendActionDecision($(this).closest('.smac-action'), 'dismiss');
				}
			});

			$('#new-conversation, #new-conversation-main').on('click', this.createNewConversation.bind(this));
			$(document).on('click', '.delete-conversation', this.deleteConversation.bind(this));
			$('.suggested-prompt').on('click', this.fillSuggestedPrompt.bind(this));
			$('#chat-form').on('submit', this.sendMessage.bind(this));
			$('#message-input').on('input', this.autoResizeTextarea);

			// Keyboard shortcuts: Enter sends, Shift+Enter inserts a new line.
			$('#message-input').on('keydown', function(e) {
				if (e.keyCode === 13 && !e.shiftKey && !e.isComposing) {
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

			var $button = $(e.currentTarget);
			var originalText = $button.html();
			$button.prop('disabled', true).html(icon('clock') + ' ' + esc(t('creating')));

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_create_conversation',
					nonce: cfg.nonce,
					user_id: cfg.userId
				},
				success: function(response) {
					if (response.success && response.data.conversation_id) {
						window.location.href = cfg.chatPageUrl + '&conversation_id=' + response.data.conversation_id + '&_wpnonce=' + cfg.conversationNonce;
					} else {
						alert(t('createFailed'));
						$button.prop('disabled', false).html(originalText);
					}
				},
				error: function() {
					alert(t('createFailed'));
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

			if (!confirm(t('deleteConfirm', conversationTitle))) {
				return;
			}

			var originalHTML = $button.html();
			$button.prop('disabled', true).html(icon('clock'));

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_delete_conversation',
					nonce: cfg.nonce,
					conversation_id: conversationId
				},
				success: function(response) {
					if (response.success) {
						// If this was the active conversation, go back to the chat page without one.
						if (parseInt(cfg.conversationId, 10) === parseInt(conversationId, 10)) {
							window.location.href = cfg.chatPageUrl;
						} else {
							$button.closest('.conversation-item').fadeOut(reducedMotion ? 0 : 300, function() {
								$(this).remove();
							});
						}
					} else {
						alert((response.data && response.data.message) || t('deleteFailed'));
						$button.prop('disabled', false).html(originalHTML);
					}
				},
				error: function() {
					alert(t('deleteFailed'));
					$button.prop('disabled', false).html(originalHTML);
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

		sendButtonHTML: function() {
			return icon('send') + ' ' + esc(t('send'));
		},

		/**
		 * Send message
		 */
		sendMessage: function(e) {
			e.preventDefault();

			var $input = $('#message-input');
			var $sendButton = $('#send-button');
			var message = $input.val().trim();

			if (!message) {
				return;
			}

			$input.prop('disabled', true);
			$sendButton.prop('disabled', true).html(icon('clock') + ' ' + esc(t('sending')));

			// Hide suggested prompts after first message
			$('#suggested-prompts').hide();

			this.addMessageToUI('user', message);
			$input.val('').css('height', '');

			this.showTypingIndicator();

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_send_message',
					nonce: cfg.nonce,
					conversation_id: cfg.conversationId,
					message: message
				},
				success: function(response) {
					this.hideTypingIndicator();

					if (response.success && response.data) {
						// Handle multiple messages if available (tool calls generate multiple responses)
						if (response.data.messages && response.data.messages.length > 0) {
							var self = this;
							response.data.messages.forEach(function(msg, index) {
								if (msg.tool_calls && msg.tool_calls.length > 0) {
									self.addMessageToUI('assistant', msg.content || '', msg.usage || null, response.data.tool_metadata, null, msg.tool_calls, msg.actions);
								} else if (msg.content) {
									// Use 'error' role if is_error flag is set for distinct styling
									var msgRole = msg.is_error ? 'error' : 'assistant';
									// Pass failed_tools for retry buttons on error messages
									var failedTools = msg.is_error ? response.data.failed_tools : null;
									self.addMessageToUI(msgRole, msg.content, msg.usage, index === 0 ? response.data.tool_metadata : null, failedTools, null, msg.actions);
								}
							});
						} else if (response.data.content) {
							this.addMessageToUI('assistant', response.data.content, response.data.usage, response.data.tool_metadata);
						}

						// Update conversation title if this was the first message
						if (response.data.new_title) {
							this.updateConversationTitle(response.data.new_title);
						}
					} else {
						this.addMessageToUI('system', t('errorGeneric'));
					}

					$input.prop('disabled', false).focus();
					$sendButton.prop('disabled', false).html(this.sendButtonHTML());
				}.bind(this),
				error: function(xhr) {
					this.hideTypingIndicator();
					// A gateway timeout means the web server stopped waiting, but PHP keeps
					// running and saves the answer, so reload the transcript instead of
					// telling the user to resend (which would ask the question twice).
					if (xhr && (xhr.status === 502 || xhr.status === 503 || xhr.status === 504)) {
						this.addMessageToUI('system', t('slowAnswer'));
						setTimeout(function() { window.location.reload(); }, 30000);
					} else {
						this.addMessageToUI('system', t('sendFailed'));
					}

					$input.prop('disabled', false).focus();
					$sendButton.prop('disabled', false).html(this.sendButtonHTML());
				}.bind(this)
			});
		},

		/**
		 * Reduce a tool identifier to its short ability name.
		 *
		 * Mirrors Chat_Ajax_Handler::normalize_ability_name() in
		 * includes/chat/class-chat-ajax-handler.php, which is the source of
		 * truth for this rule. Names arrive either namespaced
		 * ('marketing-analytics/get-ga4-metrics') or in core's sanitized form
		 * ('wpab__marketing-analytics__get-ga4-metrics'); both reduce to the
		 * final segment ('get-ga4-metrics').
		 */
		normalizeAbilityName: function(name) {
			var shortName = String(name);

			if (shortName.indexOf('__') !== -1) {
				shortName = shortName.split('__').pop();
			}

			if (shortName.indexOf('/') !== -1) {
				shortName = shortName.split('/').pop();
			}

			return shortName;
		},

		/**
		 * Add message to UI
		 */
		addMessageToUI: function(role, content, usage, toolMetadata, failedTools, toolCalls, actions) {
			var $messages = $('#chat-messages');
			var self = this;

			var hasText = content !== null && content !== undefined && String(content).trim() !== '';
			var hasToolCalls = toolCalls && toolCalls.length > 0;
			var hasFailed = failedTools && failedTools.length > 0;

			// Don't render empty bubbles (e.g. a tool-only turn with no prose).
			if (!hasText && !hasToolCalls && !hasFailed) {
				return;
			}

			$messages.find('.welcome-message').remove();

			var textHTML = hasText ? '<div class="message-text">' + this.formatMessage(content) + '</div>' : '';
			var timeHTML = '<div class="message-time">' + esc(t('justNow')) + '</div>';

			// User: right-aligned bubble, no avatar.
			if (role === 'user') {
				$messages.append(
					'<div class="message message-user aui-msg aui-msg--user"><div class="smac-user-wrap">' +
						'<div class="aui-bubble">' + textHTML + '</div>' + timeHTML +
					'</div></div>'
				);
				this.scrollToBottom();
				return;
			}

			// System notices: full-width kit notice.
			if (role === 'system') {
				$messages.append(
					'<div class="message message-system aui-notice aui-notice--warning">' + icon('warning') +
						'<div>' + textHTML + '</div></div>'
				);
				this.scrollToBottom();
				return;
			}

			var usageHTML = '';
			if (usage && role === 'assistant') {
				usageHTML = '<div class="message-usage aui-mono aui-muted">' +
					esc(t('tokens', usage.input_tokens, usage.output_tokens));

				if (toolMetadata) {
					var toolsText = toolMetadata.tools_sent === 1 ? t('toolOne') : t('toolMany', toolMetadata.tools_sent);
					if (toolMetadata.filtered) {
						usageHTML += ' <span class="tools-filtered aui-chip" title="' + esc(t('filteredFrom', toolMetadata.total_available)) + '">' + esc(toolsText) + '</span>';
					} else {
						usageHTML += ' <span class="tools-all aui-chip" title="' + esc(t('allToolsSent')) + '">' + esc(toolsText) + '</span>';
					}
				}

				usageHTML += '</div>';
			}

			var toolCallsHTML = '';
			if (hasToolCalls) {
				var toolCount = toolCalls.length;
				toolCallsHTML = '<div class="tool-calls"><span class="tool-calls-label">' +
					esc(toolCount === 1 ? t('usedOne') : t('usedMany', toolCount)) +
					'</span><ul>';
				toolCalls.forEach(function(tc) {
					var shortName = tc && tc.name ? self.normalizeAbilityName(tc.name) : 'tool';
					toolCallsHTML += '<li class="aui-chip">' + esc(shortName) + '</li>';
				});
				toolCallsHTML += '</ul></div>';
			}

			// Failed tools with retry buttons, as a kit result block.
			var failedToolsHTML = '';
			if (hasFailed) {
				failedToolsHTML = '<div class="failed-tools aui-result">' +
					'<div class="failed-tools-header aui-result-h">' + icon('warning') + esc(t('failedTools')) +
					'<span class="aui-badge aui-badge--bad aui-end">' + esc(t('failedBadge')) + '</span></div>' +
					'<ul class="failed-tools-list aui-result-b">';
				failedTools.forEach(function(tool, index) {
					failedToolsHTML += '<li class="failed-tool-item">' +
						'<span class="tool-name aui-mono">' + esc(self.normalizeAbilityName(tool.name)) + '</span>' +
						'<span class="tool-error">' + esc(tool.error) + '</span>' +
						'<button type="button" class="retry-tool-btn aui-btn aui-btn--secondary aui-btn--sm" data-tool-index="' + index + '" ' +
							'data-tool-name="' + esc(tool.name) + '" ' +
							'data-tool-args="' + esc(JSON.stringify(tool.arguments)) + '">' +
							icon('undo') + esc(t('retry')) +
						'</button>' +
					'</li>';
				});
				failedToolsHTML += '</ul></div>';
			}

			var $actionCards = $();
			if (role === 'assistant' && actions && actions.length > 0) {
				actions.forEach(function(action) {
					$actionCards = $actionCards.add(self.buildActionCard(action));
				});
			}

			var isError = role === 'error';
			var avatarHTML = '<span class="aui-avatar message-avatar' + (isError ? ' smac-avatar--bad' : '') + '" role="img" aria-label="' + esc(t('assistant')) + '">' +
				icon(isError ? 'warning' : 'robot') + '</span>';
			var bodyText = isError && hasText ?
				'<div class="aui-notice aui-notice--error">' + icon('warning') + '<div>' + textHTML + '</div></div>' : textHTML;

			var $message = $(
				'<div class="message message-' + esc(role) + ' aui-msg aui-msg--agent">' + avatarHTML +
				'<div class="message-content aui-msg-body">' +
					bodyText + toolCallsHTML + failedToolsHTML + usageHTML + timeHTML +
				'</div></div>'
			);
			$message.find('.message-time').before($actionCards);
			$messages.append($message);

			// Bind retry button click handlers
			$message.find('.retry-tool-btn').on('click', function() {
				var $btn = $(this);
				var toolName = $btn.attr('data-tool-name');
				var toolArgs;
				try {
					toolArgs = JSON.parse($btn.attr('data-tool-args'));
				} catch (err) {
					toolArgs = {};
				}
				self.retryToolCall(toolName, toolArgs, $btn);
			});

			this.scrollToBottom();
		},

		/**
		 * Build the card for a proposed action. All text goes in via .text().
		 */
		buildActionCard: function(action) {
			var $card = $('<div class="smac-action aui-approval-mini smac-action--pending"></div>').attr('data-action-id', String(action.id));
			$card.append(
				$('<div class="aui-result-h"></div>')
					.append(icon('clock'))
					.append(document.createTextNode(t('proposedChange')))
					.append($('<span class="smac-action-badge aui-badge aui-badge--warn aui-end"></span>').text(t('needsApproval')))
			);
			$card.append(
				$('<div class="aui-result-b"></div>')
					.append($('<div class="smac-action-summary"></div>').text(action.summary || ''))
					.append($('<div class="smac-action-status" role="status"></div>'))
			);
			$card.append(
				$('<div class="aui-result-f smac-action-buttons"></div>')
					.append($('<button type="button" class="aui-btn aui-btn--primary aui-btn--sm smac-action-apply"></button>').text(action.label || t('apply')))
					.append($('<button type="button" class="aui-btn aui-btn--secondary aui-btn--sm smac-action-dismiss"></button>').text(t('dismiss')))
			);
			return $card;
		},

		/**
		 * Apply or dismiss a proposed action; show the outcome inline.
		 */
		sendActionDecision: function($card, decision) {
			var $buttons = $card.find('.smac-action-apply, .smac-action-dismiss');
			var $status = $card.find('.smac-action-status');

			if ($card.data('busy')) {
				return;
			}
			$card.data('busy', true);
			$buttons.prop('disabled', true).attr('aria-disabled', 'true');
			$status.removeClass('smac-action-status--error').text(decision === 'apply' ? t('applying') : t('dismissing'));

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: decision === 'apply' ? 'specflux_mac_apply_chat_action' : 'specflux_mac_dismiss_chat_action',
					nonce: cfg.nonce,
					action_id: $card.attr('data-action-id')
				}
			}).done(function(response) {
				var data = response && response.data ? response.data : {};
				var summary = $card.find('.smac-action-summary').text();
				if (response && response.success) {
					var label = data.status === 'dismissed' ? t('dismissed') : t('applied');
					$card.removeClass('smac-action--pending').addClass('smac-action--' + data.status);
					$card.find('.smac-action-badge')
						.text(label)
						.toggleClass('aui-badge--ok', data.status !== 'dismissed')
						.removeClass('aui-badge--warn');
					$card.find('.smac-action-summary, .smac-action-buttons').remove();
					$status.text(label + ': ' + summary + (decision === 'apply' && data.message ? ' - ' + data.message : ''));
				} else {
					var terminal = data.status === 'applied' || data.status === 'dismissed' || data.status === 'expired';
					$status.addClass('smac-action-status--error').text(data.message || t('actionError'));
					if (terminal) {
						$card.find('.smac-action-buttons').remove();
					} else {
						$card.data('busy', false);
						$buttons.prop('disabled', false).removeAttr('aria-disabled');
					}
				}
			}).fail(function() {
				$status.addClass('smac-action-status--error').text(t('actionNetwork'));
				$card.data('busy', false);
				$buttons.prop('disabled', false).removeAttr('aria-disabled');
			});
		},

		/**
		 * Retry a failed tool call
		 */
		retryToolCall: function(toolName, toolArgs, $button) {
			var self = this;
			var originalHTML = $button.html();

			$button.prop('disabled', true).html(icon('clock') + esc(t('retrying')));

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_retry_tool',
					nonce: cfg.nonce,
					conversation_id: cfg.conversationId,
					tool_name: toolName,
					tool_arguments: JSON.stringify(toolArgs)
				},
				success: function(response) {
					if (response.success && response.data) {
						var $failedItem = $button.closest('.failed-tool-item');
						$failedItem.removeClass('failed-tool-item').addClass('retry-success-item');
						$failedItem.html(
							'<span class="aui-badge aui-badge--ok">' + icon('check') + esc(t('retrySuccess')) + '</span> ' +
							'<span class="tool-name aui-mono">' + esc(self.normalizeAbilityName(toolName)) + '</span>'
						);

						self.addMessageToUI('assistant', response.data.content, null, null);
					} else {
						$button.prop('disabled', false).html(originalHTML);
						alert(t('retryFailed', response.data && response.data.message ? response.data.message : t('unknownError')));
					}
				},
				error: function() {
					$button.prop('disabled', false).html(originalHTML);
					alert(t('retryFailedNetwork'));
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

		typingStatus: function(status) {
			switch (status) {
				case 'executing_tools':
					return { text: t('executingTools'), cls: 'status-tools' };
				case 'processing':
					return { text: t('processing'), cls: 'status-processing' };
				default:
					return { text: t('thinking'), cls: 'status-thinking' };
			}
		},

		/**
		 * Show typing indicator with optional status message
		 *
		 * @param {string} status Optional status (e.g., 'thinking', 'executing_tools')
		 */
		showTypingIndicator: function(status) {
			var st = this.typingStatus(status);

			var typingHTML = '<div class="message message-assistant aui-msg aui-msg--agent typing-indicator">' +
				'<span class="aui-avatar message-avatar" role="img" aria-label="' + esc(t('assistant')) + '">' + icon('robot') + '</span>' +
				'<div class="message-content aui-msg-body">' +
					'<div class="message-loading" aria-hidden="true"><span></span><span></span><span></span></div>' +
					'<div class="message-status aui-muted ' + st.cls + '" role="status">' + esc(st.text) + '</div>' +
				'</div>' +
			'</div>';

			$('#chat-messages').append(typingHTML);
			this.scrollToBottom();
		},

		/**
		 * Update typing indicator status
		 */
		updateTypingStatus: function(status) {
			var $indicator = $('#chat-messages').find('.typing-indicator');
			if ($indicator.length) {
				var st = this.typingStatus(status);
				$indicator.find('.message-status')
					.removeClass('status-thinking status-tools status-processing')
					.addClass(st.cls)
					.text(st.text);
			}
		},

		hideTypingIndicator: function() {
			$('#chat-messages').find('.typing-indicator').remove();
		},

		/**
		 * Scroll chat to bottom
		 */
		scrollToBottom: function() {
			var $messages = $('#chat-messages');
			if (!$messages.length) {
				return;
			}
			if (reducedMotion) {
				$messages.scrollTop($messages[0].scrollHeight);
			} else {
				$messages.stop(true).animate({ scrollTop: $messages[0].scrollHeight }, 300);
			}
		},

		/**
		 * Auto-resize textarea
		 */
		autoResizeTextarea: function() {
			var $this = $(this);
			$this.css('height', 'auto');
			$this.css('height', Math.min($this[0].scrollHeight, 160) + 'px');
		},

		/**
		 * Update conversation title in the list
		 */
		updateConversationTitle: function(newTitle) {
			$('.conversation-item.active .conversation-title').text(newTitle);
		}
	};

	$(document).ready(function() {
		if ($('.specflux-marketing-analytics-chat').length) {
			ChatInterface.init();
		}
	});

})(jQuery);
