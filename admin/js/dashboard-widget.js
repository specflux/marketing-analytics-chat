jQuery(document).ready(function($) {
	'use strict';

	var $headline = $('.smac-widget-headline');
	var $btn = $('.smac-refresh-widget');
	var running = false;

	// Rebuild the widget data (one request) and render the result in place.
	function refresh() {
		if (running) {
			return;
		}
		running = true;
		$btn.prop('disabled', true).find('.dashicons').addClass('spin');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'specflux_mac_refresh_widget',
				nonce: specfluxMacDashboardWidget.nonce
			}
		}).done(function(response) {
			if (response && response.success && response.data && response.data.headline_html) {
				$headline.removeAttr('data-autoload').html(response.data.headline_html);
			} else {
				showUnavailable();
			}
		}).fail(showUnavailable).always(function() {
			running = false;
			$btn.prop('disabled', false).find('.dashicons').removeClass('spin');
		});
	}

	function showUnavailable() {
		// Only replace the loading state; keep already rendered numbers on a failed manual refresh.
		if ($headline.find('.smac-widget-loading').length) {
			$headline.removeAttr('data-autoload').html(
				$('<p class="smac-widget-loading"></p>').text(specfluxMacDashboardWidget.unavailable || '—')
			);
		}
	}

	$btn.on('click', refresh);

	// Nothing cached: fetch once automatically.
	if ($headline.attr('data-autoload')) {
		refresh();
	}
});
