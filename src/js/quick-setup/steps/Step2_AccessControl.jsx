/**
 * F069 T032 — Step 2: Access Control editor.
 *
 * Mounts the shared <AccessControlEditor> component (extracted from
 * src/js/access-control.js in T032 pre-work) scoped to the wizard's
 * currently-selected server. The apiFetch nonce middleware is already
 * wired at src/js/quick-setup.js entry (T026 / TASK-SEC-005), so this
 * component doesn't re-wire it (avoids double-header per F015 bootstrap
 * rationale).
 *
 * Above the editor: <Notice> with the EXACT F042 admin-only banner text
 * copy-pasted from admin/Partials/ServerTabs/AccessControlTab.php.
 *
 * No advance guard — spec FR-014 permits advancing without a rule change
 * ("no rule" state keeps the server admin-only, which is a valid final
 * state per F042).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AccessControlEditor from '../../access-control/AccessControlEditor.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const Step2_AccessControl = () => {
	const { state } = useWizardState();
	const bootstrap = window.acrossaiMcpQuickSetup || {};

	const server = useMemo(
		() =>
			state.servers.find(
				( s ) => s.id === state.wizardState.server_id
			),
		[ state.servers, state.wizardState.server_id ]
	);

	if ( ! server ) {
		return (
			<div>
				<h2 className="qs__step-title">
					{ __( 'Who can reach it?', 'acrossai-mcp-manager' ) }
				</h2>
				<p>
					{ __(
						'No server selected — go back to Step 1.',
						'acrossai-mcp-manager'
					) }
				</p>
			</div>
		);
	}

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Who can reach this server?', 'acrossai-mcp-manager' ) }
			</h2>

			{ /* F042 admin-only banner — EXACT copy from AccessControlTab.php,
			     do not paraphrase */ }
			<Notice status="info">
				{ __(
					'Default policy: administrators only. When the "Who can access" dropdown below reads "No user access added by admin" (no rule configured), only users with the manage_options capability (WordPress administrators) can reach this server\'s MCP endpoint. Set any rule below — Anyone / Authenticated users / a role / a user / a capability — to broaden access. Enforced at request time via a runtime filter; no database access-control rules are seeded automatically.',
					'acrossai-mcp-manager'
				) }
			</Notice>

			<AccessControlEditor
				pluginSlug="acrossai-mcp-manager"
				namespace="acrossai-mcp-manager"
				resourceKey={ server.slug }
				restApiRoot={ '/wp-json' }
				nonce={ bootstrap.restNonce || '' }
			/>

			<p className="qs__step-subtitle" style={ { marginTop: 24 } }>
				{ __(
					'You can change this anytime under the server\'s Access Control tab.',
					'acrossai-mcp-manager'
				) }
			</p>
		</div>
	);
};

export default Step2_AccessControl;
