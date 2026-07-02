/**
 * Jest config for JS unit tests.
 *
 * Extends the @wordpress/scripts unit preset and additionally transforms the
 * ESM-only `uuid` module pulled in transitively by @wordpress/components, which
 * the default transformIgnorePatterns would otherwise skip.
 */

const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	transformIgnorePatterns: [ 'node_modules/(?!(.*/)?(uuid)/)' ],
};
