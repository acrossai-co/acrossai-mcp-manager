/**
 * F069 — Step 9: AcrossAI Pro activation gate.
 *
 * Only rendered when the user picked "One-click OAuth (Connectors)" on
 * Step 7 AND `state.plugins.acrossaiPro === 'inactive'` (installed but not
 * active). App.jsx's skipProActivate predicate handles show/skip.
 *
 * Primary CTA links to the AcrossAI Add-ons admin page (per user spec) so
 * the user can activate Pro. The Continue button is disabled — once the
 * user activates Pro and returns to this tab, the reload note explains
 * how to get the wizard to re-check. If Pro flips to 'active' via a focus
 * refetch (useWizardState's window focus listener), the auto-skip effect
 * in App.jsx will forward past this step automatically.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard, { useFooterAction } from '../hooks/useAdvanceGuard.js';

const Step9_ProActivate = () => {
	const { refetch } = useWizardState();
	const bootstrap = window.acrossaiMcpQuickSetup || {};
	const [ checking, setChecking ] = useState( false );

	// URL to the AcrossAI Add-ons admin page — where the user goes to
	// activate Pro. Falls back to the wp-admin root if the site URL is
	// somehow missing (defensive; shouldn't happen in practice).
	const addonsUrl =
		bootstrap.addonsUrl ||
		`${ bootstrap.siteUrl || '' }/wp-admin/admin.php?page=acrossai-addons`;

	// Continue stays disabled until Pro is active.
	useAdvanceGuard( false );

	// NOTE: no refetch-on-mount. useWizardState already registers global
	// visibilitychange + focus + popstate listeners that catch the "user
	// activated Pro in another tab and came back" case. A local refetch
	// here would flip state.status to 'loading', which triggers App.jsx's
	// early-return to the initial-loading icon → this step unmounts → the
	// effect re-fires on remount → infinite loop / blank screen.

	const handleReloadCheck = useCallback( async () => {
		setChecking( true );
		try {
			await refetch();
		} finally {
			setChecking( false );
		}
	}, [ refetch ] );

	// Register the re-check button in the footer between Back and Continue.
	// StepLayout auto-styles a footerAction as PRIMARY and collapses the
	// built-in Continue to SECONDARY — matches the intent that re-check is
	// the action that unlocks the wizard (Continue is dormant until Pro
	// flips to active).
	const footerAction = useMemo(
		() => ( {
			label: checking
				? __( 'Checking…', 'acrossai-mcp-manager' )
				: __( "I've activated it — re-check", 'acrossai-mcp-manager' ),
			onClick: handleReloadCheck,
			isLoading: checking,
			disabled: checking,
		} ),
		[ checking, handleReloadCheck ]
	);
	useFooterAction( footerAction );

	return (
		<div>
			<h2 className="qs__step-title">
				{ __(
					'Activate AcrossAI Pro',
					'acrossai-mcp-manager'
				) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'AcrossAI Pro is installed on this site but not activated. Activate it to unlock one-click OAuth connectors.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div style={ { display: 'flex', gap: 12, marginBottom: 20 } }>
				<a
					className="qs-btn"
					href={ addonsUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Go to Add-ons page ↗', 'acrossai-mcp-manager' ) }
				</a>
			</div>

			<Notice status="info">
				{ __(
					'After activating AcrossAI Pro on the Add-ons page, come back to this wizard tab and click "I\'ve activated it — re-check" in the footer below. Once the wizard detects the active plugin, Continue will unlock.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step9_ProActivate;
