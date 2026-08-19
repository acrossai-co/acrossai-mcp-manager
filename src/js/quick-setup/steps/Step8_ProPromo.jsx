/**
 * F069 — Step 8: AcrossAI Pro pitch page.
 *
 * Only rendered when the user picked "One-click OAuth (Connectors)" on Step 7
 * AND `state.plugins.acrossaiPro === 'missing'`. App.jsx's skipProPromo
 * predicate handles the show/skip; this component assumes the state has
 * already been vetted.
 *
 * Full-page pitch that pairs the trial promo bar (already on Step 7) with
 * the value proposition and a "Start free trial" CTA to the pricing page.
 * Continue is disabled — the user must install and activate acrossai-pro
 * (which flips `plugins.acrossaiPro` to 'active') to advance. The reload-
 * after-install note tells the user how to get the wizard to re-check.
 *
 * @package AcrossAI_MCP_Manager
 */

import { __, sprintf } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

const TRIAL_URL = 'https://acrossai.co/pricing/#pricing';

const Step8_ProPromo = () => {
	const { state } = useWizardState();
	const trialEndDate = state.plugins.trialEndDate || '';

	// Continue stays disabled — user has to install & activate Pro for the
	// wizard to advance. Auto-skip in App.jsx forwards past this step
	// once `plugins.acrossaiPro` flips to 'inactive' or 'active'.
	useAdvanceGuard( false );

	// NOTE: no refetch-on-mount. useWizardState already registers global
	// visibilitychange + focus + popstate listeners that catch the "user
	// installed Pro in another tab and came back" case. A local refetch
	// here would flip state.status to 'loading', which triggers App.jsx's
	// early-return to the initial-loading icon → this step unmounts → the
	// effect re-fires on remount → infinite loop / blank screen.

	return (
		<div>
			<h2 className="qs__step-title">
				{ __(
					'One-click OAuth needs AcrossAI Pro',
					'acrossai-mcp-manager'
				) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Paste one URL into Claude, ChatGPT, Grok, or Gemini and approve — no config files, no JSON. This connector requires the AcrossAI Pro plugin.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div
				style={ {
					display: 'flex',
					flexDirection: 'column',
					gap: 24,
					padding: 32,
					background: '#faf9ff',
					border: '1px solid #c4b5fd',
					borderRadius: 4,
					marginBottom: 20,
				} }
			>
				<div style={ { textAlign: 'center' } }>
					<div
						style={ {
							fontSize: 32,
							fontWeight: 700,
							color: '#312e81',
							marginBottom: 8,
						} }
					>
						{ __( '30 days free. No credit card.', 'acrossai-mcp-manager' ) }
					</div>
					<div style={ { fontSize: 14, color: '#4c1d95' } }>
						{ trialEndDate
							? sprintf(
									/* translators: %s: date string */
									__( 'Start today — free through %s.', 'acrossai-mcp-manager' ),
									trialEndDate
							  )
							: __( 'Start today.', 'acrossai-mcp-manager' ) }
					</div>
				</div>

				<ul
					style={ {
						margin: 0,
						padding: 0,
						listStyle: 'none',
						display: 'grid',
						gap: 10,
						color: '#312e81',
					} }
				>
					<li>
						{ __(
							'One-click connectors for Claude, ChatGPT, Grok, Gemini & Cursor.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'Unlimited actions — never metered.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'Runs on your own server — no third-party cloud.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'Access Control — restrict by membership, not just role.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __(
							'1 year of updates.',
							'acrossai-mcp-manager'
						) }
					</li>
					<li>
						{ __( 'Email support.', 'acrossai-mcp-manager' ) }
					</li>
				</ul>

				<div style={ { display: 'flex', justifyContent: 'center' } }>
					<a
						className="qs-btn"
						href={ TRIAL_URL }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Start free trial', 'acrossai-mcp-manager' ) }
					</a>
				</div>
			</div>

			<Notice status="info">
				{ __(
					'After installing and activating AcrossAI Pro, return to this page and reload it. The wizard will detect the plugin and let you continue.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step8_ProPromo;
