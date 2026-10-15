( function () {
	'use strict';

	var config = window.wpQurlBlock || {};
	var i18n = config.i18n || {};

	function initBlock( root ) {
		if ( ! root || root.dataset.wpQurlInit ) {
			return;
		}
		root.dataset.wpQurlInit = '1';

		var endpoint = root.getAttribute( 'data-endpoint' ) || '';
		var button = root.querySelector( '.wp-qurl-mint__button' );
		var statusEl = root.querySelector( '.wp-qurl-mint__status' );
		var resultEl = root.querySelector( '.wp-qurl-mint__result' );
		var linkInput = root.querySelector( '.wp-qurl-mint__link' );
		var copyBtn = root.querySelector( '.wp-qurl-mint__copy' );

		if ( ! button || ! endpoint ) {
			return;
		}

		function setStatus( message, isError ) {
			statusEl.textContent = message || '';
			statusEl.className = 'wp-qurl-mint__status' + ( isError ? ' is-error' : '' );
		}

		function showLink( link ) {
			if ( ! link ) {
				return;
			}
			linkInput.value = link;
			resultEl.hidden = false;
			button.hidden = true;
			setStatus( '' );
		}

		function apiFetch( path, options ) {
			options = options || {};
			var headers = options.headers || {};
			headers[ 'X-WP-Nonce' ] = config.nonce;
			options.headers = headers;
			options.credentials = 'same-origin';

			return fetch( ( config.restUrl || '' ).replace( /\/$/, '' ) + path, options ).then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						var message = ( data && data.message ) || i18n.error;
						throw new Error( message );
					}
					return data;
				} );
			} );
		}

		apiFetch( '/mint/status?endpoint=' + encodeURIComponent( endpoint ) )
			.then( function ( data ) {
				if ( data && data.has_active ) {
					setStatus( i18n.alreadyGenerated || 'You already have a qURL. Make another if you need a new one.' );
				}
			} )
			.catch( function () {
				// Silent — user can click generate.
			} );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			setStatus( i18n.generating || 'Minting…' );

			apiFetch( '/mint', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( { endpoint: endpoint } ),
			} )
				.then( function ( data ) {
					if ( data && data.qurl_link ) {
						showLink( data.qurl_link );
					}
				} )
				.catch( function ( err ) {
					button.disabled = false;
					setStatus( err.message || i18n.error, true );
				} );
		} );

		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				if ( ! linkInput.value ) {
					return;
				}
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( linkInput.value ).then( function () {
						copyBtn.textContent = i18n.copied || 'Copied!';
						setTimeout( function () {
							copyBtn.textContent = i18n.copyLabel || 'Copy qURL';
						}, 2000 );
					} ).catch( function () {
						setStatus( i18n.copyFail || 'Copy failed.', true );
					} );
				} else {
					linkInput.select();
					try {
						document.execCommand( 'copy' );
						copyBtn.textContent = i18n.copied || 'Copied!';
					} catch ( e ) {
						setStatus( i18n.copyFail || 'Copy failed.', true );
					}
				}
			} );
		}
	}

	function boot() {
		document.querySelectorAll( '.wp-qurl-mint[data-endpoint]' ).forEach( initBlock );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
