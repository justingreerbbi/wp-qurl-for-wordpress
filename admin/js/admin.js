/**
 * WP qURL admin JavaScript.
 *
 * @package WP_QURL
 */
( function ( $ ) {
	'use strict';

	/**
	 * Copy text to clipboard and show brief feedback.
	 *
	 * @param {string} text Text to copy.
	 * @param {jQuery} $btn Button element for feedback.
	 */
	function copyToClipboard( text, $btn ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				showCopyFeedback( $btn );
			} ).catch( function () {
				fallbackCopy( text, $btn );
			} );
		} else {
			fallbackCopy( text, $btn );
		}
	}

	/**
	 * Fallback copy using a temporary textarea.
	 *
	 * @param {string} text Text to copy.
	 * @param {jQuery} $btn Button element.
	 */
	function fallbackCopy( text, $btn ) {
		var $tmp = $( '<textarea>' ).val( text ).css( { position: 'fixed', left: '-9999px' } );
		$( 'body' ).append( $tmp );
		$tmp[0].select();
		try {
			document.execCommand( 'copy' );
			showCopyFeedback( $btn );
		} catch ( e ) {
			window.alert( wpQurlAdmin.copyFail );
		}
		$tmp.remove();
	}

	/**
	 * Show temporary success label on copy button.
	 *
	 * @param {jQuery} $btn Button element.
	 */
	function showCopyFeedback( $btn ) {
		var original = $btn.text();
		$btn.text( wpQurlAdmin.copySuccess );
		setTimeout( function () {
			$btn.text( original );
		}, 2000 );
	}

	$( document ).on( 'click', '.wp-qurl-copy', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var text = $btn.attr( 'data-copy' ) || $btn.closest( 'td, .notice, p' ).find( '.wp-qurl-link-input' ).val();
		if ( text ) {
			copyToClipboard( text, $btn );
		}
	} );
}( jQuery ) );
