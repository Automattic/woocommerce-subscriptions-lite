/**
 * Classic-scripts build (admin bundles).
 *
 * Builds the classic admin script bundles - `admin-react.*` and `admin-php.*`
 * plus their extracted `style-admin-*.css` (and RTL variants) - into
 * `build/scripts/`.
 *
 * This config owns `build/scripts/` outright: Interactivity API view modules
 * (ESM script modules) are NOT built here - ALL of them live in
 * `webpack.modules.config.js` and emit into `build/modules/`. With one
 * compilation per output directory, the default output-clean semantics apply
 * as-is (wp-scripts keeps `fonts/` and `images/`), with no cross-bundle
 * `keep` carve-outs.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

const sharedPlugins = defaultConfig.plugins.filter(
	( plugin ) =>
		plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
		plugin.constructor.name !== 'CopyPlugin'
);

module.exports = {
	...defaultConfig,
	entry: {
		'admin-react': path.resolve(
			process.cwd(),
			'client',
			'admin-react',
			'index.js'
		),
		'admin-php': path.resolve(
			process.cwd(),
			'client',
			'admin-php',
			'index.js'
		),
		'checkout-filters': path.resolve(
			process.cwd(),
			'client',
			'frontend',
			'checkout',
			'index.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'build', 'scripts' ),
	},
	plugins: [
		...sharedPlugins,
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
