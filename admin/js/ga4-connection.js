jQuery(document).ready(function($) {
	'use strict';

	// Load GA4 properties
	function loadGA4Properties() {
		$('#ga4_property').html('<option value="">' + specfluxMacGA4Connection.strings.loading + '</option>');
		$('#ga4-property-error').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'specflux_mac_get_ga4_properties',
				nonce: specfluxMacGA4Connection.nonce
			},
			success: function(response) {
				if (response.success && response.data.properties) {
					var html = '<option value="">' + specfluxMacGA4Connection.strings.selectProperty + '</option>';
					$.each(response.data.properties, function(i, prop) {
						var selected = prop.name === specfluxMacGA4Connection.savedPropertyId ? ' selected' : '';
						html += '<option value="' + prop.name + '"' + selected + '>' + prop.displayName + ' (' + prop.name + ')</option>';
					});
					$('#ga4_property').html(html);
				} else {
					$('#ga4_property').html('<option value="">' + specfluxMacGA4Connection.strings.loadFailed + '</option>');
					$('#ga4-property-error').text(response.data && response.data.message ? response.data.message : specfluxMacGA4Connection.strings.loadFailed).show();
				}
			},
			error: function() {
				$('#ga4_property').html('<option value="">' + specfluxMacGA4Connection.strings.loadError + '</option>');
				$('#ga4-property-error').text(specfluxMacGA4Connection.strings.networkError).show();
			}
		});
	}

	// Load properties on page load
	loadGA4Properties();

	// Save property selection
	$('#save-ga4-property').on('click', function() {
		var propertyId = $('#ga4_property').val();
		if (!propertyId) {
			alert(specfluxMacGA4Connection.strings.selectFirst);
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text(specfluxMacGA4Connection.strings.saving);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'specflux_mac_save_ga4_property',
				nonce: specfluxMacGA4Connection.nonce,
				property_id: propertyId
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Failed to save property');
					$btn.prop('disabled', false).text(specfluxMacGA4Connection.strings.saveButton);
				}
			},
			error: function() {
				alert(specfluxMacGA4Connection.strings.networkError);
				$btn.prop('disabled', false).text(specfluxMacGA4Connection.strings.saveButton);
			}
		});
	});
});
