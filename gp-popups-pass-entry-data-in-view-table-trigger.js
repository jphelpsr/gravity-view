<script>
( function() {

	var feedId = 13; // ID of your Popup Feed.
	var triggerSelector = '.open-ticket'; // Must match the feed's click trigger selector.
	var paramPrefix = 'data-param-';
	var debug = false;

	var lastTrigger = null;
	var filterAdded = false;

	function log() {
		if ( debug && window.console ) {
			console.log.apply( console, [ '[GPP Row Params]' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	function rememberTrigger( e ) {
		var el = e.target && e.target.closest ? e.target.closest( triggerSelector ) : null;
		if ( el ) {
			lastTrigger = el;
			log( 'Trigger recorded:', el );
		}
	}

	// pointerdown/mousedown fire before click, and window capture runs before
	// any document-level handlers, so the clicked link is known before the popup opens.
	window.addEventListener( 'pointerdown', rememberTrigger, true );
	window.addEventListener( 'mousedown', rememberTrigger, true );
	window.addEventListener( 'click', rememberTrigger, true );
	window.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			rememberTrigger( e );
		}
	}, true );

	function addFilter() {
		if ( filterAdded ) {
			return true;
		}

		if ( ! window.gform || typeof window.gform.addFilter !== 'function' ) {
			return false;
		}

		window.gform.addFilter( 'gpp_popup_config', function( config ) {
			log( 'Filter ran for feed', config.feedId, '| trigger:', lastTrigger );

			if ( parseInt( config.feedId, 10 ) !== feedId || ! lastTrigger ) {
				return config;
			}

			var url = new URL( config.iframeUrl, window.location.href );

			Array.prototype.forEach.call( lastTrigger.attributes, function( attr ) {
				if ( attr.name.indexOf( paramPrefix ) === 0 && attr.value !== '' ) {
					url.searchParams.set( attr.name.slice( paramPrefix.length ), attr.value );
				}
			} );

			config.iframeUrl = url.toString();
			log( 'Popup URL:', config.iframeUrl );

			return config;
		} );

		filterAdded = true;
		log( 'Filter registered' );

		return true;
	}

	// Retry until Gravity Forms' hooks are available (up to ~10 seconds).
	if ( ! addFilter() ) {
		var tries = 0;
		var timer = setInterval( function() {
			if ( addFilter() || ++tries > 100 ) {
				clearInterval( timer );
				if ( ! filterAdded ) {
					log( 'gform.addFilter never became available.' );
				}
			}
		}, 100 );
	}

} )();
</script>
