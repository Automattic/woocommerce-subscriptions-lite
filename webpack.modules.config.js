/**
 * Script-modules build (ALL Interactivity API view modules).
 *
 * Run via `wp-scripts build --experimental-modules --config
 * webpack.modules.config.js`: the flag makes @wordpress/scripts expose its
 * module build variant (ESM output, `@wordpress/interactivity` externalized
 * as an import-mapped dependency), which this config narrows to the
 * Interactivity API view-module entries - the PDP plan picker and the
 * customer-portal store - and emits into `build/modules/`.
 *
 * Two deliberate additions over the upstream module variant:
 *
 *  - `output.clean`: upstream omits it because its two compilations share one
 *    output directory; this compilation owns `build/modules/` outright, so
 *    the standard clean (keeping `fonts/` and `images/`) is safe.
 *  - `RtlCssPlugin`: upstream's module variant emits no RTL stylesheets; the
 *    customer-portal styles are direction-sensitive, so the plugin is added
 *    to emit the `style-*-rtl.css` variants alongside the extracted CSS.
 */
const path = require( 'path' );

const defaultConfigs = require( '@wordpress/scripts/config/webpack.config' );
const RtlCssPlugin = require( '@wordpress/scripts/plugins/rtlcss-webpack-plugin' );

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
		'customer-portal': path.resolve(
			process.cwd(),
			'src',
			'js',
			'frontend',
			'customer-portal-store.js'
		),
	},
	output: {
		...moduleConfig.output,
		path: path.resolve( process.cwd(), 'build', 'modules' ),
		clean: {
			keep: /^(fonts|images)\//,
		},
	},
	plugins: [ ...moduleConfig.plugins, new RtlCssPlugin() ],
};
