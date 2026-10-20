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
			$result.removeClass('is-ok is-bad').text(specfluxMacWeeklySummary.sending);

			$.post(specfluxMacAdmin.ajaxUrl, {
				action: 'specflux_mac_send_test_summary',
				nonce: specfluxMacAdmin.nonce
			}).done(function(response) {
				var message = response && response.data && response.data.message ? response.data.message : specfluxMacWeeklySummary.failed;
				$result.removeClass('is-ok is-bad').addClass(response && response.success ? 'is-ok' : 'is-bad').text(message);
			}).fail(function() {
				$result.removeClass('is-ok').addClass('is-bad').text(specfluxMacWeeklySummary.failed);
			}).always(function() {
				$button.prop('disabled', false);
			});
		});
	});
})(jQuery);
