/**
 * F069 — Wizard shell (header + progress bar + content pane + footer).
 *
 * Feature 069 T028 + T029 — Shared chrome for every step:
 *   - Header: logo + "Quick Setup" title + "Exit setup" link right-aligned.
 *   - Progress bar: role="progressbar" + aria-valuenow / aria-valuemin /
 *     aria-valuemax for WCAG 2.1 AA (FR-010a). Fill width = displayIndex/
 *     totalSteps × 100%.
 *   - Content pane: children rendered inside a scrollable region. On
 *     step change, focus moves to the first focusable element (FR-010a),
 *     and the new step label is announced via aria-live="polite".
 *   - Footer: Back button (disabled on step 1); Continue button
 *     (label swaps to "Finish" on step 5; hidden on 'done').
 *
 * @package AcrossAI_MCP_Manager
 */

import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const STEP_TITLES = {
	'1': 'Choose a server',
	'2': 'Create a new server',
	'3': 'Choose access',
	'4': 'Enable Abilities Manager',
	'5': 'Enable abilities',
	'6': 'Enable server',
	'7': 'Pick a connection method',
	'8': 'Get AcrossAI Pro',
	'9': 'Activate AcrossAI Pro',
	'10': 'One-click OAuth setup',
	'11': 'MCP Client setup',
	'12': 'npm setup',
	'13': 'WP-CLI setup',
	'done': "You're all set!",
};

