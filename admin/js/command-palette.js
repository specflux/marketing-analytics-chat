/**
 * Core Command Palette integration.
 *
 * Registers plugin commands with the admin-wide Command Palette (WP 6.9+).
 * Written in vanilla JS against wp.data — no build step, no JSX.
 */
(function() {
	'use strict';

	var settings = window.specfluxMacCommands;

	// The palette ships with core but can be absent (dequeued, or an older
	// WordPress). Bail silently rather than throwing on every admin page.
	if ( ! settings || ! window.wp || ! wp.commands || ! wp.data || ! wp.domReady ) {
		return;
	}

	/**
	 * Build an SVG icon component for the palette.
	 *
	 * The palette expects a React element, so this needs wp-element and
	 * wp-primitives. Returns undefined when either is unavailable; the
	 * palette renders the command without an icon in that case.
	 *
	 * @param {string} path The SVG path data (24x24 viewBox).
	 * @return {Object|undefined} Icon element or undefined.
	 */
	function buildIcon( path ) {
		if ( ! wp.element || ! wp.primitives ) {
			return undefined;
		}

		return wp.element.createElement(
			wp.primitives.SVG,
			{ viewBox: '0 0 24 24', xmlns: 'http://www.w3.org/2000/svg' },
			wp.element.createElement( wp.primitives.Path, { d: path } )
		);
	}

	/**
	 * Announce a message to assistive technology and the browser console.
	 *
	 * @param {string} message Message to announce.
	 */
	function announce( message ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message );
		}
	}

	/**
	 * Render a dismissible admin notice at the top of the current page.
	 *
	 * The palette closes before the request resolves, so the result needs a
	 * surface of its own.
	 *
	 * @param {string} message Notice text.
	 * @param {string} type    Either 'success' or 'error'.
	 */
	function showNotice( message, type ) {
		var target = document.querySelector( '.wrap' );

		announce( message );

		if ( ! target ) {
			return;
		}

		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-' + type + ' is-dismissible smac-command-notice';
		notice.setAttribute( 'role', 'status' );

		var paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		notice.appendChild( paragraph );

		target.insertBefore( notice, target.firstChild );

		window.setTimeout( function() {
			if ( notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		}, 8000 );
	}

	/**
	 * Refresh cached analytics data via the widget refresh endpoint.
	 *
	 * @param {Object} args      Callback args from the palette.
	 * @param {Function} args.close Dismisses the palette.
	 */
	function refreshAnalytics( args ) {
		args.close();
		announce( settings.strings.refreshing );

		var body = new window.FormData();
		body.append( 'action', 'specflux_mac_refresh_widget' );
		body.append( 'nonce', settings.nonce );

		window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function( response ) {
			return response.json();
		} ).then( function( result ) {
			if ( result && result.success ) {
				showNotice( settings.strings.refreshed, 'success' );
			} else {
				showNotice( settings.strings.refreshFailed, 'error' );
			}
		} ).catch( function() {
			showNotice( settings.strings.refreshFailed, 'error' );
		} );
	}

	/**
	 * Navigate to a plugin page.
	 *
	 * @param {string} url Destination URL.
	 * @return {Function} Palette callback.
	 */
	function navigateTo( url ) {
		return function( args ) {
			args.close();
			window.location.href = url;
		};
	}

	/**
	 * Split a localized keyword string into the array the palette matches on.
	 *
	 * Core matches each command on `searchLabel ?? label`, so supplying a
	 * searchLabel makes the visible label itself unsearchable — typing the
	 * plugin's own name would miss commands whose searchLabel omitted it.
	 * `keywords` is additive, so the label stays matchable.
	 *
	 * @param {string} value Space-separated keyword string.
	 * @return {Array} Keyword list.
	 */
	function toKeywords( value ) {
		if ( ! value ) {
			return [];
		}

		return value.split( /\s+/ ).filter( Boolean );
	}

	var icons = {
		// Bar chart.
		dashboard: 'M5 19h14V5H5v14zM3 3h18v18H3V3zm4 12h2v2H7v-2zm4-4h2v6h-2v-6zm4-4h2v10h-2V7z',
		// Speech bubble.
		chat: 'M4 4h16v12H7.5L4 19.5V4zm2 2v9.1L6.7 14H18V6H6z',
		// Circular arrow.
		refresh: 'M12 4V1L8 5l4 4V6a6 6 0 11-6 6H4a8 8 0 108-8z'
	};

	wp.domReady( function() {
		var dispatch = wp.data.dispatch( wp.commands.store || 'core/commands' );

		if ( ! dispatch || ! dispatch.registerCommand ) {
			return;
		}

		dispatch.registerCommand( {
			name: 'specflux-mac/open-dashboard',
			label: settings.strings.openDashboard,
			keywords: toKeywords( settings.strings.openDashboardKeywords ),
			icon: buildIcon( icons.dashboard ),
			callback: navigateTo( settings.dashboardUrl )
		} );

		dispatch.registerCommand( {
			name: 'specflux-mac/ask-ai',
			label: settings.strings.askAi,
			keywords: toKeywords( settings.strings.askAiKeywords ),
			icon: buildIcon( icons.chat ),
			callback: navigateTo( settings.chatUrl )
		} );

		dispatch.registerCommand( {
			name: 'specflux-mac/refresh-analytics',
			label: settings.strings.refreshData,
			keywords: toKeywords( settings.strings.refreshDataKeywords ),
			icon: buildIcon( icons.refresh ),
			callback: refreshAnalytics
		} );
	} );
})();
