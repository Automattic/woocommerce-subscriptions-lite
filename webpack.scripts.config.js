/**
 * Build config for the Lite frontend/admin bundles.
 *
 * Two webpack configurations are exported:
 *
 *  - `scriptConfig`  builds the classic admin script bundle (`admin.*` +
 *    `style-admin*.css`).
 *  - `moduleConfig`  builds the customer-portal Interactivity API store as an
 *    ESM script module, so `@wordpress/interactivity` externalizes as a real
 *    module import (`import ... from '@wordpress/interactivity'`) and the asset
 *    file records `@wordpress/interactivity` as the module dependency, plus the
 *    portal stylesheet (`style-customer-portal*.css`) extracted from the SCSS
 *    the store entry imports. The portal asset loader enqueues the module via
 *    `wp_enqueue_script_module` and the stylesheet via `wp_enqueue_style`.
 *
 * Both configs emit into the same `build/scripts/` directory, so each one's
 * output-clean is scoped with `keep` to preserve the other's bundle (and the
 * default fonts/images). Without this the two compilations clean each other's
 * output and a single build non-deterministically drops one bundle.
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

// Clean the shared output dir on emit, but keep fonts/images (the wp-scripts
// default) and the sibling bundle's files so the two configs do not wipe each
// other out.
const keepAdminBundle = /(?:^fonts\/|^images\/|admin)/;
const keepPortalBundle = /(?:^fonts\/|^images\/|customer-portal)/;

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
		clean: { keep: keepPortalBundle },
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
		clean: { keep: keepAdminBundle },
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