const StepLayout = ( {
	step,
	displayIndex,
	totalSteps,
	isLoading,
	canAdvance,
	footerAction,
	hideContinue,
	onBack,
	onAdvance,
	onExit,
	children,
} ) => {
	const bootstrap = window.acrossaiMcpQuickSetup || {};
	const contentRef = useRef( null );
	const liveRef = useRef( null );

	// Focus mgmt (FR-010a) — on step change, focus the first interactive
	// element inside the content pane so keyboard-only users land in the
	// right place.
	useEffect( () => {
		if ( ! contentRef.current ) {
			return;
		}
		const focusable = contentRef.current.querySelector(
			'input, button, select, textarea, [tabindex]:not([tabindex="-1"]), [role="radio"]'
		);
		if ( focusable ) {
			focusable.focus();
		}
	}, [ step ] );

	// ARIA live-region announcement (FR-010a) — announce step changes to
	// assistive tech. Small delay so the announcement fires AFTER focus
	// settles, avoiding a double-announcement.
	useEffect( () => {
		if ( ! liveRef.current ) {
			return;
		}
		const title = STEP_TITLES[ step ] || step;
		const message =
			step === 'done'
				? title
				: /* translators: 1: current step number, 2: total steps, 3: step title */
				  __( 'Step %1$d of %2$d, %3$s', 'acrossai-mcp-manager' )
						.replace( '%1$d', String( displayIndex ) )
						.replace( '%2$d', String( totalSteps ) )
						.replace( '%3$s', title );
		liveRef.current.textContent = '';
		const t = setTimeout( () => {
			if ( liveRef.current ) {
				liveRef.current.textContent = message;
			}
		}, 100 );
		return () => clearTimeout( t );
	}, [ step, displayIndex, totalSteps ] );

	const progressPct = Math.min(
		100,
		Math.max( 0, Math.round( ( displayIndex / totalSteps ) * 100 ) )
	);

	const backDisabled = step === '1' || step === 'done';
	const isDone = step === 'done';
	// Terminal detail steps — after these, Continue → Finish → done.
	const isLast = [ '10', '11', '12', '13' ].includes( step );

	const continueLabel = isLast
		? __( 'Finish', 'acrossai-mcp-manager' )
		: __( 'Continue', 'acrossai-mcp-manager' );

	// Any async in flight (wizard-level saveStep/refetch/complete OR the
	// current step's registered footerAction). Drives BOTH the full-screen
	// pulsing-logo overlay AND the disabled state of all three footer
	// buttons. Consolidating loading UI into one full-screen overlay means:
	//   - Continue / Back / footerAction all share the same "please wait"
	//     signal — no more three-way inconsistency between inline spinners
	//   - The pulsing brand icon is more legible than a tiny button spinner
	//   - Overlay blocks all interaction, preventing the double-fire and
	//     mid-request-back-click races we used to defend against per-button
	const busy = isLoading || !! footerAction?.isLoading;

	return (
		<>
			{ busy && bootstrap.iconUrl && (
				<div
					className="qs__initial-loading qs__initial-loading--overlay"
					role="alert"
					aria-live="assertive"
					aria-busy="true"
				>
					<img
						className="qs__initial-loading-icon"
						src={ bootstrap.iconUrl }
						alt=""
						aria-hidden="true"
					/>
					<span className="qs__sr-only">
						{ footerAction?.isLoading && footerAction?.label
							? footerAction.label
							: __( 'Saving…', 'acrossai-mcp-manager' ) }
					</span>
				</div>
			) }

			<div
				className="qs__progress"
				role="progressbar"
				aria-valuenow={ displayIndex }
				aria-valuemin={ 1 }
				aria-valuemax={ totalSteps }
				aria-label={ __( 'Wizard progress', 'acrossai-mcp-manager' ) }
			>
				<span
					className="qs__progress-fill"
					style={ { width: `${ progressPct }%` } }
				/>
			</div>

			<header className="qs__header">
				{ bootstrap.logoUrl && (
					<img
						className="qs__header-logo"
						src={ bootstrap.logoUrl }
						alt="AcrossAI"
					/>
				) }
				<span className="qs__header-title">
					{ __( 'Quick Setup', 'acrossai-mcp-manager' ) }
				</span>
				{ ! isDone && (
					<a
						className="qs__header-exit"
						href="#"
						onClick={ ( e ) => {
							e.preventDefault();
							onExit?.();
						} }
					>
						{ __( 'Exit setup', 'acrossai-mcp-manager' ) }
					</a>
				) }
			</header>

			<div className="qs__content" ref={ contentRef }>
				{ /* Step counter intentionally not rendered — the progress bar
				     above already communicates position; a redundant
				     "STEP X OF Y" line above every heading crowded the layout.
				     The counter is still emitted into the ARIA live region
				     below (unchanged) so screen readers announce it on
				     navigation. */ }
				{ children }

				{ ! isDone && ( () => {
					// Loading UI is the full-screen overlay above the wrap.
					// All three footer buttons stay locked while `busy` so
					// the user can't double-fire an install, click Back
					// mid-request and orphan the in-flight write, or click
					// Continue and race the auto-skip effect.
					const backLocked = backDisabled || busy;
					const continueLocked =
						! canAdvance || busy || !! footerAction?.disabled;
					const footerActionLocked =
						!! footerAction?.disabled || !! footerAction?.isLoading;

					return (
						<footer className="qs__footer">
							<button
								type="button"
								className="qs-btn qs-btn--secondary"
								disabled={ backLocked }
								aria-disabled={ backLocked }
								onClick={ backLocked ? undefined : onBack }
							>
								{ __( 'Back', 'acrossai-mcp-manager' ) }
							</button>
							{ /* When a step registers a footerAction (Step 5's
							     "Enable all and continue"), Continue collapses
							     to secondary so the primary blue button is the
							     recommended combined action. Otherwise Continue
							     stays primary.
							     Step 6 hides Continue entirely via
							     useHideContinue(true) — the only legitimate
							     forward path there is "Enable & Continue", and
							     a disabled Continue would just invite confused
							     clicking. */ }
							{ ! hideContinue && (
								<button
									type="button"
									className={
										footerAction
											? 'qs-btn qs-btn--secondary'
											: 'qs-btn'
									}
									aria-disabled={ continueLocked }
									onClick={ continueLocked ? undefined : onAdvance }
								>
									{ continueLabel }
								</button>
							) }
							{ footerAction && (
								<button
									type="button"
									className="qs-btn"
									aria-disabled={ footerActionLocked }
									onClick={
										footerActionLocked
											? undefined
											: footerAction.onClick
									}
								>
									{ footerAction.label }
								</button>
							) }
						</footer>
					);
				} )() }
			</div>

			{ /* ARIA live region — visually hidden, screen-reader announces on update */ }
			<div
				className="qs__sr-only"
				ref={ liveRef }
				aria-live="polite"
				aria-atomic="true"
			/>
		</>
	);
};

export default StepLayout;
