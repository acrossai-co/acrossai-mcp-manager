/**
 * F069 — URL-driven wizard router hook.
 *
 * Contract from `contracts/react-router.md`:
 *   const { step, method, goTo, advance, back, exit } = useWizardRouter();
 *
 * - `step` and `method` are strings read from window.location.search via
 *   @wordpress/url `getQueryArg`. Source of truth is the URL (FR-008).
 * - `goTo`, `advance`, `back` write via `history.pushState` + `addQueryArgs`
 *   — no full page reload. Popstate listener keeps state in sync with
 *   browser Back/Forward navigation.
 * - `exit` navigates (full nav) to the plugin's list-table URL (from
 *   window.acrossaiMcpQuickSetup.adminUrl).
 * - `advance`/`back` respect Step 4 auto-skip when `enabled=true` is
 *   passed in via the second arg (delegates the decision to the caller —
 *   the hook doesn't know about wizardState, keeps concerns separate).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getQueryArg, addQueryArgs, removeQueryArgs } from '@wordpress/url';

const STEP_ORDER = [
	'1', '2', '3', '4', '5', '6', '7',
	'8', '9', '10', '11', '12', '13',
	'done',
];

/**
 * Conditional steps skipped depending on wizard state:
 *   - 2  (server create) — skip unless user chose "Create new" in step 1
 *   - 4  (abilities-manager gate) — skip when plugin is already active
 *   - 5  (abilities picker) — skip when all abilities are already enabled
 *   - 6  (enable endpoint) — skip when server is already enabled
 *   - 8  (Pro pitch) — skip unless method=connectors AND pro state=missing
 *   - 9  (Pro activate) — skip unless method=connectors AND pro state=inactive
 *   - 10 (Connectors detail) — skip unless method=connectors AND pro state=active
 *   - 11 (Client detail) — skip unless method=client
 *   - 12 (npm detail) — skip unless method=npm
 *   - 13 (WP-CLI detail) — skip unless method=wpcli
 *
 * Callers pass a `skips` object with matching boolean flags to advance/back so
 * the router can walk past unwanted steps in either direction. The hook stays
 * ignorant of wizardState — that lookup belongs in App.jsx.
 */
const shouldSkip = ( step, skips ) => {
	if ( step === '2' && skips.skipCreate ) return true;
	if ( step === '4' && skips.skipAbilitiesGate ) return true;
	if ( step === '5' && skips.skipAbilities ) return true;
	if ( step === '6' && skips.skipEnable ) return true;
	if ( step === '8' && skips.skipProPromo ) return true;
	if ( step === '9' && skips.skipProActivate ) return true;
	if ( step === '10' && skips.skipConnectorsDetail ) return true;
	if ( step === '11' && skips.skipClient ) return true;
	if ( step === '12' && skips.skipNpm ) return true;
	if ( step === '13' && skips.skipWpcli ) return true;
	return false;
};

const readParams = () => {
	const search = window.location.search || '';
	// getQueryArg returns undefined for missing params; normalize to null/string.
	const rawStep = getQueryArg( search, 'step' );
	const rawMethod = getQueryArg( search, 'method' );
	const rawMode = getQueryArg( search, 'mode' );
	const rawServer = getQueryArg( search, 'server' );
	const parsedServer = rawServer ? parseInt( rawServer, 10 ) : NaN;
	return {
		step: ( rawStep === undefined || rawStep === '' ) ? '1' : String( rawStep ),
		method: ( rawMethod === undefined || rawMethod === '' ) ? null : String( rawMethod ),
		mode: ( rawMode === undefined || rawMode === '' ) ? null : String( rawMode ),
		server: Number.isFinite( parsedServer ) && parsedServer > 0 ? parsedServer : null,
	};
};

const buildUrl = ( step, method, mode, server ) => {
	// Preserve existing query params (page, quick-setup, first_run) — only
	// mutate step + method + mode + server.
	//
	// IMPORTANT: `addQueryArgs` writes empty strings as `key=` (visible noise
	// in the URL). Use `removeQueryArgs` to drop absent optional params instead.
	let url = window.location.pathname + window.location.search;
	url = addQueryArgs( url, { step } );
	url = method
		? addQueryArgs( url, { method } )
		: removeQueryArgs( url, 'method' );
	url = mode
		? addQueryArgs( url, { mode } )
		: removeQueryArgs( url, 'mode' );
	url = server
		? addQueryArgs( url, { server } )
		: removeQueryArgs( url, 'server' );
	return url;
};

