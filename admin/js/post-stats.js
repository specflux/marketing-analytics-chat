/**
 * Fills the Analytics column on the Posts/Pages list with one AJAX request.
 */
(function($) {
	'use strict';

	$(function() {
		var cfg = window.specfluxMacPostStats;
		var $cells = $('.smac-post-stats--loading');

		if (!cfg || !$cells.length) {
			return;
		}

		var ids = $cells.map(function() {
			return $(this).data('post-id');
		}).get();

		function fail() {
			$cells.removeClass('smac-post-stats--loading').addClass('smac-post-stats--error')
				.attr('title', cfg.errorTitle).text('—');
		}

		$.post(cfg.ajaxUrl, {
			action: cfg.action,
			nonce: cfg.nonce,
			post_ids: ids
		}).done(function(response) {
			if (!response || !response.success || !response.data || !response.data.cells) {
				fail();
				return;
			}
			$cells.each(function() {
				var $cell = $(this);
				var html = response.data.cells[$cell.data('post-id')];
				if (html) {
					// Markup is escaped server-side by Post_Stats::get_cell_html().
					$cell.replaceWith(html);
				} else {
					$cell.removeClass('smac-post-stats--loading').text('—');
				}
			});
		}).fail(fail);
	});
})(jQuery);
