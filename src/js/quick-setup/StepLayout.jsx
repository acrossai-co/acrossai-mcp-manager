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
	'2': 'Choose access',
	'3': 'Enable abilities',
	'4': 'Enable server',
	'5': 'Pick a connection method',
	'done': "You're all set!",
};

const StepLayout = ( {
	step,
	displayIndex,
	totalSteps,
	isLoading,
	canAdvance,
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
	const isLast = step === '5';

	const continueLabel = isLast
		? __( 'Finish', 'acrossai-mcp-manager' )
		: __( 'Continue', 'acrossai-mcp-manager' );

	return (
		<>
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
			</header>

			{ ! isDone && (
				<div className="qs__step-counter">
					{ __( 'Step', 'acrossai-mcp-manager' ) } { displayIndex }{ ' ' }
					{ __( 'of', 'acrossai-mcp-manager' ) } { totalSteps }
				</div>
			) }

			<div className="qs__content" ref={ contentRef }>
				{ children }
			</div>

			{ /* ARIA live region — visually hidden, screen-reader announces on update */ }
			<div
				className="qs__sr-only"
				ref={ liveRef }
				aria-live="polite"
				aria-atomic="true"
			/>

			{ ! isDone && (
				<footer className="qs__footer">
					<button
						type="button"
						className="qs-btn qs-btn--secondary"
						disabled={ backDisabled }
						aria-disabled={ backDisabled }
						onClick={ backDisabled ? undefined : onBack }
					>
						{ __( 'Back', 'acrossai-mcp-manager' ) }
					</button>
					<button
						type="button"
						className="qs-btn"
						aria-disabled={ ! canAdvance || isLoading }
						onClick={
							! canAdvance || isLoading ? undefined : onAdvance
						}
					>
						{ isLoading
							? __( 'Saving…', 'acrossai-mcp-manager' )
							: continueLabel }
					</button>
				</footer>
			) }
		</>
	);
};

export default StepLayout;
