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
 *
 * MVP note (Phase 3B): Step 1-5 + Completion components are stubs today
 * (Phase 3C lands them). The shell renders + navigates correctly; each
 * step body shows a placeholder.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from './components/Notice.jsx';
import StepLayout from './StepLayout.jsx';
import useWizardRouter from './hooks/useWizardRouter.js';
import useWizardState from './hooks/useWizardState.js';

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

/**
 * Placeholder step body (Phase 3B stub — real step components ship in Phase 3C).
 */
const StepPlaceholder = ( { step } ) => (
	<div>
		<h2 className="qs__step-title">
			{ __( 'Step', 'acrossai-mcp-manager' ) } { step }
		</h2>
		<p className="qs__step-subtitle">
			{ __(
				'This step will render its content once Phase 3C ships the individual step components.',
				'acrossai-mcp-manager'
			) }
		</p>
	</div>
);

const CompletionPlaceholder = () => (
	<div>
		<h2 className="qs__step-title">
			{ __( "You're all set!", 'acrossai-mcp-manager' ) }
		</h2>
		<p className="qs__step-subtitle">
			{ __(
				'Completion screen ships in Phase 3C.',
				'acrossai-mcp-manager'
			) }
		</p>
	</div>
);

/**
 * Step-registry — maps URL step value to the component to render.
 * Phase 3C will swap StepPlaceholder for the real Step1_ServerPick,
 * Step2_AccessControl, etc.
 */
const stepRegistry = {
	'1': ( props ) => <StepPlaceholder step="1" { ...props } />,
	'2': ( props ) => <StepPlaceholder step="2" { ...props } />,
	'3': ( props ) => <StepPlaceholder step="3" { ...props } />,
	'4': ( props ) => <StepPlaceholder step="4" { ...props } />,
	'5': ( props ) => <StepPlaceholder step="5" { ...props } />,
	'done': () => <CompletionPlaceholder />,
};

const App = () => {
	const router = useWizardRouter();
	const { state, isLoading, error, refetch, clearError } = useWizardState();

	// Hydrate on mount.
	useEffect( () => {
		if ( state.status === 'idle' ) {
			refetch();
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Step 4 auto-skip (US4) — jump 3→5 forward, 5→3 back when the
	// selected server is already enabled.
	useEffect( () => {
		if ( state.status !== 'ready' ) {
			return;
		}
		if ( router.step === '4' && state.wizardState.enabled === true ) {
			// User is on step 4 but the server is already enabled — skip.
			router.goTo( '5' );
		}
	}, [ router.step, state.status, state.wizardState.enabled, router ] );

	// US5 deep-link precondition guard — silent redirect to furthest legit step.
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

	// Total steps for progress bar — 4 when Step 4 is auto-skipped.
	const totalSteps = useMemo( () => {
		return state.wizardState.enabled === true ? 4 : 5;
	}, [ state.wizardState.enabled ] );

	// Which step index within the reduced total to display.
	const displayIndex = useMemo( () => {
		if ( router.step === 'done' ) {
			return totalSteps;
		}
		let idx = parseInt( router.step, 10 );
		if ( state.wizardState.enabled === true && idx > 4 ) {
			idx -= 1; // account for skipped step 4.
		}
		return idx;
	}, [ router.step, totalSteps, state.wizardState.enabled ] );

	const renderCurrentStep = () => {
		const factory = stepRegistry[ router.step ] || stepRegistry[ '1' ];
		return factory( { state, router } );
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

	return (
		<StepLayout
			step={ router.step }
			displayIndex={ displayIndex }
			totalSteps={ totalSteps }
			isLoading={ isLoading }
			canAdvance={ true /* Phase 3C: per-step guards via context */ }
			onBack={ () =>
				router.back( { skipStep4: state.wizardState.enabled === true } )
			}
			onAdvance={ () =>
				router.advance( { skipStep4: state.wizardState.enabled === true } )
			}
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
	);
};

export default App;
