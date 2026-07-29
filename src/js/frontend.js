/**
 * [acrossai_mcp_servers] shortcode — public interactions.
 *
 * Reference source: acrossai-mcp-manager.zip / MCP Servers Widget v2.dc.html
 *
 * Deliberately vanilla JS (no React, no WP packages). The design brief for
 * this shortcode is theme-agnostic and dependency-free — two behaviors:
 *
 *   1. Sidebar server switcher + client pill switcher.
 *      One delegated click listener toggles a data-active attribute on the
 *      matching detail card and aria-selected on the nav element. No DOM
 *      re-render — pure CSS visibility swap for zero-flash transitions.
 *
 *   2. Copy-to-clipboard.
 *      One delegated listener on [data-amcp-copy] copies the referenced
 *      element's textContent, flashes a two-second "Copied!"
 *      acknowledgement, then reverts. Falls back to a hidden-textarea +
 *      document.execCommand('copy') on browsers that lack the async
 *      Clipboard API (older mobile Safari, non-HTTPS contexts).
 */
( function () {
	'use strict';

	// ── Selection state — sidebar servers + client pills ─────────
	document.addEventListener( 'click', function ( event ) {
		var serverBtn = event.target.closest( '[data-amcp-server-select]' );
		if ( serverBtn ) {
			handleServerSelect( serverBtn );
			return;
		}

		var clientBtn = event.target.closest( '[data-amcp-client-select]' );
		if ( clientBtn ) {
			handleClientSelect( clientBtn );
			return;
		}
	} );

	function handleServerSelect( btn ) {
		var widget = btn.closest( '.acrossai-mcp-servers' );
		if ( ! widget ) {
			return;
		}
		var target = btn.getAttribute( 'data-amcp-server-select' );
		if ( ! target ) {
			return;
		}

		// Toggle aria-selected on sidebar buttons.
		var buttons = widget.querySelectorAll( '[data-amcp-server-select]' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].setAttribute(
				'aria-selected',
				buttons[ i ].getAttribute( 'data-amcp-server-select' ) === target
					? 'true'
					: 'false'
			);
		}

		// Show only the matching server panel.
		var panels = widget.querySelectorAll( '[data-amcp-server]' );
		for ( var j = 0; j < panels.length; j++ ) {
			panels[ j ].setAttribute(
				'data-active',
				panels[ j ].getAttribute( 'data-amcp-server' ) === target
					? 'true'
					: 'false'
			);
		}
	}

	function handleClientSelect( btn ) {
		var panel = btn.closest( '[data-amcp-server]' );
		if ( ! panel ) {
			return;
		}
		var target = btn.getAttribute( 'data-amcp-client-select' );
		if ( ! target ) {
			return;
		}

		// Toggle aria-selected on client pills within this server panel.
		var pills = panel.querySelectorAll( '[data-amcp-client-select]' );
		for ( var i = 0; i < pills.length; i++ ) {
			pills[ i ].setAttribute(
				'aria-selected',
				pills[ i ].getAttribute( 'data-amcp-client-select' ) === target
					? 'true'
					: 'false'
			);
		}

		// Show only the matching client-detail card.
		var details = panel.querySelectorAll( '[data-amcp-client]' );
		for ( var j = 0; j < details.length; j++ ) {
			details[ j ].setAttribute(
				'data-active',
				details[ j ].getAttribute( 'data-amcp-client' ) === target
					? 'true'
					: 'false'
			);
		}
	}

	// ── Copy-to-clipboard ────────────────────────────────────────
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
