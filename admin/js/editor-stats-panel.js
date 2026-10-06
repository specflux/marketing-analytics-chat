/**
 * Block editor sidebar panel: 28-day analytics for the post being edited.
 *
 * Data arrives in window.specfluxMacEditorStats (server-escaped JSON). When the
 * server cache is cold the panel asks admin-ajax.php to build it once.
 */
( function ( wp, $ ) {
	'use strict';

	var cfg = window.specfluxMacEditorStats;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! cfg || ! Panel || ! wp.plugins || ! wp.element || ! wp.data || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;

	function StatsPanel() {
		var saved = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return editor && editor.getCurrentPostAttribute ? editor.getCurrentPostAttribute( 'status' ) : null;
		}, [] );

		var state = useState( cfg.data );
		var data = state[ 0 ];
		var setData = state[ 1 ];
		var failedState = useState( false );
		var failed = failedState[ 0 ];
		var setFailed = failedState[ 1 ];

		var published = 'publish' === saved;
		var needsFetch = published && 'ready' !== data.state;

		useEffect( function () {
			if ( ! needsFetch ) {
				return;
			}
			var cancelled = false;
			$.post( cfg.ajaxUrl, {
				action: cfg.action,
				nonce: cfg.nonce,
				post_id: cfg.postId
			} ).done( function ( response ) {
				if ( cancelled ) {
					return;
				}
				if ( response && response.success && response.data ) {
					setData( response.data );
				} else {
					setFailed( true );
				}
			} ).fail( function () {
				if ( ! cancelled ) {
					setFailed( true );
				}
			} );
			return function () {
				cancelled = true;
			};
		}, [ needsFetch, saved ] );

		var body;

		if ( ! published ) {
			body = el( 'p', null, __( 'Publish this post to start collecting analytics.', 'specflux-marketing-analytics-chat' ) );
		} else if ( failed ) {
			body = el( 'p', { title: cfg.errorTitle }, '—' );
		} else if ( 'ready' !== data.state ) {
			body = el( 'p', null, __( 'Loading…', 'specflux-marketing-analytics-chat' ) );
		} else {
			var rows = [
				[ __( 'Views (GA4)', 'specflux-marketing-analytics-chat' ), data.values.views ],
				[ __( 'Search clicks', 'specflux-marketing-analytics-chat' ), data.values.clicks ],
				[ __( 'Impressions', 'specflux-marketing-analytics-chat' ), data.values.impressions ],
				[ __( 'Avg. position', 'specflux-marketing-analytics-chat' ), data.values.position ]
			];
			body = el(
				'div',
				{ className: 'smac-editor-stats', title: data.failed ? cfg.errorTitle : undefined },
				rows.map( function ( row ) {
					return el(
						'div',
						{ key: row[ 0 ], style: { display: 'flex', justifyContent: 'space-between', marginBottom: '4px' } },
						el( 'span', null, row[ 0 ] ),
						el( 'strong', null, row[ 1 ] )
					);
				} ),
				el(
					'p',
					{ style: { marginTop: '12px' } },
					el( 'a', { href: data.chatUrl }, __( 'Ask the AI how to improve this post', 'specflux-marketing-analytics-chat' ) )
				)
			);
		}

		return el(
			Panel,
			{
				name: 'specflux-mac-editor-stats',
				title: __( 'Analytics (28 days)', 'specflux-marketing-analytics-chat' ),
				className: 'smac-editor-stats-panel',
				initialOpen: true
			},
			body
		);
	}

	wp.plugins.registerPlugin( 'specflux-mac-editor-stats', { render: StatsPanel } );
}( window.wp, window.jQuery ) );
