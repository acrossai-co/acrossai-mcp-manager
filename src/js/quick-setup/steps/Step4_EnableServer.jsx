/**
 * F069 T034 — Step 4: Enable the server.
 *
 * Auto-skip case handled by App.jsx (US4) — if server already enabled,
 * App.jsx redirects step=4 → step=5 before this component ever renders.
 * When it DOES render, the server is disabled. Toggle-on posts step 4
 * with enabled=true.
 *
 * Advance guard: canAdvance = wizardState.enabled === true.
 *
 * @package AcrossAI_MCP_Manager
 */

import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

const Step4_EnableServer = () => {
	const { state, saveStep, isLoading } = useWizardState();
	const enabled = state.wizardState.enabled;

	useAdvanceGuard( enabled === true );

	const handleToggle = async () => {
		await saveStep( 4, { enabled: ! enabled } );
	};

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Turn the endpoint on', 'acrossai-mcp-manager' ) }
			</h2>

			<Notice status="warning">
				<div>
					<strong>
						{ __( 'Your server is currently disabled.', 'acrossai-mcp-manager' ) }
					</strong>
					<div style={ { marginTop: 4 } }>
						{ __(
							'While disabled it rejects every MCP request, even from administrators.',
							'acrossai-mcp-manager'
						) }
					</div>
				</div>
			</Notice>

			<div
				style={ {
					padding: '22px 24px',
					background: '#fcf9e8',
					borderRadius: 2,
					marginBottom: 20,
					borderLeft: '4px solid #f0b849',
				} }
			>
				<label
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: 12,
						cursor: 'pointer',
					} }
				>
					<input
						type="checkbox"
						checked={ enabled === true }
						onChange={ handleToggle }
						disabled={ isLoading }
						style={ { width: 20, height: 20 } }
					/>
					<span style={ { fontSize: 14, fontWeight: 500 } }>
						{ isLoading
							? __( 'Updating…', 'acrossai-mcp-manager' )
							: enabled
							? __( 'Server is enabled ✓', 'acrossai-mcp-manager' )
							: __( 'Enable this server', 'acrossai-mcp-manager' ) }
					</span>
				</label>
			</div>

			<p className="qs__step-subtitle">
				{ __(
					'You can disable it anytime from the server list.',
					'acrossai-mcp-manager'
				) }
			</p>
		</div>
	);
};

export default Step4_EnableServer;
