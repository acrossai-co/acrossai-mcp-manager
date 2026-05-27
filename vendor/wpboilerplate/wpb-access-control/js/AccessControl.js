/**
 * AccessControl — main component.
 *
 * Loads providers and the current rule on mount, renders the full UI, and
 * saves changes back to the wpb-ac/v1 REST API.
 *
 * Required props
 * --------------
 * @param {string}   namespace    Access-control namespace, e.g. "mcp".
 * @param {string}   resourceKey  Resource key, e.g. "server".
 * @param {string}   restApiRoot  WP REST API root URL, e.g. "https://site.com/wp-json".
 * @param {string}   nonce        wp_create_nonce('wp_rest') value.
 *
 * Optional props
 * --------------
 * @param {string}   title        Card heading. Default: "Access Control".
 * @param {string}   description  Subtitle text.
 * @param {string}   saveLabel    Save button label. Default: "Save Access Control".
 * @param {Function} onSave       Called with (acKey, acOptions) after a successful save.
 *
 * Consuming-plugin setup
 * ----------------------
 * The nonce middleware must be registered before any apiFetch call. If you use
 * the auto-render path in index.js it is set up automatically. When importing
 * the component directly, call:
 *   apiFetch.use(apiFetch.createNonceMiddleware(nonce));
 * once — before rendering — in your own plugin code.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import ProviderDropdown from './components/ProviderDropdown';
import RoleOptionsPanel from './components/RoleOptionsPanel';
import UserSearchPanel from './components/UserSearchPanel';
import './AccessControl.scss';

const NO_ACCESS = '';

export default function AccessControl( {
	namespace,
	resourceKey,
	restApiRoot,
	nonce,
	title = 'Access Control',
	description = 'Control which users are allowed to connect to this MCP server. Administrators always have access regardless of this setting.',
	saveLabel = 'Save Access Control',
	onSave,
} ) {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveNotice, setSaveNotice ] = useState( null );

	const [ providers, setProviders ] = useState( [] );
	const [ selectedKey, setSelectedKey ] = useState( NO_ACCESS );
	const [ selectedOptions, setSelectedOptions ] = useState( [] );

	// Full user objects for the wp_user panel tags [{id, login, display_name}].
	const [ selectedUsers, setSelectedUsers ] = useState( [] );

	// Encode slashes in namespace as %2F for the URL segment.
	const encodedNs = namespace
		.split( '/' )
		.map( encodeURIComponent )
		.join( '%2F' );

	useEffect( () => {
		Promise.all( [
			apiFetch( { url: `${ restApiRoot }/wpb-ac/v1/providers` } ),
			apiFetch( {
				url: `${ restApiRoot }/wpb-ac/v1/rules/${ encodedNs }/${ resourceKey }`,
			} ),
		] )
			.then( ( [ provs, rule ] ) => {
				setProviders( provs );

				const acKey = rule.key || '';
				const acOptions = rule.value || [];
				setSelectedKey( acKey );
				setSelectedOptions( acOptions );

				if ( acKey === 'wp_user' && acOptions.length > 0 ) {
					hydrateUserIds( acOptions );
				}
			} )
			.catch( () => {} )
			.finally( () => setIsLoading( false ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	/** Resolve stored user IDs to display-name objects via the WP core users API. */
	const hydrateUserIds = useCallback(
		( ids ) => {
			const qs = ids.map( ( id ) => `include[]=${ id }` ).join( '&' );
			apiFetch( {
				url: `${ restApiRoot }/wp/v2/users?${ qs }&per_page=100`,
			} )
				.then( ( users ) => {
					setSelectedUsers(
						users.map( ( u ) => ( {
							id: String( u.id ),
							login: u.slug,
							display_name: u.name,
						} ) )
					);
				} )
				.catch( () => {
					// Fall back to raw IDs when the WP users endpoint is unavailable.
					setSelectedUsers( ids.map( ( id ) => ( { id, login: id, display_name: id } ) ) );
				} );
		},
		[ restApiRoot ]
	);

	const handleProviderChange = useCallback( ( newKey ) => {
		setSelectedKey( newKey );
		setSelectedOptions( [] );
		setSelectedUsers( [] );
		setSaveNotice( null );
	}, [] );

	const handleOptionToggle = useCallback( ( optId ) => {
		setSelectedOptions( ( prev ) =>
			prev.includes( optId )
				? prev.filter( ( o ) => o !== optId )
				: [ ...prev, optId ]
		);
	}, [] );

	const handleAddUser = useCallback( ( user ) => {
		setSelectedUsers( ( prev ) =>
			prev.find( ( u ) => u.id === user.id ) ? prev : [ ...prev, user ]
		);
		setSelectedOptions( ( prev ) =>
			prev.includes( user.id ) ? prev : [ ...prev, user.id ]
		);
	}, [] );

	const handleRemoveUser = useCallback( ( userId ) => {
		setSelectedUsers( ( prev ) => prev.filter( ( u ) => u.id !== userId ) );
		setSelectedOptions( ( prev ) => prev.filter( ( id ) => id !== userId ) );
	}, [] );

	const handleSave = useCallback( async () => {
		setIsSaving( true );
		setSaveNotice( null );

		try {
			if ( selectedKey === NO_ACCESS ) {
				await apiFetch( {
					url: `${ restApiRoot }/wpb-ac/v1/rules/${ encodedNs }/${ resourceKey }`,
					method: 'DELETE',
				} );
			} else {
				await apiFetch( {
					url: `${ restApiRoot }/wpb-ac/v1/rules/${ encodedNs }/${ resourceKey }`,
					method: 'PUT',
					data: { ac_key: selectedKey, ac_options: selectedOptions },
				} );
			}

			setSaveNotice( { type: 'success', message: 'Access control saved.' } );
			onSave?.( selectedKey, selectedOptions );
		} catch ( err ) {
			setSaveNotice( {
				type: 'error',
				message: err?.message || 'Failed to save.',
			} );
		} finally {
			setIsSaving( false );
		}
	}, [ selectedKey, selectedOptions, encodedNs, resourceKey, restApiRoot, onSave ] );

	const activeProvider = providers.find( ( p ) => p.id === selectedKey ) || null;

	// Show checkbox panel for providers with static options (e.g. wp_role).
	const showRolePanel =
		activeProvider &&
		activeProvider.id !== 'wp_user' &&
		activeProvider.options?.length > 0;

	const showUserPanel = selectedKey === 'wp_user';

	if ( isLoading ) {
		return <div className="wpb-ac wpb-ac--loading">Loading…</div>;
	}

	return (
		<div className="wpb-ac">
			<h2 className="wpb-ac__title">{ title }</h2>
			<p className="wpb-ac__description">{ description }</p>

			<div className="wpb-ac__row">
				<div className="wpb-ac__label">Who can access</div>
				<div className="wpb-ac__control">
					<ProviderDropdown
						providers={ providers }
						value={ selectedKey }
						onChange={ handleProviderChange }
					/>
				</div>
			</div>

			{ showRolePanel && (
				<div className="wpb-ac__row">
					<div className="wpb-ac__label">{ activeProvider.label }</div>
					<div className="wpb-ac__control">
						<RoleOptionsPanel
							providerId={ selectedKey }
							options={ activeProvider.options }
							selectedOptions={ selectedOptions }
							onToggle={ handleOptionToggle }
						/>
					</div>
				</div>
			) }

			{ showUserPanel && (
				<div className="wpb-ac__row">
					<div className="wpb-ac__label">
						{ activeProvider?.label || 'Users' }
					</div>
					<div className="wpb-ac__control">
						<UserSearchPanel
							restApiRoot={ restApiRoot }
							selectedUsers={ selectedUsers }
							onAdd={ handleAddUser }
							onRemove={ handleRemoveUser }
						/>
					</div>
				</div>
			) }

			{ saveNotice && (
				<p className={ `wpb-ac__notice wpb-ac__notice--${ saveNotice.type }` }>
					{ saveNotice.message }
				</p>
			) }

			<div className="wpb-ac__footer">
				<button
					type="button"
					className="wpb-ac__save-btn"
					onClick={ handleSave }
					disabled={ isSaving }
				>
					{ isSaving ? 'Saving…' : saveLabel }
				</button>
			</div>
		</div>
	);
}
