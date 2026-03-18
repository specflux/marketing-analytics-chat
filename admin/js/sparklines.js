/**
 * Sparkline Renderer for Marketing Analytics Chat
 *
 * Lightweight inline SVG sparkline generator — no external dependencies.
 *
 * @package Marketing_Analytics_MCP
 */

(function( $ ) {
	'use strict';

	window.MacSparkline = {

		/**
		 * Default options.
		 */
		defaults: {
			width: 60,
			height: 20,
			color: '#2271b1',
			fillColor: 'rgba(34, 113, 177, 0.1)',
			lineWidth: 1.5
		},

		/**
		 * Render a sparkline SVG into a container element.
		 *
		 * @param {HTMLElement} container The DOM element to render into.
		 * @param {Array}       data      Array of numeric values.
		 * @param {Object}      options   Optional overrides for width, height, color, fillColor.
		 */
		render: function( container, data, options ) {
			if ( ! container || ! Array.isArray( data ) || data.length < 2 ) {
				return;
			}

			var opts = $.extend( {}, this.defaults, options || {} );
			var w    = opts.width;
			var h    = opts.height;
			var pad  = opts.lineWidth;

			// Calculate min / max for scaling.
			var min = Math.min.apply( null, data );
			var max = Math.max.apply( null, data );

			// Avoid division by zero when all values are identical.
			if ( max === min ) {
				max = min + 1;
			}

			// Build polyline points.
			var points = [];
			var step   = ( w - pad * 2 ) / ( data.length - 1 );

			for ( var i = 0; i < data.length; i++ ) {
				var x = pad + i * step;
				var y = h - pad - ( ( data[ i ] - min ) / ( max - min ) ) * ( h - pad * 2 );
				points.push( x.toFixed( 1 ) + ',' + y.toFixed( 1 ) );
			}

			var polylinePoints = points.join( ' ' );

			// Build fill polygon (closes to bottom corners).
			var fillPoints = polylinePoints +
				' ' + ( pad + ( data.length - 1 ) * step ).toFixed( 1 ) + ',' + ( h - pad ).toFixed( 1 ) +
				' ' + pad.toFixed( 1 ) + ',' + ( h - pad ).toFixed( 1 );

			var svg = '<svg xmlns="http://www.w3.org/2000/svg"' +
				' width="' + w + '"' +
				' height="' + h + '"' +
				' viewBox="0 0 ' + w + ' ' + h + '"' +
				' style="display:block;">' +
				'<polygon points="' + fillPoints + '"' +
				' fill="' + opts.fillColor + '" />' +
				'<polyline points="' + polylinePoints + '"' +
				' fill="none"' +
				' stroke="' + opts.color + '"' +
				' stroke-width="' + opts.lineWidth + '"' +
				' stroke-linecap="round"' +
				' stroke-linejoin="round" />' +
				'</svg>';

			container.innerHTML = svg;
		}
	};

	/**
	 * Auto-initialize sparklines from data attributes on page load.
	 */
	$( document ).ready( function() {
		$( '.mac-sparkline' ).each( function() {
			var rawData = $( this ).attr( 'data-values' ) || '[]';
			var color   = $( this ).attr( 'data-color' ) || '#2271b1';

			try {
				var data = JSON.parse( rawData );
			} catch ( e ) {
				return;
			}

			MacSparkline.render( this, data, { color: color } );
		} );
	} );

	/**
	 * Dashboard metrics refresh handler.
	 */
	$( document ).ready( function() {
		var $panel = $( '.mac-insights-panel' );
		if ( ! $panel.length ) {
			return;
		}

		$panel.on( 'click', '.mac-insights-refresh', function( e ) {
			e.preventDefault();

			var $btn = $( this );
			if ( $btn.hasClass( 'updating-message' ) ) {
				return;
			}

			$btn.addClass( 'updating-message' ).prop( 'disabled', true );

			$.post( macDashboardInsights.ajaxUrl, {
				action: 'marketing_analytics_mcp_refresh_dashboard_metrics',
				nonce: macDashboardInsights.nonce
			}, function( response ) {
				$btn.removeClass( 'updating-message' ).prop( 'disabled', false );

				if ( response.success && response.data && response.data.metrics ) {
					updateMetricCards( response.data.metrics );
				}
			} ).fail( function() {
				$btn.removeClass( 'updating-message' ).prop( 'disabled', false );
			} );
		} );

		/**
		 * Update metric cards with fresh data from AJAX response.
		 *
		 * @param {Object} metrics Metrics keyed by platform.
		 */
		function updateMetricCards( metrics ) {
			$.each( metrics, function( platform, platformMetrics ) {
				$.each( platformMetrics, function( _, metric ) {
					var $card = $( '.mac-metric-card[data-platform="' + platform + '"][data-metric="' + metric.key + '"]' );
					if ( ! $card.length ) {
						return;
					}

					$card.find( '.mac-metric-value' ).text( metric.formatted );

					var $change = $card.find( '.mac-metric-change' );
					$change
						.removeClass( 'positive negative neutral' )
						.addClass( metric.direction );

					var arrow = 'neutral' === metric.direction ? '' : ( 'positive' === metric.direction ? '\u2191 ' : '\u2193 ' );
					$change.text( arrow + metric.change );

					var $sparkline = $card.find( '.mac-sparkline' );
					if ( $sparkline.length && metric.sparkline && metric.sparkline.length > 1 ) {
						var color = 'positive' === metric.direction ? '#00a32a' : ( 'negative' === metric.direction ? '#d63638' : '#2271b1' );
						MacSparkline.render( $sparkline[0], metric.sparkline, { color: color } );
					}
				} );
			} );
		}
	} );

})( jQuery );
