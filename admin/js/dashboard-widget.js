jQuery(document).ready(function($) {
	'use strict';

	$('.marketing-analytics-refresh-widget').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).find('.dashicons').addClass('spin');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'marketing_analytics_mcp_refresh_widget',
				nonce: macDashboardWidget.nonce
			},
			success: function() {
				location.reload();
			},
			error: function() {
				$btn.prop('disabled', false).find('.dashicons').removeClass('spin');
			}
		});
	});
});
