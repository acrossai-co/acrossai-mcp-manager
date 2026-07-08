/**
 * Feature 020 — Per-server Tool Selection React app.
 *
 * Hand-rolled two-column shuttle picker. **Optimistic-per-toggle**:
 * each Add / Remove click POSTs immediately to
 * `/acrossai-mcp-manager/v1/servers/{id}/tools` with the full new set;
 * on failure the local state rolls back and an error notice surfaces.
 * The Save changes / Cancel bar has been retired at operator request —
 * "once I add it, it's saved."
 *
 * Uses only `@wordpress/*` Tier 1 packages — no react-query, redux, mobx,
 * @tanstack, react-table, @mui, or styled-components. Enforced by grep
 * gate T053.
 *
 * @package acrossai-mcp-manager
 */

import {
	createElement,
	Fragment,
	useState,
	useEffect,
	useMemo,
} from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import { Button, SearchControl, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useSelect } from '@wordpress/data';

/**
 * Slugs never exposed in the left "All abilities" pool — mirrors PHP-side
 * ToolsController::EXCLUDED_SLUGS and ToolExposureGate::EXCLUDED_SLUGS.
 * Three-way duplication acknowledged in plan.md §Complexity Tracking per
 * F017 precedent (DRY tension with A9).
 *
 * These slugs ARE shown in the right "Added as tools" column as always-added
 * built-ins (see BUILTIN_ABILITIES below) — they're the mcp-adapter protocol
 * tools and are always callable regardless of operator curation (matches the
 * PHP-side ToolExposureGate protocol-bypass semantics at step 3).
 */
const EXCLUDED_SLUGS = new Set( [
	'mcp-adapter/discover-abilities',
	'mcp-adapter/get-ability-info',
	'mcp-adapter/execute-ability',
] );

/**
 * The three MCP-adapter protocol tools that ship built-in to every server —
 * shown in the right column as always-added, non-removable. Metadata mirrors
 * the retired `ToolsTab::get_core_tools()` PHP method so the UI still
 * surfaces the "Discover Abilities" / "Get Ability Info" / "Execute Ability"
 * primitives without operators needing to know they're protocol plumbing.
 *
 * Labels + descriptions run through i18n at render time. `type = 'Built-in'`
 * uses a distinct badge color (not Tool/Prompt/Resource) so operators can
 * visually distinguish protocol tools from curated ones.
 */
const BUILTIN_ABILITIES = [
	{
		name: 'mcp-adapter/discover-abilities',
		labelKey: 'Discover Abilities',
		descriptionKey:
			'Lists all publicly available WordPress abilities registered on this site. AI clients use this to discover what actions the server can perform.',
		type: 'Built-in',
	},
	{
		name: 'mcp-adapter/get-ability-info',
		labelKey: 'Get Ability Info',
		descriptionKey:
			'Returns detailed information about a specific ability, including its input/output schema and description. Used by AI clients before executing an ability.',
		type: 'Built-in',
	},
	{
		name: 'mcp-adapter/execute-ability',
		labelKey: 'Execute Ability',
		descriptionKey:
			'Executes a WordPress ability with the provided input parameters and returns the result. This is the primary tool used by AI clients to interact with WordPress.',
		type: 'Built-in',
	},
];

/**
 * Type badge palette — matches the mockup at tools-ui.zip → Tools Selection.dc.html:178-183.
 * `Built-in` is F020's addition for the three protocol tools shown always-added
 * in the right column — muted amber to visually distinguish from operator-curated
 * types.
 */
const TYPE_STYLE = {
	Tool: { bg: '#e5f0f8', fg: '#0a4b78' },
	Prompt: { bg: '#f3e8fd', fg: '#6b21a8' },
	Resource: { bg: '#e6f6ec', fg: '#0a6b3d' },
	'Built-in': { bg: '#fef7e0', fg: '#8a6d00' },
};

