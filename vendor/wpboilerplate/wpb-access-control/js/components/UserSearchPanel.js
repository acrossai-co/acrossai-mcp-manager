/**
 * User search input with autocomplete dropdown and selected-user tags.
 *
 * Calls GET /wpb-ac/v1/users?search=… (debounced 300 ms) and renders
 * matching users in a dropdown. Selecting a user fires onAdd(); clicking ×
 * on a tag fires onRemove().
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {Object}   props
 * @param {string}   props.restApiRoot    WordPress REST API root URL.
 * @param {Array}    props.selectedUsers  Currently selected users [{id, login, display_name}].
 * @param {Function} props.onAdd         Called with a user object when selected from dropdown.
 * @param {Function} props.onRemove      Called with user ID when × is clicked.
 */
export default function UserSearchPanel( {
	restApiRoot,
	selectedUsers,
	onAdd,
	onRemove,
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ showDropdown, setShowDropdown ] = useState( false );
	const debounceRef = useRef( null );
	const containerRef = useRef( null );

	// Close dropdown on outside click.
	useEffect( () => {
		const handleClick = ( e ) => {
			if ( containerRef.current && ! containerRef.current.contains( e.target ) ) {
				setShowDropdown( false );
			}
		};
		document.addEventListener( 'mousedown', handleClick );
		return () => document.removeEventListener( 'mousedown', handleClick );
	}, [] );

	const doSearch = useCallback(
		( term ) => {
			if ( ! term.trim() ) {
				setResults( [] );
				setShowDropdown( false );
				return;
			}
			apiFetch( {
				url: `${ restApiRoot }/wpb-ac/v1/users?search=${ encodeURIComponent( term ) }`,
			} )
				.then( ( data ) => {
					setResults( data || [] );
					setShowDropdown( true );
				} )
				.catch( () => setResults( [] ) );
		},
		[ restApiRoot ]
	);

	const handleSearchChange = useCallback(
		( e ) => {
			const term = e.target.value;
			setSearch( term );
			clearTimeout( debounceRef.current );
			debounceRef.current = setTimeout( () => doSearch( term ), 300 );
		},
		[ doSearch ]
	);

	const handleSelect = useCallback(
		( user ) => {
			onAdd( { id: user.id, login: user.login, display_name: user.display_name } );
			setSearch( '' );
			setResults( [] );
			setShowDropdown( false );
		},
		[ onAdd ]
	);

	const visibleResults = results.filter(
		( r ) => ! selectedUsers.find( ( u ) => u.id === r.id )
	);

	return (
		<div className="wpb-ac__user-panel" ref={ containerRef }>
			<p className="wpb-ac__panel-description">
				Search by username or email and select one or more users.
				Administrators always have access regardless of this list.
			</p>

			<div className="wpb-ac__search-wrapper">
				<input
					type="text"
					className="wpb-ac__search-input"
					placeholder="Search by username or email..."
					value={ search }
					onChange={ handleSearchChange }
					onFocus={ () => visibleResults.length && setShowDropdown( true ) }
				/>
				{ showDropdown && visibleResults.length > 0 && (
					<ul className="wpb-ac__search-dropdown">
						{ visibleResults.map( ( user ) => (
							<li
								key={ user.id }
								className="wpb-ac__search-option"
								onMouseDown={ () => handleSelect( user ) }
							>
								{ user.display_name } ({ user.login })
							</li>
						) ) }
					</ul>
				) }
			</div>

			{ selectedUsers.length > 0 && (
				<div className="wpb-ac__user-tags">
					{ selectedUsers.map( ( user ) => (
						<span key={ user.id } className="wpb-ac__user-tag">
							{ user.display_name } ({ user.login })
							<button
								type="button"
								className="wpb-ac__tag-remove"
								onClick={ () => onRemove( user.id ) }
								aria-label={ `Remove ${ user.display_name }` }
							>
								×
							</button>
						</span>
					) ) }
				</div>
			) }
		</div>
	);
}
