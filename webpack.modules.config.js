/**
 * Script-modules build (Interactivity API view modules).
 *
 * Run via `wp-scripts build --experimental-modules --config
 * webpack.modules.config.js`: the flag makes @wordpress/scripts expose its
 * module build variant (ESM output, `@wordpress/interactivity` externalized
 * as an import-mapped dependency), which this config narrows to the frontend
 * view entries and emits into `build/modules/`.
 */
const path = require( 'path' );

const defaultConfigs = require( '@wordpress/scripts/config/webpack.config' );

if ( ! Array.isArray( defaultConfigs ) ) {
	throw new Error(
		'The modules build needs the --experimental-modules flag so @wordpress/scripts exposes its module configuration.'
	);
}

const [ , moduleConfig ] = defaultConfigs;

module.exports = {
	...moduleConfig,
	entry: {
		'plan-picker-view': path.resolve(
			process.cwd(),
			'client',
			'frontend',
			'plan-picker',
			'view.js'
		),
	},
	output: {
		...moduleConfig.output,
		path: path.resolve( process.cwd(), 'build', 'modules' ),
	},
};