const useWizardRouter = () => {
	const [ params, setParams ] = useState( readParams );

	// Popstate listener — sync when browser Back/Forward walks history.
	useEffect( () => {
		const onPopState = () => setParams( readParams() );
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	const goTo = useCallback( ( step, method = null ) => {
		if ( ! STEP_ORDER.includes( step ) ) {
			return;
		}
		// Step navigation always clears mode (mode is a per-step sub-view).
		// Preserve the current server param so it survives navigation.
		setParams( ( prev ) => {
			const nextUrl = buildUrl( step, method, null, prev.server );
			window.history.pushState( {}, '', nextUrl );
			return { step, method, mode: null, server: prev.server };
		} );
	}, [] );

	// Set/clear a per-step sub-view mode (e.g. `?mode=create` for Step 1's
	// inline server-create form). URL-backed so browser Back returns to the
	// picker instead of walking all the way out of Step 1.
	const setMode = useCallback( ( mode ) => {
		setParams( ( prev ) => {
			const next = { ...prev, mode: mode || null };
			const nextUrl = buildUrl( prev.step, prev.method, next.mode, prev.server );
			window.history.pushState( {}, '', nextUrl );
			return next;
		} );
	}, [] );

	// Sync server id into the URL. Used by App.jsx whenever wizardState's
	// server_id changes (e.g. after Step 1 pick or Step 2 create) so that
	// steps 2+ carry `?server=<id>` for shareable deep-links. `replaceState`
	// (not push) so we don't clutter the browser history with server-id-only
	// changes — the step navigation entries are what belong in history.
	const setServer = useCallback( ( server ) => {
		setParams( ( prev ) => {
			const normalized =
				Number.isFinite( server ) && server > 0 ? server : null;
			if ( prev.server === normalized ) {
				return prev; // no-op — avoids an extra history entry / rerender.
			}
			const nextUrl = buildUrl( prev.step, prev.method, prev.mode, normalized );
			window.history.replaceState( {}, '', nextUrl );
			return { ...prev, server: normalized };
		} );
	}, [] );

	const advance = useCallback( ( { skips = {} } = {} ) => {
		const idx = STEP_ORDER.indexOf( params.step );
		if ( idx === -1 || idx >= STEP_ORDER.length - 1 ) {
			return;
		}
		let nextIdx = idx + 1;
		// Walk past every consecutive step whose skip predicate is truthy —
		// e.g. Step 3 → Step 5 when abilities-manager is active AND server
		// is enabled would jump 3 → 4 (skipped) → 5 (skipped) → 6? No, 6 is
		// enable, also skipped → 7. The while-loop handles all such chains.
		while (
			nextIdx < STEP_ORDER.length - 1 &&
			shouldSkip( STEP_ORDER[ nextIdx ], skips )
		) {
			nextIdx += 1;
		}
		goTo( STEP_ORDER[ nextIdx ], null );
	}, [ params.step, goTo ] );

	const back = useCallback( ( { skips = {} } = {} ) => {
		const idx = STEP_ORDER.indexOf( params.step );
		if ( idx <= 0 ) {
			return;
		}
		let prevIdx = idx - 1;
		while ( prevIdx > 0 && shouldSkip( STEP_ORDER[ prevIdx ], skips ) ) {
			prevIdx -= 1;
		}
		goTo( STEP_ORDER[ prevIdx ], null );
	}, [ params.step, goTo ] );

	const exit = useCallback( () => {
		const bootstrap = window.acrossaiMcpQuickSetup || {};
		const target = bootstrap.adminUrl || '/wp-admin/admin.php?page=acrossai_mcp_manager';
		window.location.href = target;
	}, [] );

	return {
		step: params.step,
		method: params.method,
		mode: params.mode,
		server: params.server,
		goTo,
		setMode,
		setServer,
		advance,
		back,
		exit,
	};
};

export default useWizardRouter;
