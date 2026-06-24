/**
 * Build config for the Lite frontend/admin bundles.
 *
 * Two webpack configurations are exported:
 *
 *  - `scriptConfig`  builds the classic admin script bundle.
 *  - `moduleConfig`  builds the customer-portal Interactivity API store as an
 *    ESM script module, so `@wordpress/interactivity` externalizes as a real
 *    module import (`import ... from '@wordpress/interactivity'`) and the asset
 *    file records `@wordpress/interactivity` as the module dependency. The
 *    portal asset loader enqueues it via `wp_enqueue_script_module`.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

const sharedPlugins = defaultConfig.plugins.filter(
	( plugin ) =>
		plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
		plugin.constructor.name !== 'CopyPlugin'
);

const outputPath = path.resolve( process.cwd(), 'build', 'scripts' );

const scriptConfig = {
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
	},
	output: {
		...defaultConfig.output,
		path: outputPath,
	},
	plugins: [
		...sharedPlugins,
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};

const moduleConfig = {
	...defaultConfig,
	entry: {
		'customer-portal': path.resolve(
			process.cwd(),
			'src',
			'js',
			'frontend',
			'customer-portal-store.js'
		),
	},
	experiments: {
		...defaultConfig.experiments,
		outputModule: true,
	},
	output: {
		...defaultConfig.output,
		path: outputPath,
		module: true,
		chunkFormat: 'module',
		environment: {
			...( defaultConfig.output && defaultConfig.output.environment ),
			module: true,
		},
		library: {
			...( defaultConfig.output && defaultConfig.output.library ),
			type: 'module',
		},
	},
	plugins: [
		...sharedPlugins,
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};

module.exports = [ scriptConfig, moduleConfig ];
