/**
 * Admin JavaScript for Specflux Marketing Analytics Chat
 *
 * @package Marketing_Analytics_MCP
 */

(function($) {
	'use strict';

	/**
	 * Copy MCP endpoint URL to clipboard
	 */
	function initCopyEndpoint() {
		$('.copy-endpoint').on('click', function(e) {
			e.preventDefault();

			var endpoint = $('.mcp-endpoint').text();

			// Use Clipboard API if available
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(endpoint).then(function() {
					showCopySuccess(e.target);
				}).catch(function() {
					fallbackCopyToClipboard(endpoint, e.target);
				});
			} else {
				fallbackCopyToClipboard(endpoint, e.target);
			}
		});
	}

	/**
	 * Fallback copy method for older browsers
	 */
	function fallbackCopyToClipboard(text, button) {
		var $temp = $('<textarea>');
		$('body').append($temp);
		$temp.val(text).select();

		try {
			document.execCommand('copy');
			showCopySuccess(button);
		} catch (err) {
			console.error('Copy failed:', err);
			$(button).text('Copy failed');
			setTimeout(function() {
				$(button).text('Copy URL');
			}, 2000);
		}

		$temp.remove();
	}

	/**
	 * Show copy success feedback
	 */
	function showCopySuccess(button) {
		var originalText = $(button).text();
		$(button).text('Copied!').addClass('button-primary');

		setTimeout(function() {
			$(button).text(originalText).removeClass('button-primary');
		}, 2000);
	}

	/**
	 * Test platform connection
	 */
	function initTestConnection() {
		$('.test-connection').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var platform = $button.data('platform');
			var originalText = $button.text();

			$button.text(specfluxMacAdmin.strings.testing)
				.prop('disabled', true)
				.addClass('testing-connection');

			// Get form data based on platform
			var data = {
				action: 'specflux_mac_test_connection',
				platform: platform,
				nonce: specfluxMacAdmin.nonce
			};

			if (platform === 'clarity') {
				data.api_token = $('#clarity_api_token').val();
				data.project_id = $('#clarity_project_id').val();
			}

			// Send AJAX request
			$.ajax({
				url: specfluxMacAdmin.ajaxUrl,
				type: 'POST',
				data: data,
				success: function(response) {
					if (response.success) {
						showNotice('success', specfluxMacAdmin.strings.success);
					} else {
						showNotice('error', response.data.message || specfluxMacAdmin.strings.error);
					}
				},
				error: function() {
					showNotice('error', specfluxMacAdmin.strings.error);
				},
				complete: function() {
					$button.text(originalText)
						.prop('disabled', false)
						.removeClass('testing-connection');
				}
			});
		});
	}

	/**
	 * Clear all caches
	 */
	function initClearCaches() {
		$('.clear-all-caches').on('click', function(e) {
			e.preventDefault();

			if (!confirm('Are you sure you want to clear all cached data?')) {
				return;
			}

			var $button = $(this);
			var originalText = $button.text();

			$button.text('Clearing...').prop('disabled', true);

			$.ajax({
				url: specfluxMacAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_clear_caches',
					nonce: specfluxMacAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						showNotice('success', 'All caches cleared successfully');
					} else {
						showNotice('error', 'Failed to clear caches');
					}
				},
				error: function() {
					showNotice('error', 'Failed to clear caches');
				},
				complete: function() {
					$button.text(originalText).prop('disabled', false);
				}
			});
		});
	}

	/**
	 * Show admin notice
	 */
	function showNotice(type, message) {
		var noticeClass = 'notice-' + type;
		var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

		$('.wrap > h1').after($notice);

		// Auto-dismiss after 5 seconds
		setTimeout(function() {
			$notice.fadeOut(function() {
				$(this).remove();
			});
		}, 5000);
	}

	/**
	 * Initialize GA4 property selector
	 */
	function initGA4PropertySelector() {
		// Load properties button
		$('#load-ga4-properties').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var $selector = $('#ga4_property_selector');
			var $loading = $('#ga4-property-loading');
			var $error = $('#ga4-property-error');
			var $saveButton = $('#save-ga4-property');

			// Show loading state
			$button.prop('disabled', true);
			$loading.show();
			$selector.hide();
			$error.hide();
			$saveButton.hide();

			// Fetch properties via AJAX
			$.ajax({
				url: specfluxMacAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_list_ga4_properties',
					nonce: specfluxMacAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						// Clear existing options except the first one
						$selector.find('option:not(:first)').remove();

						// Add properties to dropdown
						$.each(response.data.properties, function(index, property) {
							var optionText = property.display_name + ' (' + property.property_id + ')';
							if (property.account_name) {
								optionText += ' - ' + property.account_name;
							}
							$selector.append(
								$('<option></option>')
									.val(property.property_id)
									.text(optionText)
							);
						});

						// Show selector and save button
						$selector.show();
						$saveButton.show();
						$button.text('Reload Properties');
					} else {
						$error.find('p').text(response.data.message || 'Failed to load properties');
						$error.show();
					}
				},
				error: function() {
					$error.find('p').text('Failed to load properties. Please try again.');
					$error.show();
				},
				complete: function() {
					$loading.hide();
					$button.prop('disabled', false);
				}
			});
		});

		// Save property button
		$('#save-ga4-property').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var $selector = $('#ga4_property_selector');
			var propertyId = $selector.val();

			if (!propertyId) {
				showNotice('error', 'Please select a property');
				return;
			}

			var originalText = $button.text();
			$button.text('Saving...').prop('disabled', true);

			// Save property via AJAX
			$.ajax({
				url: specfluxMacAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_save_ga4_property',
					property_id: propertyId,
					nonce: specfluxMacAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						showNotice('success', response.data.message || 'Property saved successfully!');
						// Reload page after 1 second to show the updated current property
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						showNotice('error', response.data.message || 'Failed to save property');
					}
				},
				error: function() {
					showNotice('error', 'Failed to save property. Please try again.');
				},
				complete: function() {
					$button.text(originalText).prop('disabled', false);
				}
			});
		});
	}

	/**
	 * Initialize GSC site selector
	 */
	function initGSCSiteSelector() {
		// Load sites button
		$('#load-gsc-sites').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var $selector = $('#gsc_site_selector');
			var $loading = $('#gsc-site-loading');
			var $error = $('#gsc-site-error');
			var $saveButton = $('#save-gsc-site');

			// Show loading state
			$button.prop('disabled', true);
			$loading.show();
			$selector.hide();
			$error.hide();
			$saveButton.hide();

			// Fetch sites via AJAX
			$.ajax({
				url: specfluxMacAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_list_gsc_sites',
					nonce: specfluxMacAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						// Clear existing options except the first one
						$selector.find('option:not(:first)').remove();

						// Add sites to dropdown
						$.each(response.data.sites, function(index, site) {
							var optionText = site.site_url;
							if (site.permission_level) {
								optionText += ' (' + site.permission_level + ')';
							}
							$selector.append(
								$('<option></option>')
									.val(site.site_url)
									.text(optionText)
							);
						});

						// Show selector and save button
						$selector.show();
						$saveButton.show();
						$button.text('Reload Sites');
					} else {
						$error.find('p').text(response.data.message || 'Failed to load sites');
						$error.show();
					}
				},
				error: function() {
					$error.find('p').text('Failed to load sites. Please try again.');
					$error.show();
				},
				complete: function() {
					$loading.hide();
					$button.prop('disabled', false);
				}
			});
		});

		// Save site button
		$('#save-gsc-site').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var $selector = $('#gsc_site_selector');
			var siteUrl = $selector.val();

			if (!siteUrl) {
				showNotice('error', 'Please select a site');
				return;
			}

			var originalText = $button.text();
			$button.text('Saving...').prop('disabled', true);

			// Save site via AJAX
			$.ajax({
				url: specfluxMacAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'specflux_mac_save_gsc_site',
					site_url: siteUrl,
					nonce: specfluxMacAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						showNotice('success', response.data.message || 'Site saved successfully!');
						// Reload page after 1 second to show the updated current site
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						showNotice('error', response.data.message || 'Failed to save site');
					}
				},
				error: function() {
					showNotice('error', 'Failed to save site. Please try again.');
				},
				complete: function() {
					$button.text(originalText).prop('disabled', false);
				}
			});
		});
	}

	/**
	 * Validate Google OAuth credential format on blur
	 */
	function initCredentialValidation() {
		$('#google_client_id').on('blur', function() {
			var $input = $(this);
			var $status = $('#google_client_id_status');
			var value = $input.val().trim();

			if (!value) {
				$status.hide();
				return;
			}

			if (value.match(/\.apps\.googleusercontent\.com$/)) {
				$status.html('<span style="color: #00a32a;">&#10003;</span>').show();
			} else {
				$status.html('<span style="color: #d63638;">&#10007; Must end with .apps.googleusercontent.com</span>').show();
			}
		});

		$('#google_client_secret').on('blur', function() {
			var $input = $(this);
			var $status = $('#google_client_secret_status');
			var value = $input.val().trim();

			if (!value) {
				$status.hide();
				return;
			}

			if (value.indexOf('GOCSPX-') === 0) {
				$status.html('<span style="color: #00a32a;">&#10003;</span>').show();
			} else {
				$status.html('<span style="color: #d63638;">&#10007; Must start with GOCSPX-</span>').show();
			}
		});
	}

	/**
	 * "Connect with Google" buttons: fetch the consent URL, then redirect
	 */
	function initGoogleConnect() {
		$('.smac-google-connect').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var $status = $button.siblings('.smac-google-connect-status');

			if ($button.prop('disabled')) {
				return;
			}

			$button.prop('disabled', true);
			$status.removeClass('error').text(specfluxMacAdmin.strings.redirecting).show();

			$.post(specfluxMacAdmin.ajaxUrl, {
				action: 'specflux_mac_google_auth_url',
				nonce: specfluxMacAdmin.nonce,
				service: $button.data('service')
			}).done(function(response) {
				if (response.success && response.data && response.data.url) {
					window.location.href = response.data.url;
					return;
				}
				$button.prop('disabled', false);
				$status.addClass('error').text((response.data && response.data.message) || specfluxMacAdmin.strings.error);
			}).fail(function() {
				$button.prop('disabled', false);
				$status.addClass('error').text(specfluxMacAdmin.strings.error);
			});
		});
	}

	/**
	 * Strip one-time OAuth callback parameters from the address bar
	 *
	 * The callback is single-use, so a reload of the landing URL would be
	 * rejected as a replay. Rewrite the URL to the plain service tab instead.
	 */
	function cleanOAuthCallbackUrl() {
		if (!window.history || !window.history.replaceState || !window.URLSearchParams) {
			return;
		}

		var params = new URLSearchParams(window.location.search);
		if (!params.has('oauth_callback')) {
			return;
		}

		// The server already switched to the right tab; mirror it in the URL.
		var activeHref = $('.nav-tab-wrapper .nav-tab-active').attr('href') || '';
		var tabMatch   = activeHref.match(/[?&]tab=([a-z0-9_-]+)/);

		['oauth_callback', 'code', 'state', 'scope', 'handoff', 'nonce', 'service', 'smac_oauth_error'].forEach(function(key) {
			params.delete(key);
		});
		if (tabMatch) {
			params.set('tab', tabMatch[1]);
		}

		window.history.replaceState({}, document.title, window.location.pathname + '?' + params.toString());
	}

	/**
	 * Review notice: record the dismissal choice, then remove the notice
	 *
	 * "Maybe later" (and the native WP dismiss "x") snoozes the prompt;
	 * "Already did / don't ask again" hides it permanently.
	 */
	function initReviewNotice() {
		var $notice = $('.smac-review-notice');

		if (!$notice.length) {
			return;
		}

		function dismiss(mode) {
			$.post(specfluxMacAdmin.ajaxUrl, {
				action: 'specflux_mac_review_dismiss',
				nonce: specfluxMacAdmin.nonce,
				mode: mode
			});

			$notice.remove();
		}

		$notice.on('click', '.smac-review-later', function(e) {
			e.preventDefault();
			dismiss('later');
		});

		$notice.on('click', '.smac-review-never', function(e) {
			e.preventDefault();
			dismiss('never');
		});

		// The native WP dismiss button is injected after the notice renders.
		$notice.on('click', '.notice-dismiss', function() {
			dismiss('later');
		});
	}

	/**
	 * Initialize all functionality
	 */
	$(document).ready(function() {
		cleanOAuthCallbackUrl();
		initCopyEndpoint();
		initTestConnection();
		initClearCaches();
		initGA4PropertySelector();
		initGSCSiteSelector();
		initCredentialValidation();
		initGoogleConnect();
		initReviewNotice();
	});

})(jQuery);