/**
 * Defensive filter boundary. Mirrors src/js/abilities.js:safeApplyFilters —
 * a broken third-party callback must NOT white-screen the mount.
 *
 * @param {string} hookName     Filter name.
 * @param {*}      defaultValue Value returned on callback throw.
 * @param {...*}   args         Extra args passed to applyFilters.
 * @return {*} Result of applyFilters, or defaultValue on throw.
 */
export function safeApplyFilters( hookName, defaultValue, ...args ) {
	try {
		return applyFilters( hookName, defaultValue, ...args );
	} catch ( e ) {
		// eslint-disable-next-line no-console
		console.error(
			`[acrossaiMcpTools] Filter "${ hookName }" callback threw:`,
			e
		);
		return defaultValue;
	}
}


/**
 * Render a single ability row.
 *
 * @param {object}      props
 * @param {object}      props.ability     Ability metadata.
 * @param {string}      props.side        'available' | 'added' | 'builtin' — controls
 *                                        badge, background, and action-button behavior.
 * @param {?function}   props.onAction    Add/Remove handler (omit for `builtin`).
 * @param {?string}     props.actionLabel Button label (omit for `builtin`).
 * @param {?boolean}    props.busy        When true, the action button is disabled
 *                                        to prevent double-clicks during a POST.
 */
function AbilityRow( { ability, side, onAction, actionLabel, busy } ) {
	const decoration = safeApplyFilters(
		'acrossaiMcpManager.tools.row',
		{},
		ability,
		{ side }
	);
	const typeStyle =
		TYPE_STYLE[ ability.type ] || { bg: '#f0f0f1', fg: '#50575e' };
	const rowClass = [ 'acrossai-mcp-tools-row', decoration.className ]
		.filter( Boolean )
		.join( ' ' );
	const isBuiltin = side === 'builtin';
	const showCheckmark = side === 'added' || isBuiltin;
	// Muted background for built-ins so they read as "always-on infrastructure"
	// rather than "operator-curated". Same right-column background family.
	const rowBg = isBuiltin ? '#fbfaf3' : side === 'added' ? '#f9fcff' : '';

	return createElement(
		'div',
		{
			className: rowClass,
			style: {
				display: 'flex',
				alignItems: 'flex-start',
				gap: '12px',
				padding: '13px 16px',
				borderBottom: '1px solid #f0f0f1',
				background: rowBg,
			},
		},
		showCheckmark
			? createElement(
					'span',
					{
						style: {
							flex: 'none',
							width: '22px',
							height: '22px',
							borderRadius: '50%',
							background: isBuiltin ? '#fef7e0' : '#e6f6ec',
							color: isBuiltin ? '#8a6d00' : '#0a6b3d',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							fontSize: '13px',
							fontWeight: 700,
							marginTop: '1px',
						},
						title: isBuiltin
							? __( 'Built-in — always available on every server.', 'acrossai-mcp-manager' )
							: '',
					},
					isBuiltin ? '🔒' : '✓'
			  )
			: null,
		decoration.prepend || null,
		createElement(
			'div',
			{ style: { flex: 1, minWidth: 0 } },
			createElement(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'center',
						gap: '9px',
						flexWrap: 'wrap',
					},
				},
				createElement(
					'span',
					{
						style: {
							fontSize: '14px',
							fontWeight: 700,
							color: '#1d2327',
						},
					},
					ability.label || ability.name
				),
				ability.type
					? createElement(
							'span',
							{
								style: {
									display: 'inline-block',
									fontSize: '11.5px',
									fontWeight: 600,
									borderRadius: '3px',
									padding: '2px 8px',
									background: typeStyle.bg,
									color: typeStyle.fg,
								},
							},
							ability.type
					  )
					: null
			),
			createElement(
				'div',
				{ style: { marginTop: '5px' } },
				createElement(
					'code',
					{
						style: {
							fontSize: '12px',
							background: '#f0f0f1',
							color: '#2c3338',
							padding: '2px 7px',
							borderRadius: '3px',
							wordBreak: 'break-all',
						},
					},
					ability.name
				)
			),
			ability.description
				? createElement(
						'div',
						{
							style: {
								fontSize: '12.5px',
								color: '#646970',
								lineHeight: 1.55,
								marginTop: '7px',
							},
						},
						ability.description
				  )
				: null,
			decoration.append || null
		),
		isBuiltin
			? createElement(
					'span',
					{
						style: {
							flex: 'none',
							fontSize: '11.5px',
							fontWeight: 600,
							color: '#8a6d00',
							padding: '5px 11px',
							background: '#fef7e0',
							border: '1px solid #f0d879',
							borderRadius: '3px',
							whiteSpace: 'nowrap',
						},
					},
					__( 'Built-in', 'acrossai-mcp-manager' )
			  )
			: createElement( Button, {
					variant: side === 'added' ? 'secondary' : 'primary',
					isSmall: true,
					disabled: !! busy,
					onClick: () => onAction( ability.name ),
					children: actionLabel,
			  } )
	);
}

