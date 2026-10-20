/**
 * Dashboard behaviour: refresh the cached headline numbers, and make the
 * "Ask about your data" box submit on Enter.
 *
 * @package Marketing_Analytics_MCP
 */

/* global jQuery, specfluxMacDashboard */
( function( $ ) {
	'use strict';

	$( function() {
		var $ask = $( '#smac-ask' );

		// Enter sends the question to the AI Assistant; Shift+Enter adds a line.
		$ask.on( 'keydown', function( e ) {
			if ( 13 === e.which && ! e.shiftKey && ! e.isComposing ) {
				e.preventDefault();
				$( '#smac-ask-form' ).trigger( 'submit' );
			}
		} );

		// Grow the box a little as the question gets longer (the kit caps the height).
		$ask.on( 'input', function() {
			this.style.height = 'auto';
			this.style.height = this.scrollHeight + 'px';
		} );

		// Refresh the cached headline numbers (the same call the WordPress dashboard widget makes).
		$( '#smac-refresh-stats' ).on( 'click', function() {
			var $btn = $( this );
			var label = $btn.text();
			var $status = $( '#smac-refresh-status' );

			if ( $btn.prop( 'disabled' ) ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( $btn.data( 'working' ) );
			$status.text( $btn.data( 'working' ) );

			$.post( specfluxMacDashboard.ajaxUrl, {
				action: 'specflux_mac_refresh_widget',
				nonce: specfluxMacDashboard.nonce
			} ).done( function( response ) {
				if ( response && response.success ) {
					window.location.reload();
					return;
				}
				$btn.prop( 'disabled', false ).text( label );
				$status.text( specfluxMacDashboard.failed );
			} ).fail( function() {
				$btn.prop( 'disabled', false ).text( label );
				$status.text( specfluxMacDashboard.failed );
			} );
		} );
	} );
} )( jQuery );
