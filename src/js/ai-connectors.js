/**
 * F021 Phase 9 — AI Connectors shared JS.
 *
 * Delegated event handlers on the tab wrapper. Every connector card
 * rendered by `AbstractConnectorProfile::render_default_card` inherits
 * these behaviors:
 *   • Generate credentials → POST to /oauth/generate-client
 *   • Regenerate credentials → confirm + POST
 *   • Copy button → clipboard writeText with execCommand fallback
 *   • Reveal button → toggle password input type
 *
 * Server id + REST nonce come from the tab wrapper's data attributes:
 *   .acrossai-mcp-ai-connectors[data-server-id][data-wp-rest-nonce]
 *
 * Connector slug comes from the section's data attribute:
 *   .acrossai-mcp-connector[data-acrossai-connector-slug]
 *
 * Localized i18n bundle: `acrossaiMcpConnectors` (via wp_localize_script).
 *
 * Class + attribute contract is PUBLIC API — marked @experimental
 * until 1.0.0. Any breaking change requires a memory entry.
 */

import '../scss/ai-connectors.scss';

( function () {
	'use strict';

	const i18n = ( typeof window !== 'undefined' && window.acrossaiMcpConnectors ) || {};
	const REST_ENDPOINT = i18n.restEndpoint || '';

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		const wrappers = document.querySelectorAll( '.acrossai-mcp-ai-connectors' );
		if ( 0 === wrappers.length ) {
			return;
		}

		wrappers.forEach( ( wrapper ) => wrapper.addEventListener( 'click', handleClick.bind( null, wrapper ) ) );
	}

	function handleClick( wrapper, ev ) {
		const target = ev.target.closest(
			'.acrossai-mcp-connector__generate-btn, .acrossai-mcp-connector__regenerate-btn, ' +
			'[data-acrossai-copy], [data-acrossai-reveal], ' +
			'.acrossai-mcp-connector-panel__revoke-btn, .acrossai-mcp-connector-panel__delete-btn, ' +
			'.acrossai-mcp-connector-panel__nuclear-btn, .acrossai-mcp-connector-panel__approve-btn'
		);
		if ( ! target ) {
			return;
		}
		ev.preventDefault();

		if ( target.matches( '.acrossai-mcp-connector__generate-btn' ) ) {
			handleGenerate( wrapper, target, false );
		} else if ( target.matches( '.acrossai-mcp-connector__regenerate-btn' ) ) {
			handleGenerate( wrapper, target, true );
		} else if ( target.matches( '[data-acrossai-copy]' ) ) {
			handleCopy( target );
		} else if ( target.matches( '[data-acrossai-reveal]' ) ) {
			handleReveal( target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__revoke-btn' ) ) {
			handleAdminAction( wrapper, target, 'revoke-client-tokens', 'Revoke every token for this client?' );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__delete-btn' ) ) {
			handleAdminAction( wrapper, target, 'delete-client', 'Delete this client? This revokes every token first and cannot be undone.' );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__nuclear-btn' ) ) {
			handleNuclear( wrapper, target );
		} else if ( target.matches( '.acrossai-mcp-connector-panel__approve-btn' ) ) {
			handleApprove( wrapper, target );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.acrossai-mcp-connector-panel__settings-form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();
				const wrapper = form.closest( '.acrossai-mcp-ai-connectors' );
				if ( ! wrapper ) { return; }
				handleSaveSettings( wrapper, form );
			} );
		} );
	} );

	// ---- F024 admin actions ---------------------------------------------

	function adminBase( wrapper ) {
		const serverId = parseInt( wrapper.getAttribute( 'data-server-id' ), 10 );
		const nonce    = wrapper.getAttribute( 'data-wp-rest-nonce' ) || '';
		const base     = ( REST_ENDPOINT || '' ).replace( /\/oauth\/generate-client$/, '' );
		return { serverId: serverId, nonce: nonce, base: base };
	}

	function postAdmin( wrapper, path, body ) {
		const ctx = adminBase( wrapper );
		if ( ! ctx.base || ! ctx.nonce ) {
			return Promise.reject( new Error( 'missing-ctx' ) );
		}
		return fetch( ctx.base + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ctx.nonce,
			},
			body: JSON.stringify( body ),
		} ).then( function ( r ) {
			return r.json().then( function ( data ) { return { status: r.status, data: data }; } );
		} );
	}

	function handleAdminAction( wrapper, btn, path, confirmMsg ) {
		const clientId = btn.getAttribute( 'data-acrossai-client-id' );
		if ( ! clientId ) { return; }
		if ( ! window.confirm( confirmMsg ) ) { return; }
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/' + path, { client_id: clientId } )
			.then( function ( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					btn.disabled = false;
				}
			} )
			.catch( function () { btn.disabled = false; } );
	}

	function handleNuclear( wrapper, btn ) {
		const slug       = btn.getAttribute( 'data-acrossai-connector-slug' );
		const confirmMsg = btn.getAttribute( 'data-acrossai-confirm' ) || 'Revoke everything?';
		if ( ! slug ) { return; }
		if ( ! window.confirm( confirmMsg ) ) { return; }
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/revoke-connector-tokens', { server_id: ctx.serverId, connector_slug: slug } )
			.then( function () { window.location.reload(); } )
			.catch( function () { btn.disabled = false; } );
	}

	function handleApprove( wrapper, btn ) {
		const slug   = btn.getAttribute( 'data-acrossai-connector-slug' );
		const userId = parseInt( btn.getAttribute( 'data-acrossai-user-id' ), 10 );
		if ( ! slug || ! userId ) { return; }
		const ctx = adminBase( wrapper );
		btn.disabled = true;
		postAdmin( wrapper, '/oauth/approve-pending-consent', { server_id: ctx.serverId, connector_slug: slug, user_id: userId } )
			.then( function () { window.location.reload(); } )
			.catch( function () { btn.disabled = false; } );
	}

	function handleSaveSettings( wrapper, form ) {
		const slug = form.getAttribute( 'data-acrossai-connector-slug' );
		if ( ! slug ) { return; }
		const enabledInput  = form.querySelector( 'input[name="enabled"]' );
		const approvalInput = form.querySelector( 'input[name="require_admin_approval"]' );
		const enabled       = !! ( enabledInput && enabledInput.checked );
		const approval      = !! ( approvalInput && approvalInput.checked );

		const ctx = adminBase( wrapper );
		const btn = form.querySelector( 'button[type="submit"]' );
		if ( btn ) { btn.disabled = true; }

		postAdmin( wrapper, '/oauth/connector-settings', {
			server_id:              ctx.serverId,
			connector_slug:         slug,
			enabled:                enabled,
			require_admin_approval: approval,
		} )
			.then( function ( response ) {
				if ( response.status >= 200 && response.status < 300 ) {
					window.location.reload();
				} else {
					window.alert( 'Failed to save. See console.' );
					// eslint-disable-next-line no-console
					console.error( response );
					if ( btn ) { btn.disabled = false; }
				}
			} )
			.catch( function () { if ( btn ) { btn.disabled = false; } } );
	}

	// ---- Generate / Regenerate ------------------------------------------

	function handleGenerate( wrapper, btn, isRegenerate ) {
		const section = btn.closest( '.acrossai-mcp-connector' );
		if ( ! section ) {
			return;
		}

		const serverId = parseInt( wrapper.getAttribute( 'data-server-id' ), 10 );
		const nonce    = wrapper.getAttribute( 'data-wp-rest-nonce' ) || '';
		const slug     = section.getAttribute( 'data-acrossai-connector-slug' ) || '';
		const endpoint = REST_ENDPOINT;

		if ( ! serverId || ! nonce || ! endpoint || ! slug ) {
			flashResult( section, 'error', i18n.missingCtx || 'Missing server context. Reload the page and try again.' );
			return;
		}

		if ( isRegenerate ) {
			const confirmMsg = btn.getAttribute( 'data-acrossai-confirm' ) || i18n.confirmRegenerate || 'Regenerate credentials?';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}
		}

		btn.disabled = true;
		flashResult( section, 'pending', i18n.working || 'Generating credentials…' );

		fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( { server_id: serverId, connector_slug: slug } ),
		} )
			.then( ( r ) => r.json().then( ( body ) => ( { status: r.status, body } ) ) )
			.then( ( response ) => {
				btn.disabled = false;
				if ( response.status < 200 || response.status >= 300 ) {
					const msg =
						( response.body && ( response.body.message || response.body.error_description ) ) ||
						i18n.failed ||
						'Failed to generate credentials.';
					flashResult( section, 'error', msg );
					return;
				}
				renderCredentials( section, response.body );
			} )
			.catch( () => {
				btn.disabled = false;
				flashResult( section, 'error', i18n.failed || 'Failed to generate credentials.' );
			} );
	}

	// ---- Rendering ------------------------------------------------------

	function renderCredentials( section, body ) {
		const result = section.querySelector( '[data-acrossai-result]' );
		if ( ! result ) {
			return;
		}

		const labels = {
			issued:   i18n.issued   || 'Credentials generated',
			clientId: i18n.clientId || 'OAuth Client ID',
			secret:   i18n.secret   || 'OAuth Client Secret (visible once — copy it now)',
			setup:    i18n.setup    || 'Setup instructions',
			copy:     i18n.copy     || 'Copy',
			reveal:   i18n.reveal   || 'Reveal',
		};

		let html = '';
		html += '<h4 class="acrossai-mcp-connector__result-title">' + escapeHtml( labels.issued ) + '</h4>';
		html += '<div class="acrossai-mcp-connector__credentials acrossai-mcp-connector__credentials--fresh">';
		html += '<p class="acrossai-mcp-connector__label">' + escapeHtml( labels.clientId ) + '</p>';
		html += '<div class="acrossai-mcp-connector__copy-row">';
		html += '<input type="text" class="acrossai-mcp-connector__input regular-text code" value="' + escapeAttr( body.client_id || '' ) + '" readonly>';
		html += '<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="client-id">' + escapeHtml( labels.copy ) + '</button>';
		html += '</div>';
		html += '<p class="acrossai-mcp-connector__label">' + escapeHtml( labels.secret ) + '</p>';
		html += '<div class="acrossai-mcp-connector__copy-row">';
		html += '<input type="password" class="acrossai-mcp-connector__input acrossai-mcp-connector__input--secret regular-text code" value="' + escapeAttr( body.client_secret || '' ) + '" readonly autocomplete="off" spellcheck="false">';
		html += '<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-reveal="client-secret">' + escapeHtml( labels.reveal ) + '</button>';
		html += '<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="client-secret">' + escapeHtml( labels.copy ) + '</button>';
		html += '</div>';
		html += '</div>';

		if ( body.setup_instructions_html ) {
			// setup_instructions_html was passed through wp_kses_post server-side
			// (SEC-021-T02). Safe to insert as-is.
			html += '<div class="acrossai-mcp-connector__setup">';
			html += '<h4 class="acrossai-mcp-connector__setup-title">' + escapeHtml( labels.setup ) + '</h4>';
			html += body.setup_instructions_html;
			html += '</div>';
		}

		result.innerHTML = html;
		result.setAttribute( 'data-status', 'success' );
	}

	function flashResult( section, status, message ) {
		const result = section.querySelector( '[data-acrossai-result]' );
		if ( ! result ) {
			return;
		}
		result.setAttribute( 'data-status', status );
		result.innerHTML = '<p class="acrossai-mcp-connector__result-message">' + escapeHtml( message ) + '</p>';
	}

	// ---- Copy / Reveal --------------------------------------------------

	function handleCopy( btn ) {
		const row = btn.closest( '.acrossai-mcp-connector__copy-row' );
		if ( ! row ) {
			return;
		}
		const input = row.querySelector( '.acrossai-mcp-connector__input' );
		if ( ! input ) {
			return;
		}
		const value = input.value || '';

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard
				.writeText( value )
				.then( () => flashCopied( btn ) )
				.catch( () => fallbackCopy( input, btn ) );
		} else {
			fallbackCopy( input, btn );
		}
	}

	function fallbackCopy( input, btn ) {
		const wasPassword = 'password' === input.type;
		if ( wasPassword ) {
			input.type = 'text';
		}
		input.select();
		try {
			document.execCommand( 'copy' );
			flashCopied( btn );
		} catch ( e ) {
			// Silent — user can still copy manually.
		}
		if ( wasPassword ) {
			input.type = 'password';
		}
		input.blur();
	}

	function flashCopied( btn ) {
		const originalText = btn.textContent;
		btn.textContent = i18n.copied || 'Copied!';
		btn.classList.add( 'acrossai-mcp-connector__copy-btn--copied' );
		setTimeout( () => {
			btn.textContent = originalText;
			btn.classList.remove( 'acrossai-mcp-connector__copy-btn--copied' );
		}, 1500 );
	}

	function handleReveal( btn ) {
		const row = btn.closest( '.acrossai-mcp-connector__copy-row' );
		if ( ! row ) {
			return;
		}
		const input = row.querySelector( '.acrossai-mcp-connector__input--secret' );
		if ( ! input ) {
			return;
		}
		const revealLabel = i18n.reveal || 'Reveal';
		const hideLabel   = i18n.hide || 'Hide';

		if ( 'password' === input.type ) {
			input.type = 'text';
			btn.textContent = hideLabel;
		} else {
			input.type = 'password';
			btn.textContent = revealLabel;
		}
	}

	// ---- Utilities ------------------------------------------------------

	function escapeHtml( s ) {
		return String( null == s ? '' : s ).replace( /[&<>"']/g, ( c ) => (
			{ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]
		) );
	}

	function escapeAttr( s ) {
		return escapeHtml( s );
	}
} )();
