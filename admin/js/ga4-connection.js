jQuery(document).ready(function($) {
	'use strict';

	// Load GA4 properties
	function loadGA4Properties() {
		$('#ga4_property').html('<option value="">' + macGA4Connection.strings.loading + '</option>');
		$('#ga4-property-error').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'marketing_analytics_mcp_get_ga4_properties',
				nonce: macGA4Connection.nonce
			},
			success: function(response) {
				if (response.success && response.data.properties) {
					var html = '<option value="">' + macGA4Connection.strings.selectProperty + '</option>';
					$.each(response.data.properties, function(i, prop) {
						var selected = prop.name === macGA4Connection.savedPropertyId ? ' selected' : '';
						html += '<option value="' + prop.name + '"' + selected + '>' + prop.displayName + ' (' + prop.name + ')</option>';
					});
					$('#ga4_property').html(html);
				} else {
					$('#ga4_property').html('<option value="">' + macGA4Connection.strings.loadFailed + '</option>');
					$('#ga4-property-error').text(response.data && response.data.message ? response.data.message : macGA4Connection.strings.loadFailed).show();
				}
			},
			error: function() {
				$('#ga4_property').html('<option value="">' + macGA4Connection.strings.loadError + '</option>');
				$('#ga4-property-error').text(macGA4Connection.strings.networkError).show();
			}
		});
	}

	// Load properties on page load
	loadGA4Properties();

	// Save property selection
	$('#save-ga4-property').on('click', function() {
		var propertyId = $('#ga4_property').val();
		if (!propertyId) {
			alert(macGA4Connection.strings.selectFirst);
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text(macGA4Connection.strings.saving);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'marketing_analytics_mcp_save_ga4_property',
				nonce: macGA4Connection.nonce,
				property_id: propertyId
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Failed to save property');
					$btn.prop('disabled', false).text(macGA4Connection.strings.saveButton);
				}
			},
			error: function() {
				alert(macGA4Connection.strings.networkError);
				$btn.prop('disabled', false).text(macGA4Connection.strings.saveButton);
			}
		});
	});
});
