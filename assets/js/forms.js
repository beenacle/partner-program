/**
 * Keeps public form nonces alive on cached pages.
 *
 * The application and login pages are static enough that full-page caches
 * (Kinsta, Varnish, Cloudflare, WP Rocket, ...) happily serve the same HTML
 * for hours. WordPress bakes a nonce into that HTML, and an anonymous nonce
 * dies after 12-24h — so a visitor served a stale cached copy submits with a
 * dead nonce and the request is rejected, silently losing the submission.
 *
 * This script fetches a fresh nonce from an uncached REST endpoint on load
 * (and again right before submit) and writes it into the form, so a cached
 * page still submits successfully.
 */
( function () {
	'use strict';

	var cfg = window.partnerProgramForms || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	function refresh( form ) {
		var action = form.getAttribute( 'data-pp-nonce-action' );
		var fieldName = form.getAttribute( 'data-pp-nonce-field' );
		if ( ! action || ! fieldName ) {
			return Promise.resolve();
		}
		var field = form.querySelector( 'input[name="' + fieldName + '"]' );
		if ( ! field ) {
			return Promise.resolve();
		}
		var sep = cfg.restUrl.indexOf( '?' ) === -1 ? '?' : '&';
		var url = cfg.restUrl + sep + 'action=' + encodeURIComponent( action );
		return fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json' }
		} ).then( function ( res ) {
			return res.ok ? res.json() : null;
		} ).then( function ( data ) {
			if ( data && data.nonce ) {
				field.value = data.nonce;
			}
		} ).catch( function () {
			/* Network hiccup: fall back to the server-side graceful
			   "session expired, try again" handling. */
		} );
	}

	function arm( form ) {
		// Refresh immediately so even a no-JS-interaction submit is current.
		refresh( form );

		var armed = false;
		form.addEventListener( 'submit', function ( event ) {
			if ( armed ) {
				return; // Second pass: let the browser submit for real.
			}
			event.preventDefault();
			refresh( form ).then( function () {
				armed = true;
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			} );
		} );
	}

	function init() {
		var forms = document.querySelectorAll( 'form[data-pp-refresh-nonce]' );
		for ( var i = 0; i < forms.length; i++ ) {
			arm( forms[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