/**
 * Main React component — the shuttle picker.
 */
function ToolsApp( { serverId, serverSlug } ) {
	const config = window.acrossaiMcpTools || {};
	// Single source of truth — the operator-curated tool set as the server
	// has it. Optimistic-per-toggle: each Add/Remove POSTs immediately.
	const [ added, setAdded ] = useState( new Set() );
	const [ search, setSearch ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ abilitiesFromRest, setAbilitiesFromRest ] = useState( [] );

	// B22 — Prefer the @wordpress/abilities data store when it exists (v0.x
	// packages aren't in @wordpress/scripts externals map, so we look up by
	// string key at runtime, not by build-time import).
	const abilitiesFromStore = useSelect( ( select ) => {
		const store = select( 'core/abilities' );
		if ( store && typeof store.getAbilities === 'function' ) {
			return store.getAbilities();
		}
		return null;
	}, [] );

	const abilities = useMemo( () => {
		const source =
			Array.isArray( abilitiesFromStore ) && abilitiesFromStore.length > 0
				? abilitiesFromStore
				: abilitiesFromRest;
		return source.filter( ( a ) => ! EXCLUDED_SLUGS.has( a.name ) );
	}, [ abilitiesFromStore, abilitiesFromRest ] );

	// Initial mount: GET /tools?include_abilities=1
	useEffect( () => {
		const path = `/${ config.namespace }/servers/${ serverId }/tools?include_abilities=1`;
		apiFetch( { path } )
			.then( ( response ) => {
				setAdded( new Set( response.tools || [] ) );
				if ( Array.isArray( response.abilities ) ) {
					setAbilitiesFromRest( response.abilities );
				}
			} )
			.catch( ( err ) => {
				setError( err.message || __( 'Failed to load tools.', 'acrossai-mcp-manager' ) );
			} )
			.finally( () => {
				setLoading( false );
			} );
	}, [ config.namespace, serverId ] );

	const visibleAvailable = useMemo( () => {
		const q = search.trim().toLowerCase();
		return abilities.filter( ( a ) => {
			if ( added.has( a.name ) ) return false;
			if ( ! q ) return true;
			return (
				a.name.toLowerCase().includes( q ) ||
				( a.label || '' ).toLowerCase().includes( q ) ||
				( a.description || '' ).toLowerCase().includes( q ) ||
				( a.category || '' ).toLowerCase().includes( q )
			);
		} );
	}, [ abilities, added, search ] );

	const addedRows = useMemo( () => {
		const byName = Object.fromEntries( abilities.map( ( a ) => [ a.name, a ] ) );
		return Array.from( added ).map( ( name ) => byName[ name ] || {
			name,
			label: name,
			description: __( '(ability no longer registered)', 'acrossai-mcp-manager' ),
			type: '',
			category: '',
		} );
	}, [ abilities, added ] );

	/**
	 * Persist the given tool set to the server. Optimistically updates local
	 * state before the POST; on error, rolls back to the previous state and
	 * surfaces the error to the operator.
	 *
	 * @param {Set<string>} nextSet   The desired full tool set after this action.
	 * @param {Set<string>} prevSet   The prior tool set — used for rollback on failure.
	 */
	const persistSet = ( nextSet, prevSet ) => {
		setAdded( nextSet ); // Optimistic — UI reflects the change immediately.
		setSaving( true );
		setError( null );
		const path = `/${ config.namespace }/servers/${ serverId }/tools`;
		apiFetch( {
			path,
			method: 'POST',
			data: { tools: Array.from( nextSet ) },
		} )
			.then( ( response ) => {
				// Server truth — reconcile against what actually persisted.
				setAdded( new Set( response.tools || [] ) );
			} )
			.catch( ( err ) => {
				// Rollback the optimistic update — the server rejected the
				// change (403 / 400 / 500) or the network failed.
				setAdded( prevSet );
				setError( err.message || __( 'Save failed.', 'acrossai-mcp-manager' ) );
			} )
			.finally( () => {
				setSaving( false );
			} );
	};

	const addAbility = ( name ) => {
		const prev = new Set( added );
		const next = new Set( added );
		next.add( name );
		persistSet( next, prev );
	};
	const removeAbility = ( name ) => {
		const prev = new Set( added );
		const next = new Set( added );
		next.delete( name );
		persistSet( next, prev );
	};
	const removeAll = () => {
		const prev = new Set( added );
		persistSet( new Set(), prev );
	};

	if ( loading ) {
		return createElement(
			'div',
			{ style: { padding: '40px', textAlign: 'center' } },
			createElement( Spinner )
		);
	}

	const totalPool = abilities.length;

	return createElement(
		Fragment,
		null,
		error
			? createElement(
					Notice,
					{ status: 'error', onRemove: () => setError( null ) },
					error
			  )
			: null,
		createElement(
			'div',
			{
				style: {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					flexWrap: 'wrap',
					gap: '10px',
					marginBottom: '6px',
				},
			},
			createElement(
				'span',
				null,
				sprintf(
					/* translators: 1: added count, 2: total available count, 3: built-in count */
					__(
						'%1$d of %2$d abilities added as tools · %3$d built-in always available',
						'acrossai-mcp-manager'
					),
					added.size,
					totalPool,
					BUILTIN_ABILITIES.length
				)
			)
		),
		createElement(
			'div',
			{
				style: {
					display: 'grid',
					gridTemplateColumns: '1fr 1fr',
					gap: '20px',
					marginTop: '22px',
					alignItems: 'start',
				},
			},
			// LEFT column: available abilities.
			createElement(
				'div',
				{
					style: {
						border: '1px solid #c3c4c7',
						borderRadius: '8px',
						overflow: 'hidden',
						display: 'flex',
						flexDirection: 'column',
					},
				},
				createElement(
					'div',
					{
						style: {
							padding: '13px 16px',
							background: '#f6f7f7',
							borderBottom: '1px solid #c3c4c7',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'space-between',
							gap: '10px',
						},
					},
					createElement(
						'div',
						null,
						createElement(
							'span',
							{ style: { fontSize: '14px', fontWeight: 700 } },
							__( 'All abilities', 'acrossai-mcp-manager' )
						),
						' ',
						createElement(
							'span',
							{ style: { fontSize: '12.5px', color: '#646970' } },
							sprintf(
								/* translators: %d: available ability count */
								__( '%d available', 'acrossai-mcp-manager' ),
								visibleAvailable.length
							)
						)
					),
				),
				createElement(
					'div',
					{ style: { padding: '12px 14px', borderBottom: '1px solid #e0e0e2' } },
					createElement( SearchControl, {
						value: search,
						onChange: setSearch,
						placeholder: __( 'Search abilities…', 'acrossai-mcp-manager' ),
					} )
				),
				createElement(
					'div',
					{ style: { maxHeight: '560px', overflow: 'auto' } },
					visibleAvailable.length === 0
						? createElement(
								'div',
								{ style: { padding: '40px 24px', textAlign: 'center', color: '#646970' } },
								search.trim()
									? __( 'No abilities match your search.', 'acrossai-mcp-manager' )
									: __( 'Every ability has been added as a tool.', 'acrossai-mcp-manager' )
						  )
						: visibleAvailable.map( ( a ) =>
								createElement( AbilityRow, {
									key: a.name,
									ability: a,
									side: 'available',
									onAction: addAbility,
									actionLabel: __( '+ Add', 'acrossai-mcp-manager' ),
									busy: saving,
								} )
						  )
				)
			),
			// RIGHT column: added tools.
			createElement(
				'div',
				{
					style: {
						border: '1px solid #c3c4c7',
						borderRadius: '8px',
						overflow: 'hidden',
						display: 'flex',
						flexDirection: 'column',
					},
				},
				createElement(
					'div',
					{
						style: {
							padding: '13px 16px',
							background: '#f0f6fc',
							borderBottom: '1px solid #c3c4c7',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'space-between',
							gap: '10px',
						},
					},
					createElement(
						'div',
						null,
						createElement(
							'span',
							{ style: { fontSize: '14px', fontWeight: 700 } },
							__( 'Added as tools', 'acrossai-mcp-manager' )
						),
						' ',
						createElement(
							'span',
							{
								style: {
									display: 'inline-flex',
									alignItems: 'center',
									justifyContent: 'center',
									minWidth: '20px',
									height: '20px',
									padding: '0 6px',
									borderRadius: '10px',
									background: '#2271b1',
									color: '#fff',
									fontSize: '12px',
									fontWeight: 700,
								},
								title: __(
									'3 built-in protocol tools + your curated selection.',
									'acrossai-mcp-manager'
								),
							},
							String( added.size + BUILTIN_ABILITIES.length )
						)
					),
					createElement( Button, {
						variant: 'secondary',
						isSmall: true,
						onClick: removeAll,
						disabled: added.size === 0 || saving,
						children: __( 'Reset', 'acrossai-mcp-manager' ),
					} )
				),
				createElement(
					'div',
					{ style: { maxHeight: '632px', overflow: 'auto', flex: 1 } },
					// Always-added built-in mcp-adapter protocol tools. Shown at
					// the TOP of the column so operators see the full picture of
					// what AI clients can call on this server.
					createElement(
						'div',
						{
							style: {
								padding: '8px 16px',
								fontSize: '11.5px',
								fontWeight: 600,
								textTransform: 'uppercase',
								letterSpacing: '0.06em',
								color: '#646970',
								background: '#fafaf5',
								borderBottom: '1px solid #f0d879',
							},
						},
						__( 'Always available (built-in)', 'acrossai-mcp-manager' )
					),
					BUILTIN_ABILITIES.map( ( b ) =>
						createElement( AbilityRow, {
							key: b.name,
							ability: {
								name: b.name,
								label: __( b.labelKey, 'acrossai-mcp-manager' ),
								description: __(
									b.descriptionKey,
									'acrossai-mcp-manager'
								),
								type: b.type,
								category: '',
							},
							side: 'builtin',
						} )
					),
					// Operator-curated section header — helps distinguish from
					// built-ins visually. Always rendered even when addedRows is
					// empty so the empty state has clear context.
					createElement(
						'div',
						{
							style: {
								padding: '8px 16px',
								fontSize: '11.5px',
								fontWeight: 600,
								textTransform: 'uppercase',
								letterSpacing: '0.06em',
								color: '#646970',
								background: '#f6f7f7',
								borderBottom: '1px solid #e0e0e2',
								borderTop: '1px solid #e0e0e2',
							},
						},
						__( 'Curated tools', 'acrossai-mcp-manager' )
					),
					addedRows.length === 0
						? createElement(
								'div',
								{ style: { padding: '32px 28px', textAlign: 'center' } },
								createElement(
									'div',
									{ style: { fontSize: '14px', fontWeight: 600, color: '#50575e' } },
									__( 'No tools added yet', 'acrossai-mcp-manager' )
								),
								createElement(
									'div',
									{
										style: {
											fontSize: '13px',
											color: '#646970',
											marginTop: '6px',
											lineHeight: 1.55,
										},
									},
									__(
										'Add abilities from the left to expose them as callable MCP tools.',
										'acrossai-mcp-manager'
									)
								)
						  )
						: addedRows.map( ( a ) =>
								createElement( AbilityRow, {
									key: a.name,
									ability: a,
									side: 'added',
									onAction: removeAbility,
									actionLabel: __( 'Remove', 'acrossai-mcp-manager' ),
									busy: saving,
								} )
						  )
				)
			)
		),
		// Zero-added warning banner (visible only when operator has curated
		// zero abilities). The three built-in protocol tools remain callable
		// regardless — the warning is about operator-curated abilities.
		added.size === 0
			? createElement(
					'div',
					{
						style: {
							display: 'flex',
							alignItems: 'flex-start',
							gap: '12px',
							background: '#fcf9f0',
							border: '1px solid #f0d879',
							borderLeft: '4px solid #dba617',
							padding: '12px 16px',
							marginTop: '20px',
						},
					},
					createElement(
						'div',
						{ style: { fontSize: '13.5px', color: '#3c434a', lineHeight: 1.55 } },
						__(
							'No abilities are added as tools yet. Connected AI clients can still discover the built-in protocol tools shown above, but they won\'t be able to execute any WordPress ability on this server until you add at least one.',
							'acrossai-mcp-manager'
						)
					)
			  )
			: null,
		// mcp-adapter info banner (always visible below the columns).
		createElement(
			'div',
			{
				style: {
					display: 'flex',
					alignItems: 'flex-start',
					gap: '12px',
					background: '#fff',
					border: '1px solid #c3c4c7',
					borderLeft: '4px solid #2271b1',
					padding: '13px 16px',
					marginTop: '20px',
				},
			},
			createElement(
				'div',
				{ style: { fontSize: '13.5px', color: '#3c434a', lineHeight: 1.55 } },
				__(
					'Every ability added here is exposed as an MCP tool through the wordpress/mcp-adapter package. AI clients call these tools to run the underlying WordPress abilities registered in the Abilities tab.',
					'acrossai-mcp-manager'
				)
			)
		),
		// Saving indicator — subtle spinner shown while a POST is in-flight,
		// replacing the retired Save changes / Cancel bar. Each Add / Remove /
		// Reset click now commits automatically.
		saving
			? createElement(
					'div',
					{
						style: {
							display: 'flex',
							alignItems: 'center',
							gap: '10px',
							marginTop: '24px',
							paddingTop: '20px',
							borderTop: '1px solid #e0e0e2',
							fontSize: '13px',
							color: '#646970',
						},
					},
					createElement( Spinner ),
					__( 'Saving…', 'acrossai-mcp-manager' )
			  )
			: null
	);
}

