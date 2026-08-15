/**
 * F069 T031 — Step 1: inline "Create a new server" form.
 *
 * Uses vanilla HTML inputs (not DataForm) for MVP tightness — server-create
 * has only 5 fields with no filter/sort/pagination concern; DEV5 doesn't
 * apply since the wizard is interactive multi-field per D37, but for a
 * one-shot create form inside a larger DataViews-heavy surface, plain
 * WordPress-styled inputs are the right call. A Phase 8 follow-up can
 * migrate to DataForm if the field count grows.
 *
 * Slug auto-derived from Name via a JS sanitize_title shim; route auto-
 * derived from slug. Both are editable.
 *
 * On successful create, server_id lands in wizardState via the shared
 * saveStep response handler.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useWizardState from '../hooks/useWizardState.js';

/**
 * JS-side sanitize_title shim — mirrors WP core sanitize_title for the
 * common case (lowercase alphanumeric + hyphens; strips everything else).
 * The server-side MCPServerFieldSanitizer applies the authoritative
 * sanitize_title on receive; this is a UX helper only.
 */
const jsSanitizeTitle = ( input ) => {
	return String( input )
		.toLowerCase()
		.replace( /[^a-z0-9\-_]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.replace( /-{2,}/g, '-' );
};

const Step1_ServerCreate = ( { onCreated, onCancel } ) => {
	const { saveStep, isLoading } = useWizardState();
	const [ form, setForm ] = useState( {
		server_name: '',
		server_slug: '',
		description: '',
		server_route_namespace: 'mcp',
		server_route: '',
		server_version: 'v1.0.0',
	} );
	const [ slugTouched, setSlugTouched ] = useState( false );
	const [ routeTouched, setRouteTouched ] = useState( false );
	const [ localError, setLocalError ] = useState( null );

	const handleNameChange = ( value ) => {
		setForm( ( prev ) => ( {
			...prev,
			server_name: value,
			server_slug: slugTouched ? prev.server_slug : jsSanitizeTitle( value ),
			server_route: routeTouched
				? prev.server_route
				: jsSanitizeTitle( value ),
		} ) );
	};

	const handleSlugChange = ( value ) => {
		setSlugTouched( true );
		setForm( ( prev ) => ( {
			...prev,
			server_slug: value,
			server_route: routeTouched ? prev.server_route : jsSanitizeTitle( value ),
		} ) );
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		setLocalError( null );
		if ( ! form.server_name.trim() ) {
			setLocalError(
				__( 'Server name is required.', 'acrossai-mcp-manager' )
			);
			return;
		}
		const response = await saveStep( 1, { new_server: form } );
		if ( response && response.wizardState && response.wizardState.server_id ) {
			onCreated?.( response.wizardState.server_id );
		}
	};

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Create a new MCP server', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'The wizard needs a server to configure. Fill in a name — the rest is auto-filled.',
					'acrossai-mcp-manager'
				) }
			</p>

			<form onSubmit={ handleSubmit }>
				<div style={ { marginBottom: 16 } }>
					<label>
						<strong>{ __( 'Name', 'acrossai-mcp-manager' ) }</strong>
						<span style={ { color: '#cc1818', marginLeft: 4 } }>*</span>
						<input
							type="text"
							value={ form.server_name }
							onChange={ ( e ) => handleNameChange( e.target.value ) }
							required
							style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4 } }
						/>
					</label>
				</div>

				<div style={ { marginBottom: 16 } }>
					<label>
						<strong>{ __( 'Slug', 'acrossai-mcp-manager' ) }</strong>
						<input
							type="text"
							value={ form.server_slug }
							onChange={ ( e ) => handleSlugChange( e.target.value ) }
							style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
						/>
					</label>
				</div>

				<div style={ { marginBottom: 16 } }>
					<label>
						<strong>{ __( 'Description', 'acrossai-mcp-manager' ) }</strong>
						<textarea
							value={ form.description }
							onChange={ ( e ) =>
								setForm( ( p ) => ( { ...p, description: e.target.value } ) )
							}
							rows={ 3 }
							style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4 } }
						/>
					</label>
				</div>

				<div style={ { display: 'flex', gap: 12, marginBottom: 16 } }>
					<label style={ { flex: 1 } }>
						<strong>{ __( 'Route namespace', 'acrossai-mcp-manager' ) }</strong>
						<input
							type="text"
							value={ form.server_route_namespace }
							onChange={ ( e ) =>
								setForm( ( p ) => ( {
									...p,
									server_route_namespace: e.target.value,
								} ) )
							}
							style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
						/>
					</label>
					<label style={ { flex: 1 } }>
						<strong>{ __( 'Route', 'acrossai-mcp-manager' ) }</strong>
						<input
							type="text"
							value={ form.server_route }
							onChange={ ( e ) => {
								setRouteTouched( true );
								setForm( ( p ) => ( { ...p, server_route: e.target.value } ) );
							} }
							style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
						/>
					</label>
					<label style={ { flex: '0 0 120px' } }>
						<strong>{ __( 'Version', 'acrossai-mcp-manager' ) }</strong>
						<input
							type="text"
							value={ form.server_version }
							onChange={ ( e ) =>
								setForm( ( p ) => ( { ...p, server_version: e.target.value } ) )
							}
							style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
						/>
					</label>
				</div>

				{ localError && (
					<div style={ { color: '#cc1818', marginBottom: 16 } } role="alert">
						{ localError }
					</div>
				) }

				<div style={ { display: 'flex', gap: 12 } }>
					<button type="submit" className="qs-btn" disabled={ isLoading }>
						{ isLoading
							? __( 'Creating…', 'acrossai-mcp-manager' )
							: __( 'Create server', 'acrossai-mcp-manager' ) }
					</button>
					<button
						type="button"
						className="qs-btn qs-btn--secondary"
						onClick={ onCancel }
						disabled={ isLoading }
					>
						{ __( 'Cancel', 'acrossai-mcp-manager' ) }
					</button>
				</div>
			</form>
		</div>
	);
};

export default Step1_ServerCreate;
