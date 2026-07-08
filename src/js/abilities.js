/**
 * Feature 017 — Per-server Ability Selection React entry.
 *
 * Mounts a `@wordpress/dataviews`-driven table on the per-server Abilities
 * tab (`?page=acrossai_mcp_manager&action=edit&server=<id>&tab=abilities`).
 * The tab lets admins toggle exposure of each registered WordPress Ability
 * per MCP server; effective exposure falls back to `meta[mcp][public]` when
 * no per-server row exists (FR-007).
 *
 * Extensibility surface (FR-026..029):
 *   - JS filter `acrossaiMcpManager.abilities.fields` — add columns
 *   - JS filter `acrossaiMcpManager.abilities.actions` — add bulk actions
 *   - JS filter `acrossaiMcpManager.abilities.row` — decorate rows
 *
 * All three filters are wrapped in `safeApplyFilters` so a throwing
 * companion-plugin callback never white-screens the tab. Built-in
 * `fields`/`actions`/`row` keys are re-asserted after each filter fires so
 * extensions cannot remove or overwrite core columns.
 *
 * Compiled to `build/js/abilities.js` by webpack.config.js. Enqueued only
 * on the Abilities tab by `admin/Main.php::maybe_enqueue_abilities_app()`.
 *
 * @since 0.1.0
 * @package AcrossAI_MCP_Manager
 */

