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
import { getQueryArg, addQueryArgs } from '@wordpress/url';

const STEP_ORDER = [ '1', '2', '3', '4', '5', 'done' ];

const readParams = () => {
	const search = window.location.search || '';
	// getQueryArg returns undefined for missing params; normalize to null/string.
	const rawStep = getQueryArg( search, 'step' );
	const rawMethod = getQueryArg( search, 'method' );
	return {
		step: ( rawStep === undefined || rawStep === '' ) ? '1' : String( rawStep ),
		method: ( rawMethod === undefined || rawMethod === '' ) ? null : String( rawMethod ),
	};
};

const buildUrl = ( step, method ) => {
	// Preserve existing query params (page, quick-setup, first_run) — only
	// mutate step + method. addQueryArgs merges, so this is safe.
	const args = { step };
	if ( method ) {
		args.method = method;
	} else {
		// Explicitly clear method when not provided (empty string → drops key).
		args.method = '';
	}
	return addQueryArgs( window.location.pathname + window.location.search, args );
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
		const nextUrl = buildUrl( step, method );
		window.history.pushState( {}, '', nextUrl );
		setParams( { step, method } );
	}, [] );

	const advance = useCallback( ( { skipStep4 = false } = {} ) => {
		const idx = STEP_ORDER.indexOf( params.step );
		if ( idx === -1 || idx >= STEP_ORDER.length - 1 ) {
			return;
		}
		let nextIdx = idx + 1;
		// Auto-skip step 4 if the caller signals enabled=true (spec FR-017).
		if ( skipStep4 && STEP_ORDER[ nextIdx ] === '4' ) {
			nextIdx += 1;
		}
		goTo( STEP_ORDER[ nextIdx ], null );
	}, [ params.step, goTo ] );

	const back = useCallback( ( { skipStep4 = false } = {} ) => {
		const idx = STEP_ORDER.indexOf( params.step );
		if ( idx <= 0 ) {
			return;
		}
		let prevIdx = idx - 1;
		if ( skipStep4 && STEP_ORDER[ prevIdx ] === '4' ) {
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
		goTo,
		advance,
		back,
		exit,
	};
};

export default useWizardRouter;
