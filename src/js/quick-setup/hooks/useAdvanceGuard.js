/**
 * F069 — Per-step advance-guard context.
 *
 * Each step component may set its `canAdvance` boolean via the shared
 * WizardGuardContext. App.jsx reads it and drives the Continue button's
 * disabled + aria-disabled state. Contract from contracts/react-router.md
 * § Step guard contract.
 *
 * @package AcrossAI_MCP_Manager
 */

import { createContext, useContext, useEffect } from '@wordpress/element';

export const WizardGuardContext = createContext( {
	setCanAdvance: () => {},
} );

/**
 * Called from a step component:
 *   useAdvanceGuard( wizardState.server_id !== null );
 */
const useAdvanceGuard = ( canAdvance ) => {
	const { setCanAdvance } = useContext( WizardGuardContext );
	useEffect( () => {
		setCanAdvance( !! canAdvance );
		// On unmount, reset to `true` so the next step doesn't inherit
		// a stale disabled state.
		return () => setCanAdvance( true );
	}, [ canAdvance, setCanAdvance ] );
};

export default useAdvanceGuard;
