/**
 * Checkbox list for providers that expose static options (e.g. wp_role).
 */

const PROVIDER_DESCRIPTIONS = {
	wp_role:
		'Select which WordPress Role values may access this resource. Leave all unchecked to deny everyone (except administrators).',
};

/**
 * @param {Object}   props
 * @param {string}   props.providerId       Active provider ID (used for description lookup).
 * @param {Array}    props.options           Options from provider.options [{id, label}].
 * @param {string[]} props.selectedOptions  Currently checked option IDs.
 * @param {Function} props.onToggle         Called with option ID when a checkbox changes.
 */
export default function RoleOptionsPanel( {
	providerId,
	options,
	selectedOptions,
	onToggle,
} ) {
	const description = PROVIDER_DESCRIPTIONS[ providerId ] || null;

	return (
		<div className="wpb-ac__options-panel">
			{ description && (
				<p className="wpb-ac__panel-description">{ description }</p>
			) }
			<ul className="wpb-ac__checkbox-list">
				{ options.map( ( option ) => (
					<li key={ option.id } className="wpb-ac__checkbox-item">
						<label>
							<input
								type="checkbox"
								value={ option.id }
								checked={ selectedOptions.includes( option.id ) }
								onChange={ () => onToggle( option.id ) }
							/>
							{ option.label }
						</label>
					</li>
				) ) }
			</ul>
		</div>
	);
}
