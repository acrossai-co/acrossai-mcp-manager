/**
 * F069 T035 — Step 5: connection method 2×2 grid.
 *
 * Renders four method cards (Connectors, MCP Client, npm, WP-CLI).
 * Connectors card is tri-state per FR-020 based on state.plugins.acrossaiPro:
 *   - 'missing'  → "Get AcrossAI Pro →" pricing link + trial trust line
 *   - 'inactive' → yellow notice + "Activate AcrossAI Pro" button
 *   - 'active'   → radio-selectable; picking it expands to <Step5_ConnectorsPanel>
 *
 * Other 3 cards are always radio-selectable; on pick, expand inline.
 *
 * Advance guard: canAdvance = wizardState.method !== null.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Notice from '../components/Notice.jsx';
import RadioCard from '../components/RadioCard.jsx';
import { LinkIcon, PuzzleIcon, TerminalIcon } from '../components/icons.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';
import Step5_ConnectorsPanel from './Step5_ConnectorsPanel.jsx';
import Step5_ClientPanel from './Step5_ClientPanel.jsx';
import Step5_NpmPanel from './Step5_NpmPanel.jsx';
import Step5_WpCliPanel from './Step5_WpCliPanel.jsx';

const METHODS = [
	{
		key: 'connectors',
		title: __( 'One-click OAuth (Connectors)', 'acrossai-mcp-manager' ),
		description: __(
			'Paste one URL into Claude, ChatGPT, Grok, or Gemini and approve. No config files.',
			'acrossai-mcp-manager'
		),
		icon: LinkIcon,
		badge: 'PAID',
	},
	{
		key: 'client',
		title: __( 'MCP Client', 'acrossai-mcp-manager' ),
		description: __(
			'Paste a JSON config into Claude Desktop, VS Code, Cursor, and more.',
			'acrossai-mcp-manager'
		),
		icon: PuzzleIcon,
	},
	{
		key: 'npm',
		title: __( 'npm (one-line install)', 'acrossai-mcp-manager' ),
		description: __(
			'Run a single npx command — no config files, no JSON.',
			'acrossai-mcp-manager'
		),
		icon: TerminalIcon,
	},
	{
		key: 'wpcli',
		title: __( 'WP-CLI (local subprocess)', 'acrossai-mcp-manager' ),
		description: __(
			'Best for CI or local dev. No network credentials transmitted.',
			'acrossai-mcp-manager'
		),
		icon: TerminalIcon,
	},
];

const Step5_MethodGrid = () => {
	const { state, saveStep } = useWizardState();
	const proState = state.plugins.acrossaiPro; // 'missing' | 'inactive' | 'active'
	const chosenMethod = state.wizardState.method;

	useAdvanceGuard( chosenMethod !== null );

	// If a method is already chosen, render the expanded panel.
	if ( chosenMethod ) {
		return (
			<div>
				<h2 className="qs__step-title">
					{ __( 'How do you want to connect?', 'acrossai-mcp-manager' ) }
				</h2>
				<button
					type="button"
					className="qs-btn qs-btn--link"
					onClick={ () => saveStep( 5, { method: '' } ) }
					style={ { marginBottom: 16 } }
				>
					{ __( '← Pick a different method', 'acrossai-mcp-manager' ) }
				</button>
				{ chosenMethod === 'connectors' && <Step5_ConnectorsPanel /> }
				{ chosenMethod === 'client' && <Step5_ClientPanel /> }
				{ chosenMethod === 'npm' && <Step5_NpmPanel /> }
				{ chosenMethod === 'wpcli' && <Step5_WpCliPanel /> }
			</div>
		);
	}

	const handlePick = async ( methodKey ) => {
		// Connectors card is only selectable when Pro is active.
		if ( methodKey === 'connectors' && proState !== 'active' ) {
			return;
		}
		await saveStep( 5, { method: methodKey } );
	};

	const renderConnectorsCardExtras = () => {
		if ( proState === 'inactive' ) {
			return (
				<Notice status="warning">
					<div>
						<strong>
							{ __(
								'AcrossAI Pro is installed but not active.',
								'acrossai-mcp-manager'
							) }
						</strong>{ ' ' }
						{ __(
							'Activate it to enable one-click connectors.',
							'acrossai-mcp-manager'
						) }
					</div>
				</Notice>
			);
		}
		if ( proState === 'missing' ) {
			return (
				<a
					className="qs-btn qs-btn--link"
					href="https://acrossai.co/pricing/#pricing"
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Get AcrossAI Pro →', 'acrossai-mcp-manager' ) }
				</a>
			);
		}
		return null;
	};

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'How do you want to connect?', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Pick a connection method. You can add more later from the server\'s edit page.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div
				style={ {
					display: 'grid',
					gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
					gap: 12,
				} }
				role="radiogroup"
				aria-label={ __( 'Connection methods', 'acrossai-mcp-manager' ) }
			>
				{ METHODS.map( ( m ) => {
					const Icon = m.icon;
					const isConnectors = m.key === 'connectors';
					const isSelectable = ! isConnectors || proState === 'active';

					return (
						<RadioCard
							key={ m.key }
							name="qs-method"
							value={ m.key }
							selected={ false }
							onSelect={
								isSelectable ? () => handlePick( m.key ) : undefined
							}
							title={
								<>
									<Icon size={ 18 } />{ ' ' }
									{ m.title }
									{ m.badge && (
										<span className="qs-card__badge">
											{ m.badge }
										</span>
									) }
								</>
							}
							subtitle={ m.description }
						>
							{ isConnectors && renderConnectorsCardExtras() }
						</RadioCard>
					);
				} ) }
			</div>

			{ proState === 'missing' && (
				<div style={ { marginTop: 12 } }>
					<Notice status="info">
						<span style={ { color: '#312e81' } }>
							{ __(
								'Start on Personal with a 30-day free trial on 1 site. No card charged today, cancel any time before it ends. Try it risk-free for 14 days.',
								'acrossai-mcp-manager'
							) }{ ' ' }
							<a
								href="https://acrossai.co/pricing/"
								target="_blank"
								rel="noopener noreferrer"
								style={ { color: '#4f46e5', fontWeight: 600 } }
							>
								{ __( 'See pricing ↗', 'acrossai-mcp-manager' ) }
							</a>
						</span>
					</Notice>
				</div>
			) }
		</div>
	);
};

export default Step5_MethodGrid;
