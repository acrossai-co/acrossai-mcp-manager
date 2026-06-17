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
