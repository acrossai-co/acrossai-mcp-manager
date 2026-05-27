/**
 * "Who can access" dropdown.
 *
 * Renders the two static options (no-access + everyone) followed by any
 * registered providers returned by the REST API.
 */

const STATIC_OPTIONS = [
	{ value: '', label: 'No user access added by admin' },
	{ value: 'everyone', label: 'Everyone (no restriction)' },
];

/**
 * @param {Object}   props
 * @param {Array}    props.providers  Provider list from GET /providers.
 * @param {string}   props.value      Current selected value.
 * @param {Function} props.onChange   Called with the new value string.
 */
export default function ProviderDropdown( { providers, value, onChange } ) {
	const availableProviders = providers.filter( ( p ) => p.available !== false );

	return (
		<select
			className="wpb-ac__select"
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
		>
			{ STATIC_OPTIONS.map( ( opt ) => (
				<option key={ opt.value } value={ opt.value }>
					{ opt.label }
				</option>
			) ) }
			{ availableProviders.map( ( provider ) => (
				<option key={ provider.id } value={ provider.id }>
					{ provider.label }
				</option>
			) ) }
		</select>
	);
}
