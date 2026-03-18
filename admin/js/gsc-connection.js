jQuery(document).ready(function($) {
	'use strict';

	// Load GSC properties
	function loadGSCProperties() {
		$('#gsc_site_url').html('<option value="">' + macGSCConnection.strings.loading + '</option>');
		$('#gsc-property-error').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'marketing_analytics_mcp_get_gsc_properties',
				nonce: macGSCConnection.nonce
			},
			success: function(response) {
				if (response.success && response.data.properties) {
					var html = '<option value="">' + macGSCConnection.strings.selectProperty + '</option>';
					$.each(response.data.properties, function(i, prop) {
						var selected = prop.siteUrl === macGSCConnection.savedSiteUrl ? ' selected' : '';
						html += '<option value="' + prop.siteUrl + '"' + selected + '>' + prop.siteUrl + '</option>';
					});
					$('#gsc_site_url').html(html);
				} else {
					$('#gsc_site_url').html('<option value="">' + macGSCConnection.strings.loadFailed + '</option>');
					$('#gsc-property-error').text(response.data && response.data.message ? response.data.message : macGSCConnection.strings.loadFailed).show();
				}
			},
			error: function() {
				$('#gsc_site_url').html('<option value="">' + macGSCConnection.strings.loadError + '</option>');
				$('#gsc-property-error').text(macGSCConnection.strings.networkError).show();
			}
		});
	}

	// Load properties on page load
	loadGSCProperties();

	// Save property selection
	$('#save-gsc-property').on('click', function() {
		var siteUrl = $('#gsc_site_url').val();
		if (!siteUrl) {
			alert(macGSCConnection.strings.selectFirst);
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text(macGSCConnection.strings.saving);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'marketing_analytics_mcp_save_gsc_property',
				nonce: macGSCConnection.nonce,
				site_url: siteUrl
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Failed to save property');
					$btn.prop('disabled', false).text(macGSCConnection.strings.saveButton);
				}
			},
			error: function() {
				alert(macGSCConnection.strings.networkError);
				$btn.prop('disabled', false).text(macGSCConnection.strings.saveButton);
			}
		});
	});
});
