/**
 * F069 — Step 3: Access Control editor.
 *
 * Mounts the shared <AccessControlEditor> component scoped to the wizard's
 * currently-selected server. The apiFetch nonce middleware is already
 * wired at src/js/quick-setup.js entry (TASK-SEC-005), so this component
 * doesn't re-wire it (avoids double-header per F015 bootstrap rationale).
 *
 * Save integration (vs. the per-server-edit tab): the vendor's
 * "Save Access Control" button is hidden here and replaced by a wizard
 * footer button registered via useFooterAction. Footer layout on this
 * step is Back · Continue · Save and Continue:
 *   - Continue          → advance without touching the AC rule (leaves
 *                          whatever's saved as-is; safe skip per FR-014)
 *   - Save and Continue → PUT / DELETE the AC rule via the vendor's
 *                          endpoint, then advance
 * Both are valid — the "no rule" state keeps the server admin-only, which
 * is a valid final state per F042.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import AccessControlEditor from '../../access-control/AccessControlEditor.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import { useFooterAction, useWizardAdvance } from '../hooks/useAdvanceGuard.js';

// Vendor sentinel — an AC rule with key === NO_ACCESS means "delete the row".
// Kept in sync with wpb-access-control/js/AccessControl.js:NO_ACCESS constant.
const NO_ACCESS = '_no_access';

const Step3_AccessControl = () => {
	const { state } = useWizardState();
	const advance = useWizardAdvance();
	const bootstrap = window.acrossaiMcpQuickSetup || {};
	const [ saveError, setSaveError ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const server = useMemo(
		() =>
			state.servers.find(
				( s ) => s.id === state.wizardState.server_id
			),
		[ state.servers, state.wizardState.server_id ]
	);

	// Vendor emits onChange once after load AND on every user change. We store
	// the latest tuple in a ref so the footer action sees fresh values without
	// re-registering the callback on every keystroke (which would churn the
	// context's setFooterAction).
	const selectionRef = useRef( { key: null, options: [] } );

	const handleAcChange = useCallback( ( key, options ) => {
		selectionRef.current = {
			key,
			options: Array.isArray( options ) ? options : [],
		};
	}, [] );

	const handleSaveAndContinue = useCallback( async () => {
		setSaveError( null );

		// If onChange never fired (vendor still loading), just advance —
		// admin-only default is a valid final state per F042.
		if ( ! server || selectionRef.current.key === null ) {
			advance();
			return;
		}

		const pluginSlug = bootstrap.acPluginSlug || 'mcp';
		const namespace = bootstrap.acNamespace || 'acrossai-mcp-manager';
		const restApiRoot = bootstrap.restApiRoot || '/wp-json';
		const url = `${ restApiRoot }/wpb-ac/v1/${ pluginSlug }/rules/${ encodeURIComponent(
			namespace
		) }/${ server.slug }`;

		setSaving( true );
		try {
			if ( selectionRef.current.key === NO_ACCESS ) {
				await apiFetch( { url, method: 'DELETE' } );
			} else {
				await apiFetch( {
					url,
					method: 'PUT',
					data: {
						ac_key: selectionRef.current.key,
						ac_options: selectionRef.current.options,
					},
				} );
			}
			advance();
		} catch ( err ) {
			setSaveError(
				( err && err.message ) ||
					__(
						'Failed to save access control. Try again.',
						'acrossai-mcp-manager'
					)
			);
		} finally {
			setSaving( false );
		}
	}, [
		server,
		bootstrap.acPluginSlug,
		bootstrap.acNamespace,
		bootstrap.restApiRoot,
		advance,
	] );

	const footerAction = useMemo(
		() =>
			server
				? {
					label: __( 'Save and Continue', 'acrossai-mcp-manager' ),
					onClick: handleSaveAndContinue,
					isLoading: saving,
					disabled: saving,
				  }
				: null,
		[ server, handleSaveAndContinue, saving ]
	);
	useFooterAction( footerAction );

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

			{ saveError && (
				<Notice status="error">{ saveError }</Notice>
			) }

			<AccessControlEditor
				pluginSlug={ bootstrap.acPluginSlug || 'mcp' }
				namespace={ bootstrap.acNamespace || 'acrossai-mcp-manager' }
				resourceKey={ server.slug }
				restApiRoot={ bootstrap.restApiRoot || '/wp-json' }
				nonce={ bootstrap.restNonce || '' }
				hideSaveButton={ true }
				onChange={ handleAcChange }
			/>

			<p className="qs__step-subtitle" style={ { marginTop: 24 } }>
				{ __(
					'Continue skips saving (leaves current rule as-is). Save and Continue writes your changes and moves on. You can change this anytime under the server\'s Access Control tab.',
					'acrossai-mcp-manager'
				) }
			</p>
		</div>
	);
};

export default Step3_AccessControl;
