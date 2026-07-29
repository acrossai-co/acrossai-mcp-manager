/**
 * [acrossai_mcp_servers] shortcode — public interactions.
 *
 * Reference source: acrossai-mcp-manager.zip / shortcode-output.html
 *
 * Deliberately vanilla JS (no React, no WP packages). The design brief for
 * this shortcode is theme-agnostic and dependency-free — expand/collapse is
 * native <details>, and the only scripted behavior is copy-to-clipboard.
 *
 * One delegated click listener handles every [data-amcp-copy] button on the
 * page (including any markup injected later), copies the referenced element's
 * textContent, flashes a two-second "Copied!" acknowledgement, then reverts.
 * Falls back to a hidden-textarea + document.execCommand('copy') on browsers
 * that lack the async Clipboard API (older mobile Safari, non-HTTPS contexts).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '[data-amcp-copy]' );
		if ( ! btn ) {
			return;
		}

		var selector = btn.getAttribute( 'data-amcp-copy' );
		var source = selector ? document.querySelector( selector ) : null;
		if ( ! source ) {
			return;
		}

		var text = source.textContent;

		var done = function () {
			var original =
				btn.getAttribute( 'data-amcp-label' ) || btn.textContent;
			btn.setAttribute( 'data-amcp-label', original );
			btn.textContent = '✓ Copied!';
			btn.classList.add( 'is-copied' );
			clearTimeout( btn._amcpTimer );
			btn._amcpTimer = setTimeout( function () {
				btn.textContent = original;
				btn.classList.remove( 'is-copied' );
			}, 2000 );
		};

		var fallback = function () {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.cssText = 'position:absolute;left:-9999px;top:0';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( err ) {
				// Clipboard write failed on both paths — leave the button
				// unchanged so the user knows the copy did not happen.
			}
			document.body.removeChild( ta );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, fallback );
		} else {
			fallback();
		}
	} );
} )();
