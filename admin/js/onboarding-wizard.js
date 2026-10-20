/**
 * Dashboard setup block: "Skip setup".
 *
 * Records the setup as dismissed (specflux_mac_onboarding_complete) and removes
 * the block. The block is removed locally even if the request fails, so the
 * button never feels dead.
 *
 * @package Marketing_Analytics_MCP
 */

/* global jQuery, specfluxMacOnboardingWizard */
( function( $ ) {
	'use strict';

	$( function() {
		var $block = $( '#smac-onboarding-wizard' );
		if ( ! $block.length ) {
			return;
		}

		$( '#smac-wizard-skip' ).on( 'click', function( e ) {
			e.preventDefault();

			var $button = $( this ).prop( 'disabled', true );

			function remove() {
				$block.slideUp( 200, function() {
					$block.remove();
					$( '#smac-ask' ).trigger( 'focus' );
				} );
			}

			$.post( specfluxMacOnboardingWizard.ajaxUrl, {
				action: 'specflux_mac_dismiss_wizard',
				nonce: specfluxMacOnboardingWizard.nonce
			} ).always( function() {
				$button.prop( 'disabled', false );
				remove();
			} );
		} );
	} );
} )( jQuery );
