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

import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

const Step9_ProActivate = () => {
	const { refetch } = useWizardState();
	const bootstrap = window.acrossaiMcpQuickSetup || {};

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

	const handleReloadCheck = () => {
		refetch();
	};

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
				<button
					type="button"
					className="qs-btn qs-btn--secondary"
					onClick={ handleReloadCheck }
				>
					{ __( 'I\'ve activated it — re-check', 'acrossai-mcp-manager' ) }
				</button>
			</div>

			<Notice status="info">
				{ __(
					'After activating AcrossAI Pro on the Add-ons page, come back to this wizard tab and reload it — or click "I\'ve activated it — re-check" above. Once the wizard detects the active plugin, Continue will unlock.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step9_ProActivate;