import {
	createRoot,
	createElement,
	useState,
	useEffect,
	useMemo,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import {
	ToggleControl,
	Notice,
	Spinner,
	SelectControl,
	SearchControl,
	CheckboxControl,
	Button,
} from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { useSelect } from '@wordpress/data';

// SCSS is bundled with this entry — @wordpress/scripts extracts it to
// `build/js/abilities.css`, which `admin/Main.php::maybe_enqueue_abilities_app()`
// enqueues alongside the JS bundle.
import '../scss/abilities.scss';

/**
 * NB: `@wordpress/abilities` (v0.16.0 as of 2026-07) is very new and
 * @wordpress/scripts may not know how to externalize it against a
 * `wp-abilities` handle. Instead of importing `store as abilitiesStore`
 * at build time, we look up the store at runtime via `wp.data.select()`
 * with the well-known WordPress convention key `core/abilities`. When
 * the store is unavailable (Abilities API JS package not loaded), we
 * fall back to a REST call that returns the full ability list from
 * PHP `wp_get_abilities()`.
 */
const ABILITIES_STORE_KEY = 'core/abilities';

/**
 * Slugs excluded from the operator-facing table. These are the MCP
 * adapter's own protocol-plumbing tools — every MCP server has to expose
 * them to be spec-compliant, so making them operator-selectable would
 * either produce a no-op toggle (the adapter re-exposes them anyway) or
 * silently break the protocol for connected clients.
 */
const EXCLUDED_SLUGS = new Set( [
	'mcp-adapter/discover-abilities',
	'mcp-adapter/get-ability-info',
	'mcp-adapter/execute-ability',
] );

( function () {
	const mount = document.getElementById( 'acrossai-mcp-abilities-root' );
	if ( ! mount ) {
		return;
	}

	const config = window.acrossaiMcpAbilities || {};
	if ( ! config.serverId || ! config.namespace ) {
		mount.textContent = __(
			'Abilities app cannot boot — missing serverId or namespace.',
			'acrossai-mcp-manager'
		);
		return;
	}

	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	/**
	 * Defensive `applyFilters` boundary (FR-029) — never lets a broken
	 * third-party filter callback white-screen the tab.
	 *
	 * @since 0.1.0 @experimental May change without notice before 1.0.0
	 * @param {string} name  Filter name.
	 * @param {*}      value Input value (fields array / actions array / row object).
	 * @param {Object} ctx   Filter context ({ serverId, serverSlug }).
	 * @return {*} Filter output on success; input on failure or invalid return.
	 */
	function safeApplyFilters( name, value, ctx ) {
		try {
			const out = applyFilters( name, value, ctx );
			if ( name.endsWith( '.fields' ) || name.endsWith( '.actions' ) ) {
				return Array.isArray( out ) ? out : value;
			}
			return out && typeof out === 'object' ? out : value;
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error(
				`[acrossai-mcp-manager] filter "${ name }" threw:`,
				err
			);
			return value;
		}
	}

	/**
	 * Custom footer bar rendered below DataViews.
	 *
	 * Layout: [Select all checkbox] · [Prev / page-of-N / Next] · [N of M Items]
	 *
	 * @param {Object} props Footer props (view, setView, shownData,
	 *                       selection, setSelection, totalItems, paginationInfo).
	 * @return {Object} React element.
	 */
	function AbilitiesFooter( {
		view: fView,
		setView: setFView,
		shownData,
		selection: fSelection,
		setSelection: setFSelection,
		totalItems,
		paginationInfo: fPagination,
	} ) {
		const perPage = fView.perPage || 50;
		const page = fView.page || 1;
		const totalPages =
			( fPagination && fPagination.totalPages ) ||
			Math.max( 1, Math.ceil( totalItems / perPage ) );

		const allSlugs = shownData.map( ( it ) => it.slug );
		const allSelected =
			allSlugs.length > 0 &&
			allSlugs.every( ( s ) => fSelection.indexOf( s ) !== -1 );

		function toggleAll( checked ) {
			if ( checked ) {
				// Union with existing selection so cross-page selections stick.
				const next = Array.from(
					new Set( [ ...fSelection, ...allSlugs ] )
				);
				setFSelection( next );
			} else {
				setFSelection(
					fSelection.filter( ( s ) => allSlugs.indexOf( s ) === -1 )
				);
			}
		}

		function goPrev() {
			if ( page > 1 ) {
				setFView( ( v ) => ( { ...v, page: page - 1 } ) );
			}
		}
		function goNext() {
			if ( page < totalPages ) {
				setFView( ( v ) => ( { ...v, page: page + 1 } ) );
			}
		}

		return createElement(
			'div',
			{ className: 'acrossai-mcp-abilities-footer' },
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-footer__left' },
				createElement( CheckboxControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Select all', 'acrossai-mcp-manager' ),
					checked: allSelected,
					onChange: toggleAll,
				} )
			),
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-footer__center' },
				createElement(
					Button,
					{
						variant: 'tertiary',
						size: 'small',
						disabled: page <= 1,
						onClick: goPrev,
						'aria-label': __(
							'Previous page',
							'acrossai-mcp-manager'
						),
					},
					'‹'
				),
				createElement(
					'span',
					{ className: 'acrossai-mcp-abilities-footer__page' },
					sprintf(
						/* translators: 1: current page, 2: total pages */
						__( 'Page %1$d of %2$d', 'acrossai-mcp-manager' ),
						page,
						totalPages
					)
				),
				createElement(
					Button,
					{
						variant: 'tertiary',
						size: 'small',
						disabled: page >= totalPages,
						onClick: goNext,
						'aria-label': __(
							'Next page',
							'acrossai-mcp-manager'
						),
					},
					'›'
				)
			),
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-footer__right' },
				sprintf(
					/* translators: 1: rows on current page, 2: total items */
					__(
						'%1$d of %2$d Items',
						'acrossai-mcp-manager'
					),
					shownData.length,
					totalItems
				)
			)
		);
	}

	function App() {
		// Per-server exposure overrides (fetched from our REST endpoint).
		// Shape: { [ability_slug]: { is_exposed: bool, has_override: bool } }
		const [ overrides, setOverrides ] = useState( {} );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( null );
		// Selection state — driven by both DataViews' built-in row checkbox
		// and by our custom footer's "Select all" checkbox.
		const [ selection, setSelection ] = useState( [] );
		// Custom exposure filter — '' = show all, 'exposed' = only enabled,
		// 'hidden' = only disabled. Managed outside `view.filters` because
		// DataViews' filter system trips on boolean field values.
		const [ exposureFilter, setExposureFilter ] = useState( '' );
		const [ view, setView ] = useState( {
			type: 'table',
			search: '',
			filters: [],
			// `view.fields` lists the ids of columns visible in the table.
			// DataViews v6+ requires this — without it, only actions render.
			// Built-in ids match the useMemo `builtinFields` below.
			fields: [
				'slug',
				'label',
				'type',
				'category',
				'description',
				'is_exposed',
			],
			sort: { field: 'slug', direction: 'asc' },
			perPage: 50,
			page: 1,
			layout: {},
		} );

		const path = `/${ config.namespace }/servers/${ config.serverId }/abilities`;

		const filterCtx = useMemo(
			() => ( {
				serverId: config.serverId,
				serverSlug: config.serverSlug,
			} ),
			[]
		);

		// Ability list from the @wordpress/abilities data store — the
		// WordPress-canonical source of registered abilities on the client.
		// See https://developer.wordpress.org/block-editor/reference-guides/packages/packages-abilities/
		//
		// String-based store lookup (not import) so a build-time missing/new
		// package doesn't hard-break the bundle. `abilitiesFromStore === null`
		// signals "store not registered at runtime — fall back to REST".
		const abilitiesFromStore = useSelect(
			( select ) => {
				const store = select( ABILITIES_STORE_KEY );
				if ( ! store || typeof store.getAbilities !== 'function' ) {
					return null;
				}
				return store.getAbilities() || [];
			},
			[]
		);

		// REST fallback list — populated only when the client store is absent.
		const [ abilitiesFromRest, setAbilitiesFromRest ] = useState( null );

		// Fetch per-server override rows on mount. If the WP abilities client
		// store is unavailable, also fetch the full ability list from a
		// fallback query param on our REST endpoint (`?include_abilities=1`).
		useEffect( () => {
			setLoading( true );
			const needFallback = abilitiesFromStore === null;
			const fetchPath = needFallback
				? path + '?include_abilities=1'
				: path;

			apiFetch( { path: fetchPath } )
				.then( ( res ) => {
					const map = {};
					const rows = ( res && res.overrides ) || [];
					rows.forEach( ( r ) => {
						map[ r.slug ] = {
							is_exposed: !! r.is_exposed,
							has_override: true,
						};
					} );
					setOverrides( map );
					if ( needFallback && res && Array.isArray( res.abilities ) ) {
						setAbilitiesFromRest( res.abilities );
					}
					setError( null );
				} )
				.catch( ( e ) => setError( e && e.message ? e.message : String( e ) ) )
				.finally( () => setLoading( false ) );
		}, [ path, abilitiesFromStore ] );

		// Effective ability list — store preferred, REST fallback second.
		const abilities = abilitiesFromStore !== null
			? abilitiesFromStore
			: ( abilitiesFromRest || [] );

		// Merge the WP abilities list with our per-server override map.
		// Effective is_exposed: override wins; else meta.mcp.public fallback.
		// EXCLUDED_SLUGS drops the MCP adapter's protocol-plumbing tools.
		const items = useMemo( () => {
			return abilities
				.filter( ( a ) => ! EXCLUDED_SLUGS.has( a.name ) )
				.map( ( a ) => {
					const meta = a.meta || {};
					const mcpMeta = meta.mcp || {};
					const override = overrides[ a.name ];
					const isExposed = override
						? override.is_exposed
						: !! mcpMeta.public;
					return {
						slug: a.name,
						label: a.label || a.name,
						type: mcpMeta.type || 'tool',
						category: a.category || '',
						description: a.description || '',
						is_exposed: isExposed,
						has_override: !! override,
					};
				} );
		}, [ abilities, overrides ] );

		function saveMany( selectedItems, isExposed ) {
			const batch = selectedItems.map( ( it ) => ( {
				slug: it.slug,
				is_exposed: isExposed,
			} ) );
			// Optimistic local update — flip the override map immediately.
			setOverrides( ( current ) => {
				const next = { ...current };
				batch.forEach( ( p ) => {
					next[ p.slug ] = { is_exposed: p.is_exposed, has_override: true };
				} );
				return next;
			} );
			return apiFetch( {
				path,
				method: 'POST',
				data: { abilities: batch },
			} )
				.then( ( res ) => {
					// Rehydrate from server truth in case there was a conflict.
					const map = {};
					const rows = ( res && res.overrides ) || [];
					rows.forEach( ( r ) => {
						map[ r.slug ] = {
							is_exposed: !! r.is_exposed,
							has_override: true,
						};
					} );
					setOverrides( map );
				} )
				.catch( ( e ) => setError( e && e.message ? e.message : String( e ) ) );
		}

		function saveOne( slug, isExposed ) {
			return saveMany( [ { slug } ], isExposed );
		}

		// Row-level decoration hook — extensions may add extra keys their
		// column `render` callbacks can read.
		const decoratedItems = useMemo(
			() =>
				items.map( ( item ) =>
					safeApplyFilters(
						'acrossaiMcpManager.abilities.row',
						item,
						filterCtx
					)
				),
			[ items, filterCtx ]
		);

		const builtinFields = useMemo(
			() => [
				{
					id: 'slug',
					label: __( 'Ability Name', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
					render: ( { item } ) =>
						createElement( 'code', null, item.slug ),
				},
				{
					id: 'label',
					label: __( 'Label', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
				},
				{
					id: 'type',
					label: __( 'Type', 'acrossai-mcp-manager' ),
					elements: [
						{
							value: 'tool',
							label: __( 'Tool', 'acrossai-mcp-manager' ),
						},
						{
							value: 'prompt',
							label: __( 'Prompt', 'acrossai-mcp-manager' ),
						},
						{
							value: 'resource',
							label: __( 'Resource', 'acrossai-mcp-manager' ),
						},
					],
					// `isPrimary: true` surfaces the filter dropdown inline
					// next to the search input instead of hiding it behind
					// the filter icon menu.
					filterBy: { operators: [ 'is' ] },
					render: ( { item } ) => {
						const value = ( item.type || 'tool' ).toLowerCase();
						const label =
							value.charAt( 0 ).toUpperCase() + value.slice( 1 );
						// Palette per ability kind — inline so DataViews'
						// cell-level styles can't fight us on specificity.
						// Matches the 1A mockup palette.
						const palette = {
							tool: { bg: '#dbeafe', fg: '#1e40af' },
							prompt: { bg: '#f3e8ff', fg: '#6b21a8' },
							resource: { bg: '#dcfce7', fg: '#166534' },
						};
						const c = palette[ value ] || palette.tool;
						return createElement(
							'span',
							{
								style: {
									display: 'inline-block',
									padding: '3px 10px',
									borderRadius: '4px',
									background: c.bg,
									color: c.fg,
									fontSize: '12px',
									fontWeight: 600,
									lineHeight: 1.4,
									letterSpacing: '0.01em',
								},
							},
							label
						);
					},
				},
				{
					id: 'category',
					label: __( 'Category', 'acrossai-mcp-manager' ),
					getValue: ( { item } ) => item.category,
					filterBy: { operators: [ 'is' ] },
					render: ( { item } ) =>
						item.category
							? createElement(
									'span',
									{
										style: {
											display: 'inline-block',
											padding: '2px 8px',
											border: '1px solid #dcdcde',
											borderRadius: '3px',
											background: '#f0f0f1',
											color: '#50575e',
											fontFamily:
												'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
											fontSize: '12px',
											lineHeight: 1.4,
											maxWidth: '100%',
											overflow: 'hidden',
											textOverflow: 'ellipsis',
											verticalAlign: 'middle',
										},
									},
									item.category
							  )
							: null,
				},
				{
					id: 'description',
					label: __( 'Description', 'acrossai-mcp-manager' ),
					enableGlobalSearch: true,
					// Constrain the description column so long copy doesn't
					// push the sortable columns off-screen. CSS below also
					// wraps and truncates gracefully.
					width: '20%',
					maxWidth: '20%',
				},
				{
					id: 'is_exposed',
					label: __( 'Exposed', 'acrossai-mcp-manager' ),
					enableSorting: false,
					enableHiding: false,
					render: ( { item } ) =>
						createElement( ToggleControl, {
							__nextHasNoMarginBottom: true,
							checked: !! item.is_exposed,
							onChange: ( next ) => saveOne( item.slug, next ),
							'aria-label': sprintf(
								/* translators: %s: ability slug */
								__(
									'Toggle exposure for %s',
									'acrossai-mcp-manager'
								),
								item.slug
							),
						} ),
				},
			],
			// eslint-disable-next-line react-hooks/exhaustive-deps
			[]
		);

		// Built-in bulk actions are registered so DataViews renders the row
		// selection checkbox column (DataViews couples per-row checkboxes
		// to `supportsBulk: true` action availability — removing all actions
		// also removes the checkboxes). The per-row Actions column is then
		// hidden via CSS so only the checkboxes remain visible. Our custom
		// bulk-actions bar above the table drives the Expose/Hide flow via
		// its own inline handlers.
		const builtinActions = useMemo(
			() => [
				{
					id: 'expose',
					label: __( 'Expose selected', 'acrossai-mcp-manager' ),
					supportsBulk: true,
					callback: ( items ) => saveMany( items, true ),
				},
				{
					id: 'hide',
					label: __( 'Hide selected', 'acrossai-mcp-manager' ),
					supportsBulk: true,
					callback: ( items ) => saveMany( items, false ),
				},
			],
			// eslint-disable-next-line react-hooks/exhaustive-deps
			[]
		);

		// Additive-only merge invariant — extensions may append, never
		// remove or overwrite, built-in field/action ids (FR-026 / FR-029).
		const finalFields = useMemo( () => {
			const extra = safeApplyFilters(
				'acrossaiMcpManager.abilities.fields',
				builtinFields,
				filterCtx
			);
			const builtinIds = new Set( builtinFields.map( ( f ) => f.id ) );
			const additions = ( Array.isArray( extra ) ? extra : [] ).filter(
				( f ) => f && ! builtinIds.has( f.id )
			);
			return [ ...builtinFields, ...additions ];
		}, [ builtinFields, filterCtx ] );

		// Keep `view.fields` in sync with `finalFields` so extension-added
		// columns are visible by default. Without this, a companion plugin's
		// filter would produce a field the user has to explicitly enable via
		// the column-visibility toggle.
		useEffect( () => {
			setView( ( current ) => {
				const currentSet = new Set( current.fields || [] );
				const builtinIds = new Set( builtinFields.map( ( f ) => f.id ) );
				const additions = finalFields
					.map( ( f ) => f.id )
					.filter( ( id ) => ! builtinIds.has( id ) && ! currentSet.has( id ) );
				if ( additions.length === 0 ) {
					return current;
				}
				return { ...current, fields: [ ...( current.fields || [] ), ...additions ] };
			} );
		}, [ finalFields, builtinFields ] );

		const finalActions = useMemo( () => {
			const extra = safeApplyFilters(
				'acrossaiMcpManager.abilities.actions',
				builtinActions,
				filterCtx
			);
			const builtinIds = new Set( builtinActions.map( ( a ) => a.id ) );
			const additions = ( Array.isArray( extra ) ? extra : [] ).filter(
				( a ) => a && ! builtinIds.has( a.id )
			);
			return [ ...builtinActions, ...additions ];
		}, [ builtinActions, filterCtx ] );

		const exposedCount = decoratedItems.filter( ( i ) => i.is_exposed )
			.length;

		// Apply the custom exposure filter BEFORE DataViews sees the data.
		// `decoratedItems` stays untouched so the header counter still shows
		// "N of M exposed" against the full ability list.
		const dataForView = useMemo( () => {
			if ( exposureFilter === 'exposed' ) {
				return decoratedItems.filter( ( i ) => !! i.is_exposed );
			}
			if ( exposureFilter === 'hidden' ) {
				return decoratedItems.filter( ( i ) => ! i.is_exposed );
			}
			return decoratedItems;
		}, [ decoratedItems, exposureFilter ] );

		// DataViews does NOT filter/sort/paginate on its own — the consumer
		// must apply the view state to the data before passing it in. The
		// `filterSortAndPaginate` helper shipped alongside DataViews does all
		// three in one call and returns `{ data, paginationInfo }`.
		const { data: shownData, paginationInfo: shownPagination } = useMemo(
			() => filterSortAndPaginate( dataForView, view, finalFields ),
			[ dataForView, view, finalFields ]
		);

		// Unique category list — drives the "All categories" dropdown.
		// MUST live above the early returns so hook order stays stable
		// across render passes (React rules of hooks).
		const categoryOptions = useMemo( () => {
			const seen = new Set();
			decoratedItems.forEach( ( it ) => {
				if ( it.category ) {
					seen.add( it.category );
				}
			} );
			const sorted = Array.from( seen ).sort();
			return [
				{ value: '', label: __( 'All categories', 'acrossai-mcp-manager' ) },
				...sorted.map( ( c ) => ( { value: c, label: c } ) ),
			];
		}, [ decoratedItems ] );

		const typeOptions = useMemo(
			() => [
				{ value: '', label: __( 'All types', 'acrossai-mcp-manager' ) },
				{ value: 'tool', label: __( 'Tool', 'acrossai-mcp-manager' ) },
				{ value: 'prompt', label: __( 'Prompt', 'acrossai-mcp-manager' ) },
				{ value: 'resource', label: __( 'Resource', 'acrossai-mcp-manager' ) },
			],
			[]
		);

		if ( loading ) {
			return createElement( Spinner );
		}
		if ( error ) {
			return createElement(
				Notice,
				{ status: 'error', isDismissible: false },
				error
			);
		}

		// Read/write filter values via `view.filters`. DataViews reads this
		// array and applies the filters to its row set; we drive it from the
		// custom SelectControls below. These are plain helpers, not hooks,
		// so they can safely live after the early returns.
		const readFilter = ( field ) => {
			const entry = ( view.filters || [] ).find(
				( f ) => f.field === field
			);
			return entry ? entry.value : '';
		};

		function writeFilter( field, value ) {
			setView( ( current ) => {
				const others = ( current.filters || [] ).filter(
					( f ) => f.field !== field
				);
				const next = value
					? [
							...others,
							{ field, operator: 'is', value },
					  ]
					: others;
				return { ...current, filters: next };
			} );
		}

		return createElement(
			'div',
			{ className: 'acrossai-mcp-abilities-root' },
			createElement(
				'p',
				{ className: 'description' },
				sprintf(
					/* translators: 1: exposed count, 2: total count */
					_n(
						'%1$d of %2$d ability exposed on this server.',
						'%1$d of %2$d abilities exposed on this server.',
						decoratedItems.length,
						'acrossai-mcp-manager'
					),
					exposedCount,
					decoratedItems.length
				)
			),
			createElement(
				'div',
				{ className: 'acrossai-mcp-abilities-toolbar' },
				createElement( SearchControl, {
					__nextHasNoMarginBottom: true,
					className: 'acrossai-mcp-abilities-toolbar__search',
					label: __(
						'Search abilities',
						'acrossai-mcp-manager'
					),
					placeholder: __(
						'Search name, label or description…',
						'acrossai-mcp-manager'
					),
					value: view.search || '',
					onChange: ( v ) =>
						setView( ( current ) => ( {
							...current,
							search: v,
						} ) ),
				} ),
				createElement( SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Category', 'acrossai-mcp-manager' ),
					hideLabelFromVision: true,
					value: readFilter( 'category' ),
					options: categoryOptions,
					onChange: ( v ) => writeFilter( 'category', v ),
				} ),
				createElement( SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Type', 'acrossai-mcp-manager' ),
					hideLabelFromVision: true,
					value: readFilter( 'type' ),
					options: typeOptions,
					onChange: ( v ) => writeFilter( 'type', v ),
				} ),
				createElement( SelectControl, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Exposure', 'acrossai-mcp-manager' ),
					hideLabelFromVision: true,
					value: exposureFilter,
					options: [
						{
							value: '',
							label: __(
								'All exposure',
								'acrossai-mcp-manager'
							),
						},
						{
							value: 'exposed',
							label: __(
								'Only exposed',
								'acrossai-mcp-manager'
							),
						},
						{
							value: 'hidden',
							label: __(
								'Only hidden',
								'acrossai-mcp-manager'
							),
						},
					],
					onChange: setExposureFilter,
				} )
			),
			// Bulk-actions bar — always visible, buttons disabled when nothing
			// is selected. Replaces DataViews' native bulk-action strip which
			// lives inside the toolbar chrome we've hidden.
			createElement(
				'div',
				{
					className: 'acrossai-mcp-abilities-bulk',
				},
				createElement(
					'span',
					{ className: 'acrossai-mcp-abilities-bulk__count' },
					sprintf(
						/* translators: %d: number of selected rows */
						_n(
							'%d selected',
							'%d selected',
							selection.length,
							'acrossai-mcp-manager'
						),
						selection.length
					)
				),
				createElement(
					Button,
					{
						variant: 'primary',
						size: 'compact',
						disabled: selection.length === 0,
						onClick: () => {
							const chosen = decoratedItems.filter( ( i ) =>
								selection.includes( i.slug )
							);
							saveMany( chosen, true ).then( () =>
								setSelection( [] )
							);
						},
					},
					__( 'Expose selected', 'acrossai-mcp-manager' )
				),
				createElement(
					Button,
					{
						variant: 'secondary',
						size: 'compact',
						disabled: selection.length === 0,
						onClick: () => {
							const chosen = decoratedItems.filter( ( i ) =>
								selection.includes( i.slug )
							);
							saveMany( chosen, false ).then( () =>
								setSelection( [] )
							);
						},
					},
					__( 'Hide selected', 'acrossai-mcp-manager' )
				),
				createElement(
					Button,
					{
						variant: 'link',
						size: 'compact',
						disabled: selection.length === 0,
						onClick: () => setSelection( [] ),
					},
					__( 'Clear', 'acrossai-mcp-manager' )
				),
				// Whole-list actions — operate on every ability regardless
				// of selection or active filters. Confirm because it's a
				// destructive/broad action.
				createElement(
					Button,
					{
						variant: 'secondary',
						size: 'compact',
						onClick: () => {
							if (
								! window.confirm(
									__(
										'Enable all abilities on this server?',
										'acrossai-mcp-manager'
									)
								)
							) {
								return;
							}
							saveMany( decoratedItems, true );
						},
					},
					__( 'Enable All', 'acrossai-mcp-manager' )
				),
				createElement(
					Button,
					{
						variant: 'secondary',
						size: 'compact',
						onClick: () => {
							if (
								! window.confirm(
									__(
										'Disable all abilities on this server?',
										'acrossai-mcp-manager'
									)
								)
							) {
								return;
							}
							saveMany( decoratedItems, false );
						},
					},
					__( 'Disable All', 'acrossai-mcp-manager' )
				)
			),
			createElement( DataViews, {
				data: shownData,
				fields: finalFields,
				view,
				onChangeView: setView,
				actions: finalActions,
				defaultLayouts: { table: {} },
				getItemId: ( item ) => item.slug,
				paginationInfo: shownPagination,
				selection,
				onChangeSelection: setSelection,
			} ),
			// Custom footer: [Select all] · [pagination] · [N of M Items]
			createElement( AbilitiesFooter, {
				view,
				setView,
				shownData,
				selection,
				setSelection,
				totalItems: decoratedItems.length,
				paginationInfo: shownPagination,
			} )
		);
	}

	// React 18+ modern rendering path — legacy `render()` triggers a
	// deprecation warning in the browser console and puts the app in
	// React-17-compat mode. `createRoot` is re-exported from
	// `@wordpress/element` for exactly this migration.
	createRoot( mount ).render( createElement( App ) );
} )();
