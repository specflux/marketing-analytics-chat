jQuery(document).ready(function($) {
	'use strict';

	// Load GSC properties
	function loadGSCProperties() {
		$('#gsc_site_url').html('<option value="">' + specfluxMacGSCConnection.strings.loading + '</option>');
		$('#gsc-property-error').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'specflux_mac_get_gsc_properties',
				nonce: specfluxMacGSCConnection.nonce
			},
			success: function(response) {
				if (response.success && response.data.properties) {
					var html = '<option value="">' + specfluxMacGSCConnection.strings.selectProperty + '</option>';
					$.each(response.data.properties, function(i, prop) {
						var selected = prop.siteUrl === specfluxMacGSCConnection.savedSiteUrl ? ' selected' : '';
						html += '<option value="' + prop.siteUrl + '"' + selected + '>' + prop.siteUrl + '</option>';
					});
					$('#gsc_site_url').html(html);
				} else {
					$('#gsc_site_url').html('<option value="">' + specfluxMacGSCConnection.strings.loadFailed + '</option>');
					$('#gsc-property-error').text(response.data && response.data.message ? response.data.message : specfluxMacGSCConnection.strings.loadFailed).show();
				}
			},
			error: function() {
				$('#gsc_site_url').html('<option value="">' + specfluxMacGSCConnection.strings.loadError + '</option>');
				$('#gsc-property-error').text(specfluxMacGSCConnection.strings.networkError).show();
			}
		});
	}

	// Load properties on page load
	loadGSCProperties();

	// Save property selection
	$('#save-gsc-property').on('click', function() {
		var siteUrl = $('#gsc_site_url').val();
		if (!siteUrl) {
			alert(specfluxMacGSCConnection.strings.selectFirst);
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text(specfluxMacGSCConnection.strings.saving);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'specflux_mac_save_gsc_property',
				nonce: specfluxMacGSCConnection.nonce,
				site_url: siteUrl
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					alert(response.data && response.data.message ? response.data.message : 'Failed to save property');
					$btn.prop('disabled', false).text(specfluxMacGSCConnection.strings.saveButton);
				}
			},
			error: function() {
				alert(specfluxMacGSCConnection.strings.networkError);
				$btn.prop('disabled', false).text(specfluxMacGSCConnection.strings.saveButton);
			}
		});
	});
});
