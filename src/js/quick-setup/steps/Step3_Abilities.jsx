/**
 * F069 T033 — Step 3: Abilities dual state.
 *
 * Variant A (abilities-manager inactive/missing): giant "3" + WordPress core
 * ability list + AcrossAI Abilities Manager promo card with WordPress.org
 * install link + case studies link.
 *
 * Variant B (abilities-manager active): giant total count + "Enable all
 * abilities for this server" (primary — POST to /step step:3
 * enable_all_abilities:true) + "Configure abilities one-by-one" (opens
 * the existing Abilities tab in a NEW browser tab).
 *
 * No advance guard — enabling abilities is optional.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const CORE_ABILITIES = [
	'core/get-environment-info',
	'core/get-site-info',
	'core/get-user-info',
];

const Step3_Abilities = () => {
	const { state, saveStep, isLoading } = useWizardState();
	const [ savedFlash, setSavedFlash ] = useState( false );
	const bootstrap = window.acrossaiMcpQuickSetup || {};
	const managerActive = state.plugins.abilitiesManager === 'active';
	const serverId = state.wizardState.server_id;

	const handleEnableAll = async () => {
		const response = await saveStep( 3, { enable_all_abilities: true } );
		if ( response ) {
			setSavedFlash( true );
			setTimeout( () => setSavedFlash( false ), 3000 );
		}
	};

	const buildAbilitiesTabUrl = () => {
		if ( ! bootstrap.adminUrl || ! serverId ) return '#';
		return `${ bootstrap.adminUrl }&action=edit&server=${ serverId }&tab=abilities`;
	};

	if ( ! managerActive ) {
		return (
			<div>
				<h2 className="qs__step-title">
					{ __( 'What should AI be able to do?', 'acrossai-mcp-manager' ) }
				</h2>

				<div style={ { display: 'flex', alignItems: 'flex-end', gap: 16, justifyContent: 'center', margin: '20px 0' } }>
					<span style={ { fontSize: 60, fontWeight: 700, lineHeight: 0.9 } }>
						{ state.abilities.total }
					</span>
					<div style={ { paddingBottom: 8 } }>
						<div style={ { fontSize: 15, fontWeight: 600 } }>
							{ __( 'abilities available', 'acrossai-mcp-manager' ) }
						</div>
						<div style={ { fontSize: 13, color: '#757575' } }>
							{ __(
								'WordPress ships with only 3 by default.',
								'acrossai-mcp-manager'
							) }
						</div>
					</div>
				</div>

				<ul style={ { listStyle: 'none', padding: 0, marginBottom: 24, border: '1px solid #ddd', borderRadius: 2 } }>
					{ CORE_ABILITIES.map( ( slug ) => (
						<li
							key={ slug }
							style={ {
								padding: '11px 14px',
								borderBottom: '1px solid #f0f0f0',
								fontFamily: 'monospace',
								fontSize: 12,
							} }
						>
							• { slug }
						</li>
					) ) }
				</ul>

				<div
					style={ {
						border: '1px solid #c4b5fd',
						background: '#faf9ff',
						padding: 20,
						borderRadius: 2,
						marginBottom: 20,
					} }
				>
					<h3 style={ { margin: '0 0 12px', fontSize: 15, fontWeight: 600, color: '#312e81' } }>
						{ __(
							'Unlock 300+ abilities with AcrossAI Abilities Manager',
							'acrossai-mcp-manager'
						) }
					</h3>
					<p style={ { fontSize: 13, lineHeight: 1.65, color: '#4c1d95', margin: '0 0 16px' } }>
						{ __(
							'Create pages, update content, install plugins, update WordPress core, manage your entire site — all from your AI client.',
							'acrossai-mcp-manager'
						) }
					</p>
					<div style={ { display: 'flex', gap: 14, alignItems: 'center' } }>
						<a
							className="qs-btn"
							href="https://wordpress.org/plugins/acrossai-abilities-manager/"
							target="_blank"
							rel="noopener noreferrer"
							style={ { background: '#4f46e5', borderColor: '#4f46e5' } }
						>
							{ __( 'Install from WordPress.org', 'acrossai-mcp-manager' ) }
						</a>
						<a
							href="https://acrossai.co/use-cases/"
							target="_blank"
							rel="noopener noreferrer"
							style={ { color: '#4f46e5', textDecoration: 'none' } }
						>
							{ __( 'View case studies →', 'acrossai-mcp-manager' ) }
						</a>
					</div>
				</div>

				<Notice status="info">
					{ __(
						'By default, all abilities registered by Abilities Manager can be accessed by users with the manage_options capability (admins only). You can broaden this per-ability in the Access Control step.',
						'acrossai-mcp-manager'
					) }
				</Notice>
			</div>
		);
	}

	// Variant B — Abilities Manager active.
	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'What should AI be able to do?', 'acrossai-mcp-manager' ) }
			</h2>

			<div style={ { display: 'flex', alignItems: 'flex-end', gap: 16, justifyContent: 'center', margin: '20px 0' } }>
				<span style={ { fontSize: 60, fontWeight: 700, lineHeight: 0.9 } }>
					{ state.abilities.total }
				</span>
				<div style={ { paddingBottom: 8 } }>
					<div style={ { fontSize: 15, fontWeight: 600 } }>
						{ __( 'abilities available', 'acrossai-mcp-manager' ) }
					</div>
					<div style={ { fontSize: 13, color: '#757575' } }>
						{ __( 'From AcrossAI Abilities Manager.', 'acrossai-mcp-manager' ) }
					</div>
				</div>
			</div>

			{ savedFlash && (
				<Notice status="success">
					{ __(
						'All abilities enabled for this server.',
						'acrossai-mcp-manager'
					) }
				</Notice>
			) }

			<div style={ { display: 'flex', gap: 12, marginBottom: 20 } }>
				<button
					type="button"
					className="qs-btn"
					disabled={ isLoading || state.wizardState.abilities_saved }
					onClick={ handleEnableAll }
					style={ { flex: 1 } }
				>
					{ state.wizardState.abilities_saved
						? __( 'All abilities enabled ✓', 'acrossai-mcp-manager' )
						: isLoading
						? __( 'Enabling…', 'acrossai-mcp-manager' )
						: __( 'Enable all abilities for this server', 'acrossai-mcp-manager' ) }
				</button>
				<a
					className="qs-btn qs-btn--secondary"
					href={ buildAbilitiesTabUrl() }
					target="_blank"
					rel="noopener noreferrer"
					style={ { flex: 1 } }
				>
					{ __( 'Configure abilities one-by-one ↗', 'acrossai-mcp-manager' ) }
				</a>
			</div>

			<Notice status="info">
				{ __(
					'By default, all abilities registered by Abilities Manager can be accessed by users with the manage_options capability (admins only). You can broaden this per-ability in the Access Control step.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		</div>
	);
};

export default Step3_Abilities;
