(function($) {
	'use strict';

	$(document).ready(function() {
		// Handle "All Tools" checkbox
		$('#tool_category_all').on('change', function() {
			var isChecked = $(this).is(':checked');
			var $otherCheckboxes = $('input[name="enabled_tool_categories[]"]').not('#tool_category_all');

			if (isChecked) {
				$otherCheckboxes.prop('checked', true).prop('disabled', true);
			} else {
				$otherCheckboxes.prop('disabled', false);
			}
		});

		// Trigger on page load to set initial state
		$('#tool_category_all').trigger('change');
	});
})(jQuery);
