/**
 * F069 T030 — Step 1: server picker + inline create form.
 *
 * Reads state.servers (populated by T020 handle_state). Renders RadioCard
 * per server. "+ Create a new server" flips local state to reveal
 * <Step1_ServerCreate /> inline (per-design brief: not a modal, not a new
 * URL — compact slide-over inside the content pane).
 *
 * Advance guard: canAdvance = wizardState.server_id !== null.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import RadioCard from '../components/RadioCard.jsx';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';
import Step1_ServerCreate from './Step1_ServerCreate.jsx';

const Step1_ServerPick = () => {
	const { state, saveStep } = useWizardState();
	const [ showCreate, setShowCreate ] = useState( state.servers.length === 0 );
	const selectedId = state.wizardState.server_id;

	useAdvanceGuard( selectedId !== null );

	const handleSelect = async ( serverId ) => {
		await saveStep( 1, { server_id: serverId } );
	};

	const handleCreated = async ( newServerId ) => {
		setShowCreate( false );
		// server_id auto-set inside saveStep call from Step1_ServerCreate,
		// nothing else to do here.
	};

	const servers = useMemo( () => state.servers || [], [ state.servers ] );

	if ( showCreate ) {
		return (
			<Step1_ServerCreate
				onCreated={ handleCreated }
				onCancel={ () => setShowCreate( servers.length === 0 ) }
			/>
		);
	}

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Which server should AI connect to?', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Pick an existing MCP server, or create a new one for this setup.',
					'acrossai-mcp-manager'
				) }
			</p>

			{ servers.length === 0 && (
				<p>
					{ __(
						'No MCP servers found — let\'s create your first one below.',
						'acrossai-mcp-manager'
					) }
				</p>
			) }

			<div role="radiogroup" aria-label={ __( 'MCP servers', 'acrossai-mcp-manager' ) }>
				{ servers.map( ( server ) => (
					<RadioCard
						key={ server.id }
						name="qs-server-pick"
						value={ String( server.id ) }
						selected={ selectedId === server.id }
						onSelect={ () => handleSelect( server.id ) }
						title={ server.name }
						subtitle={ <code>{ server.route_full }</code> }
						badge={
							! server.enabled ? (
								<span className="qs-card__badge qs-card__badge--inactive">
									{ __( 'Inactive', 'acrossai-mcp-manager' ) }
								</span>
							) : null
						}
					/>
				) ) }
			</div>

			<button
				type="button"
				className="qs-btn qs-btn--link"
				onClick={ () => setShowCreate( true ) }
				style={ { marginTop: 12 } }
			>
				{ __( '+ Create a new server', 'acrossai-mcp-manager' ) }
			</button>
		</div>
	);
};

export default Step1_ServerPick;