// Mount on DOMContentLoaded (or immediately if the script is already deferred to end of body).
function mount() {
	const root = document.getElementById( 'acrossai-mcp-tools-root' );
	if ( ! root ) {
		return; // Silent no-op — the enqueue guard failed and we're on the wrong screen.
	}
	const config = window.acrossaiMcpTools || {};

	// Wire the plugin-scoped nonce onto apiFetch. Without this, POST /tools
	// silently 403s (WordPress admin's default wpApiSettings.nonce is not
	// scoped to our route). Bug fix: user reported "add + reload = row
	// removed" because Save was returning 403 while the UI mistook the failure
	// for a successful commit. Mirrors src/js/abilities.js:95.
	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}
	// Root the API URL to the localized restApiRoot so path-only apiFetch
	// calls resolve to the correct site (relevant for multisite installs and
	// non-default REST prefixes). Value is already `untrailingslashit`-clean.
	if ( config.restApiRoot ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) );
	}

	const serverId = parseInt( root.getAttribute( 'data-server-id' ) || '0', 10 );
	const serverSlug = root.getAttribute( 'data-server-slug' ) || '';
	root.innerHTML = ''; // Clear the "Loading tools…" placeholder.
	createRoot( root ).render(
		createElement( ToolsApp, { serverId, serverSlug } )
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
