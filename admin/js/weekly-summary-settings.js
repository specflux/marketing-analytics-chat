(function($) {
	'use strict';

	$(document).ready(function() {
		var $button = $('#smac-send-test-summary');
		var $result = $('#smac-send-test-summary-result');

		if (!$button.length || typeof specfluxMacAdmin === 'undefined') {
			return;
		}

		$button.on('click', function() {
			$button.prop('disabled', true);
			$result.css('color', '').text(specfluxMacWeeklySummary.sending);

			$.post(specfluxMacAdmin.ajaxUrl, {
				action: 'specflux_mac_send_test_summary',
				nonce: specfluxMacAdmin.nonce
			}).done(function(response) {
				var message = response && response.data && response.data.message ? response.data.message : specfluxMacWeeklySummary.failed;
				$result.css('color', response && response.success ? '#00a32a' : '#d63638').text(message);
			}).fail(function() {
				$result.css('color', '#d63638').text(specfluxMacWeeklySummary.failed);
			}).always(function() {
				$button.prop('disabled', false);
			});
		});
	});
})(jQuery);
