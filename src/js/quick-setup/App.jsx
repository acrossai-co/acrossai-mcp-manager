/**
 * F069 — Wizard root component.
 *
 * Feature 069 T027 — Top-level orchestrator:
 *   - On mount, hydrates from `GET /quick-setup/state`.
 *   - Reads `step` + `method` from URL via useWizardRouter().
 *   - Handles Step 4 auto-skip (US4): if selected server already enabled,
 *     jumps step 3 → 5 on advance / step 5 → 3 on back.
 *   - Handles US5 deep-link precondition guard: if the URL lands on a step
 *     whose precondition is unmet (no server_id for step 2+), silently
 *     redirects to the furthest legitimate step.
 *   - Renders <StepLayout>{stepComponent}</StepLayout>.
 *   - Provides WizardGuardContext so per-step `useAdvanceGuard()` calls
 *     can lift their `canAdvance` state up to the Continue button.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useEffect, useMemo, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from './components/Notice.jsx';
import StepLayout from './StepLayout.jsx';
import useWizardRouter from './hooks/useWizardRouter.js';
import useWizardState from './hooks/useWizardState.js';
import { WizardGuardContext } from './hooks/useAdvanceGuard.js';

import Step1_ServerPick from './steps/Step1_ServerPick.jsx';
import Step2_AccessControl from './steps/Step2_AccessControl.jsx';
import Step3_Abilities from './steps/Step3_Abilities.jsx';
import Step4_EnableServer from './steps/Step4_EnableServer.jsx';
import Step5_MethodGrid from './steps/Step5_MethodGrid.jsx';
import Completion from './steps/Completion.jsx';

/**
 * Return the furthest step the current wizardState legitimately supports.
 * Guards the US5 deep-link case.
 */
const furthestLegitimateStep = ( wizardState ) => {
	if ( ! wizardState.server_id ) {
		return '1';
	}
	// Everything past step 1 is unlocked once a server is chosen.
	return null; // null → no forced redirect.
};

const stepRegistry = {
	'1': () => <Step1_ServerPick />,
	'2': () => <Step2_AccessControl />,
	'3': () => <Step3_Abilities />,
	'4': () => <Step4_EnableServer />,
	'5': () => <Step5_MethodGrid />,
	'done': () => <Completion />,
};

const App = () => {
	const router = useWizardRouter();
	const { state, isLoading, error, refetch, clearError } = useWizardState();
	const [ canAdvance, setCanAdvance ] = useState( true );

	// Hydrate on mount.
	useEffect( () => {
		if ( state.status === 'idle' ) {
			refetch();
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Step 4 auto-skip (US4).
	useEffect( () => {
		if ( state.status !== 'ready' ) {
			return;
		}
		if ( router.step === '4' && state.wizardState.enabled === true ) {
			router.goTo( '5' );
		}
	}, [ router.step, state.status, state.wizardState.enabled, router ] );

	// US5 deep-link precondition guard.
	useEffect( () => {
		if ( state.status !== 'ready' ) {
			return;
		}
		if ( router.step === '1' || router.step === 'done' ) {
			return;
		}
		const forced = furthestLegitimateStep( state.wizardState );
		if ( forced && forced !== router.step ) {
			router.goTo( forced );
		}
	}, [ router.step, state.status, state.wizardState, router ] );

	const totalSteps = useMemo( () => {
		return state.wizardState.enabled === true ? 4 : 5;
	}, [ state.wizardState.enabled ] );

	const displayIndex = useMemo( () => {
		if ( router.step === 'done' ) {
			return totalSteps;
		}
		let idx = parseInt( router.step, 10 );
		if ( state.wizardState.enabled === true && idx > 4 ) {
			idx -= 1;
		}
		return idx;
	}, [ router.step, totalSteps, state.wizardState.enabled ] );

	const renderCurrentStep = () => {
		const factory = stepRegistry[ router.step ] || stepRegistry[ '1' ];
		return factory();
	};

	// Loading screen (initial hydrate).
	if ( state.status === 'idle' || state.status === 'loading' ) {
		return (
			<div className="acrossai-mcp-quick-setup-wrap">
				<div className="qs__content">
					<p>{ __( 'Loading setup wizard…', 'acrossai-mcp-manager' ) }</p>
				</div>
			</div>
		);
	}

	// Memoize the context value so consumers don't rebuild on every parent render.
	const guardContext = useMemo(
		() => ( { setCanAdvance } ),
		[ setCanAdvance ]
	);

	const handleContinue = useCallback( async () => {
		if ( router.step === '5' ) {
			// Finish button — /complete + navigate to done.
			router.goTo( 'done' );
			return;
		}
		router.advance( { skipStep4: state.wizardState.enabled === true } );
	}, [ router, state.wizardState.enabled ] );

	return (
		<WizardGuardContext.Provider value={ guardContext }>
			<StepLayout
				step={ router.step }
				displayIndex={ displayIndex }
				totalSteps={ totalSteps }
				isLoading={ isLoading }
				canAdvance={ canAdvance }
				onBack={ () =>
					router.back( { skipStep4: state.wizardState.enabled === true } )
				}
				onAdvance={ handleContinue }
				onExit={ router.exit }
			>
				{ error && (
					<Notice status="error">
						{ error.message }
						{ ' ' }
						<button
							type="button"
							className="qs-btn qs-btn--link"
							onClick={ clearError }
						>
							{ __( 'Dismiss', 'acrossai-mcp-manager' ) }
						</button>
					</Notice>
				) }
				{ renderCurrentStep() }
			</StepLayout>
		</WizardGuardContext.Provider>
	);
};

export default App;
