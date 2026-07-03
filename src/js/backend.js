/**
 * Backend bundle entry — bundled into build/js/backend.js by @wordpress/scripts.
 *
 * Currently ships only the US4 dismissible-notice persistence handler.
 */

/**
 * US4 — Persist the dismissal of the "MCP adapter package missing" notice.
 *
 * WordPress core already removes the notice DOM element when the user clicks
 * `.notice-dismiss`. We listen for that click and fire a small admin-ajax
 * POST so the dismissal is remembered across page loads (sticky, per-user).
 *
 * Fire-and-forget: even on transient failure the user can dismiss again next
 * page load. No response handling required.
 *
 * Contract: specs/002-admin-ui/contracts/notice-dismissal.md
 */
( function () {
	if ( typeof document === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		const dismissBtn = target.closest(
			'.acrossai-mcp-adapter-notice .notice-dismiss'
		);
		if ( ! dismissBtn ) {
			return;
		}

		const notice = dismissBtn.closest( '.acrossai-mcp-adapter-notice' );
		if ( ! notice ) {
			return;
		}

		const nonce = notice.dataset.nonce;
		if ( ! nonce ) {
			return;
		}

		// `ajaxurl` is a global wp-admin core sets on every admin page.
		// eslint-disable-next-line no-undef
		if ( typeof ajaxurl === 'undefined' ) {
			return;
		}

		const body = new URLSearchParams();
		body.set( 'action', 'acrossai_mcp_dismiss_adapter_notice' );
		body.set( '_ajax_nonce', nonce );

		// eslint-disable-next-line no-undef
		fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).catch( function () {
			// Swallow — user can re-dismiss next page load.
		} );
	} );
} )();

/**
 * Feature 013 — Copy-to-clipboard handler.
 *
 * Contract (ported from the reference plugin's assets/admin.js):
 *   - Buttons carry `.copy-to-clipboard` and `data-field="<textarea-id>"`.
 *   - On click, copy the referenced textarea/input value to the clipboard.
 *   - On success, flash the button label to "✓ Copied!" for 2 s, then revert.
 *
 * Also supports the F013-original `data-copy-target="<css-selector>"`
 * shape so buttons still emitting that attribute keep working during
 * cross-context reuse.
 *
 * Uses `navigator.clipboard.writeText()` when available; falls back to
 * `document.execCommand('copy')` for older browsers or insecure contexts.
 */
/**
 * Feature 013 — Confirm-before-submit handler for destructive buttons.
 *
 * Ported from the reference plugin's inline `onsubmit="return confirm(...)"`.
 * Buttons carry `data-acrossai-confirm="<localized message>"`; on click, we
 * show a browser confirm() dialog and cancel the submit if the user backs
 * out. Uses event delegation so it works for dynamically-injected buttons.
 *
 * Currently consumed by DangerZoneTab's "Delete Server" button. Any future
 * destructive action (revoke, rotate, etc.) can opt in by emitting the same
 * data attribute — no per-tab JS required.
 */
( function () {
	if ( typeof document === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const button = target.closest( '[data-acrossai-confirm]' );
		if ( ! button ) {
			return;
		}
		const message = button.getAttribute( 'data-acrossai-confirm' );
		if ( ! message ) {
			return;
		}
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true );
} )();

( function () {
	if ( typeof document === 'undefined' ) {
		return;
	}

	function readSourceText( source ) {
		if ( source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement ) {
			return source.value;
		}
		return source.textContent || '';
	}

	function copyViaExecCommand( text ) {
		const scratch = document.createElement( 'textarea' );
		scratch.value = text;
		scratch.style.position = 'fixed';
		scratch.style.top = '0';
		scratch.style.left = '0';
		scratch.style.width = '2em';
		scratch.style.height = '2em';
		scratch.style.opacity = '0';
		document.body.appendChild( scratch );
		scratch.focus();
		scratch.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( scratch );
		return ok;
	}

	function flashCopied( button ) {
		if ( ! button.dataset.originalLabel ) {
			button.dataset.originalLabel = button.textContent || '';
		}
		button.textContent = '✓ Copied!';
		button.disabled = true;
		setTimeout( function () {
			button.textContent = button.dataset.originalLabel;
			button.disabled = false;
		}, 2000 );
	}

	function findSourceForButton( button ) {
		const fieldId = button.getAttribute( 'data-field' );
		if ( fieldId ) {
			return document.getElementById( fieldId );
		}
		const selector = button.getAttribute( 'data-copy-target' );
		if ( selector ) {
			return document.querySelector( selector );
		}
		return null;
	}

	document.addEventListener( 'click', function ( event ) {
		const target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}
		const button = target.closest( '.copy-to-clipboard, [data-copy-target]' );
		if ( ! button ) {
			return;
		}

		const source = findSourceForButton( button );
		if ( ! source ) {
			return;
		}

		const text = readSourceText( source );
		if ( ! text ) {
			return;
		}

		event.preventDefault();

		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			navigator.clipboard.writeText( text ).then(
				function () {
					flashCopied( button );
				},
				function () {
					if ( copyViaExecCommand( text ) ) {
						flashCopied( button );
					}
				}
			);
			return;
		}

		if ( copyViaExecCommand( text ) ) {
			flashCopied( button );
		}
	} );
} )();
