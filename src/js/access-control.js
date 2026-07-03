/**
 * Feature 015 — Access Control tab React entry.
 *
 * Mounts the vendor `wpb-access-control` React component into the
 * per-server AccessControlTab. All UI (provider dropdown, role checkboxes,
 * user autocomplete search) is owned by the vendor component; this entry
 * file just reads the mount-div's data-* config and boots.
 *
 * Compiled to build/js/access-control.js by webpack.config.js. Enqueued by
 * admin/Main.php on the Access Control tab only.
 */

import { render, createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { AccessControl } from '@wpb/access-control';

// Bundle the vendor stylesheet with this entry. The vendor's AccessControl.js
// deliberately does NOT import its own SCSS so consumers can control CSS
// delivery via their own webpack.
import '../../vendor/wpboilerplate/wpb-access-control/js/AccessControl.scss';

( function () {
	const mount = document.getElementById( 'acrossai-mcp-ac-root' );
	if ( ! mount ) {
		return;
	}

	const config = window.acrossaiMcpAccessControl || {};
	if ( ! config.pluginSlug || ! config.resourceKey ) {
		mount.textContent =
			'Access Control cannot boot — missing pluginSlug or resourceKey.';
		return;
	}

	// Register the REST nonce middleware once so all vendor apiFetch calls carry it.
	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	render(
		createElement( AccessControl, {
			pluginSlug: config.pluginSlug,
			namespace: config.namespace || 'acrossai-mcp-manager',
			resourceKey: config.resourceKey,
			restApiRoot: config.restApiRoot || '/wp-json',
			nonce: config.nonce || '',
			title: config.title || undefined,
			description: config.description || undefined,
			saveLabel: config.saveLabel || undefined,
		} ),
		mount
	);
} )();
