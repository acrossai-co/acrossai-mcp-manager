// WordPress webpack config
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Try to import getWebpackEntryPoints, fallback to empty object if not available
let getWebpackEntryPoints;
try {
	( {
		getWebpackEntryPoints,
	} = require( '@wordpress/scripts/utils/config' ) );
} catch ( error ) {
	// Fallback for older versions of @wordpress/scripts
	getWebpackEntryPoints = () => ( {} );
}

// Plugins
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );
const CopyPlugin = require( 'copy-webpack-plugin' );

// Utilities
const path = require( 'path' );
const { globSync } = require( 'glob' );

// Dynamically load SCSS files for core blocks (like block styles)
const blockStylesheets = () =>
	globSync( './src/scss/blocks/core/*.scss' ).reduce( ( files, filepath ) => {
		const name = path.parse( filepath ).name;
		files[ `css/blocks/core/${ name }` ] = path.resolve(
			process.cwd(),
			'src/scss/blocks/core',
			`${ name }.scss`
		);
		return files;
	}, {} );

// Dynamically load custom blocks from block.json files
const blockJsonFiles = globSync( './src/blocks/**/block.json' );
const blockEntries = {};

blockJsonFiles.forEach( ( jsonFile ) => {
	const blockPath = path.dirname( jsonFile );
	const relativePath = path.relative( './src/blocks', blockPath );

	// Add index.js if it exists
	const indexPath = path.join( blockPath, 'index.js' );
	if ( globSync( indexPath ).length > 0 ) {
		blockEntries[ `blocks/${ relativePath }/index` ] =
			path.resolve( indexPath );
	}

	// Add view.js if it exists
	const viewPath = path.join( blockPath, 'view.js' );
	if ( globSync( viewPath ).length > 0 ) {
		blockEntries[ `blocks/${ relativePath }/view` ] =
			path.resolve( viewPath );
	}
} );

// Final Webpack export
module.exports = {
	...defaultConfig,
	entry: {
		...getWebpackEntryPoints(), // Default WP entry points (e.g., index.js)
		...blockStylesheets(), // Core block styles (scss)
		...blockEntries, // Custom blocks (index.js/view.js)
		'js/frontend': path.resolve( process.cwd(), 'src/js', 'frontend.js' ),
		'js/backend': path.resolve( process.cwd(), 'src/js', 'backend.js' ),
		'css/frontend': path.resolve(
			process.cwd(),
			'src/scss',
			'frontend.scss'
		),
		'css/backend': path.resolve(
			process.cwd(),
			'src/scss',
			'backend.scss'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'build' ),
		filename: '[name].js',
	},
	plugins: [
		...defaultConfig.plugins,

		// Remove empty .js files after WP script/plugin generation
		new RemoveEmptyScriptsPlugin( {
			stage: RemoveEmptyScriptsPlugin.STAGE_AFTER_PROCESS_PLUGINS,
		} ),

		// Safely copy media or other static assets
		new CopyPlugin( {
			patterns: [
				{
					from: './src/media',
					to: './media',
					noErrorOnMissing: true,
				},
				{
					from: './src/fonts',
					to: './fonts',
					noErrorOnMissing: true,
				},
			],
		} ),
	],
};
