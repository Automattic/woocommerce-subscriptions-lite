/**
 * ESLint flat config for WooCommerce Subscriptions Lite.
 *
 * Re-uses the @wordpress/scripts default flat config and appends one settings
 * block: `@wordpress/interactivity` is a runtime-provided WordPress script
 * module (externalised by the build, never bundled), so it is declared as a
 * core module to satisfy the import resolver without adding it as an npm
 * dependency.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		settings: {
			'import/core-modules': [ '@wordpress/interactivity' ],
		},
	},
];
