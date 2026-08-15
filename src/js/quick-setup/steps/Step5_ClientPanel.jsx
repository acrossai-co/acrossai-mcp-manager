/**
 * F069 T037 — Step 5 MCP Client panel (pill row + JSON config).
 *
 * Reads state.methods.clients (populated by T020 from
 * ConnectionMethodRegistry::get_all()). Client count derived from DTO
 * array — NOT hardcoded — so companion plugins that register additional
 * AbstractMCPClient subclasses via the acrossai_mcp_client_classes filter
 * (per DEC-F034) auto-appear as extra pills.
 *
 * Selecting a pill reveals a <CodeBlock variant="pane"> with the JSON
 * config for that client. Config template comes from the DTO's `config`
 * field (WordPress `home_url()` + server slug substituted by
 * ConnectionMethodRegistry at read time).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const Step5_ClientPanel = () => {
	const { state } = useWizardState();
	const clients = state.methods.clients || [];
	const [ activeSlug, setActiveSlug ] = useState(
		clients[ 0 ] ? clients[ 0 ].slug : ''
	);

	const server = useMemo(
		() =>
			state.servers.find( ( s ) => s.id === state.wizardState.server_id ),
		[ state.servers, state.wizardState.server_id ]
	);

	if ( ! server ) {
		return (
			<Notice status="warning">
				{ __( 'No server selected.', 'acrossai-mcp-manager' ) }
			</Notice>
		);
	}

	if ( clients.length === 0 ) {
		return (
			<Notice status="warning">
				{ __(
					'No MCP clients registered on this site.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		);
	}

	const activeClient = clients.find( ( c ) => c.slug === activeSlug );

	// The DTO's config field carries the JSON template. If missing (older
	// F035 shape), fall back to a raw dump.
	const configText =
		activeClient && activeClient.config
			? typeof activeClient.config === 'string'
				? activeClient.config
				: JSON.stringify( activeClient.config, null, 2 )
			: JSON.stringify( activeClient || {}, null, 2 );

	const configTitle = activeClient
		? `json · ${ activeClient.name || activeClient.slug }`
		: 'json';

	return (
		<div>
			<div style={ { marginBottom: 12 } }>
				<span
					style={ {
						fontSize: 11,
						fontWeight: 600,
						letterSpacing: '0.06em',
						textTransform: 'uppercase',
						color: '#757575',
					} }
				>
					{ __( 'Choose your client', 'acrossai-mcp-manager' ) }
				</span>
			</div>

			<div
				role="tablist"
				aria-label={ __( 'MCP clients', 'acrossai-mcp-manager' ) }
				style={ { display: 'flex', gap: 7, flexWrap: 'wrap', marginBottom: 16 } }
			>
				{ clients.map( ( c ) => {
					const isActive = c.slug === activeSlug;
					return (
						<button
							key={ c.slug }
							type="button"
							role="tab"
							aria-selected={ isActive }
							onClick={ () => setActiveSlug( c.slug ) }
							className={ isActive ? 'qs-btn' : 'qs-btn qs-btn--secondary' }
						>
							{ c.icon && (
								<span style={ { marginRight: 6 } }>{ c.icon }</span>
							) }
							{ c.name || c.slug }
						</button>
					);
				} ) }
			</div>

			<Notice status="info">
				{ __(
					'You need a WordPress Application Password. Generate one under Users → Profile → Application Passwords and paste it where the config says (paste app password here).',
					'acrossai-mcp-manager'
				) }
			</Notice>

			<CodeBlock variant="pane" title={ configTitle }>
				{ configText }
			</CodeBlock>
		</div>
	);
};

export default Step5_ClientPanel;
