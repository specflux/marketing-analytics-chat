/**
 * First-party source capture. Writes one anonymous cookie (no identifier).
 * Cookie lifetime is a 30 minute sliding window. Honors the WP Consent API.
 */
( function () {
	'use strict';

	var cfg = window.specfluxMacSource || {};
	var name = cfg.cookie || 'specflux_mac_src';
	var ttl = parseInt( cfg.ttl, 10 ) || 1800;
	var engines = [ 'google', 'bing', 'yahoo', 'duckduckgo', 'baidu', 'yandex', 'ecosia', 'brave', 'startpage' ];

	function allowed() {
		return typeof window.wp_has_consent !== 'function' || window.wp_has_consent( 'statistics' );
	}

	function read() {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? m[ 1 ] : '';
	}

	function write( value ) {
		var secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = name + '=' + value + '; Max-Age=' + ttl + '; Path=/; SameSite=Lax' + secure;
	}

	function clip( v ) {
		return String( v || '' ).substring( 0, 100 );
	}

	function detect() {
		var q = new URLSearchParams( window.location.search );
		var out = { source_type: 'typein', source: '(direct)', medium: '(none)', campaign: '', landing_page: window.location.pathname };
		var utm = q.get( 'utm_source' );
		if ( utm || q.get( 'gclid' ) ) {
			out.source_type = 'utm';
			out.source = clip( utm || 'google' );
			out.medium = clip( q.get( 'utm_medium' ) || ( utm ? '' : 'cpc' ) );
			out.campaign = clip( q.get( 'utm_campaign' ) );
			return out;
		}
		var host = '';
		try {
			host = document.referrer ? new URL( document.referrer ).hostname.replace( /^www\./, '' ) : '';
		} catch ( e ) {}
		if ( host && host !== window.location.hostname.replace( /^www\./, '' ) ) {
			var engine = engines.filter( function ( e ) {
				return host.indexOf( e + '.' ) !== -1 || host.indexOf( '.' + e ) !== -1;
			} )[ 0 ];
			out.source_type = engine ? 'organic' : 'referral';
			out.source = clip( engine || host );
			out.medium = engine ? 'organic' : 'referral';
		}
		return out;
	}

	function run() {
		if ( ! allowed() ) {
			return;
		}
		var existing = read();
		var q = window.location.search;
		var fresh = /[?&](utm_source|gclid)=/.test( q );
		if ( existing && ! fresh ) {
			write( existing ); // Slide the expiry only.
			return;
		}
		write( encodeURIComponent( JSON.stringify( detect() ) ) );
	}

	run();
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		if ( e.detail && e.detail.statistics === 'allow' && ! read() ) {
			run();
		}
	} );
}() );
