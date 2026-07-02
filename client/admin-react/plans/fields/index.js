/**
 * Plan field registry.
 *
 * Lite ships a fixed set of built-in fields but lets extensions inject their
 * own via the `woocommerce_subscriptions_lite_plan_fields` filter. An extension
 * enqueues a script that declares `wp-hooks` as a dependency and registers a
 * descriptor (same shape as ./builtins.js), owning its Edit component,
 * validation, and engine mapping end to end - no change to Lite core required.
 *
 * Example (in an extension):
 *
 *   import { addFilter } from '@wordpress/hooks';
 *   addFilter(
 *     'woocommerce_subscriptions_lite_plan_fields',
 *     'my-extension/signup-fee',
 *     ( fields ) => [ ...fields, signupFeeDescriptor ]
 *   );
 */

import { applyFilters } from '@wordpress/hooks';
import { builtInFields } from './builtins';

export const PLAN_FIELDS_FILTER = 'woocommerce_subscriptions_lite_plan_fields';

/**
 * Coerce a filtered value back into a valid, de-duplicated descriptor array.
 *
 * Guards against a misbehaving filter returning a non-array or descriptors
 * without an id; last-registered wins on id collision so extensions can
 * override a built-in field.
 *
 * @param {*} fields Raw filtered value.
 * @return {Array<Object>} Normalized descriptor list.
 */
function normalizeFields( fields ) {
	if ( ! Array.isArray( fields ) ) {
		return builtInFields();
	}

	const byId = new Map();
	fields.forEach( ( field ) => {
		if ( field && typeof field === 'object' && field.id ) {
			byId.set( field.id, field );
		}
	} );

	return Array.from( byId.values() );
}

/**
 * Build the plan field registry: built-ins plus any injected fields.
 *
 * @param {Object} context             Context passed to the filter.
 * @param {Object} context.definitions Normalized engine definitions.
 * @param {Object} context.config      Bootstrapped page config.
 * @return {Array<Object>} The field descriptors, in render order.
 */
export function buildFieldRegistry( context = {} ) {
	const fields = applyFilters( PLAN_FIELDS_FILTER, builtInFields(), context );

	return normalizeFields( fields );
}
