/**
 * Engine-provided enum definitions, normalized for the UI.
 *
 * The engine supplies these via window.wcSubscriptionsLitePlans.definitions
 * (see src/Admin/PlansPage.php). The fallbacks keep the UI usable if the
 * bootstrap payload is ever missing a key.
 */

import { __ } from '@wordpress/i18n';

export const DEFAULT_DEFINITIONS = {
	statuses: [
		{
			value: 'active',
			label: __( 'Active', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'archived',
			label: __( 'Archived', 'woocommerce-subscriptions-lite' ),
		},
	],
	billingUnits: [
		{
			value: 'day',
			label: __( 'Day', 'woocommerce-subscriptions-lite' ),
			singular: __( 'day', 'woocommerce-subscriptions-lite' ),
			plural: __( 'days', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'week',
			label: __( 'Week', 'woocommerce-subscriptions-lite' ),
			singular: __( 'week', 'woocommerce-subscriptions-lite' ),
			plural: __( 'weeks', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'month',
			label: __( 'Month', 'woocommerce-subscriptions-lite' ),
			singular: __( 'month', 'woocommerce-subscriptions-lite' ),
			plural: __( 'months', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'year',
			label: __( 'Year', 'woocommerce-subscriptions-lite' ),
			singular: __( 'year', 'woocommerce-subscriptions-lite' ),
			plural: __( 'years', 'woocommerce-subscriptions-lite' ),
		},
	],
	pricingTypes: [
		{
			value: 'percentage',
			label: __( 'Percentage', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'fixed_amount',
			label: __( 'Fixed amount', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'price',
			label: __( 'Fixed price', 'woocommerce-subscriptions-lite' ),
		},
	],
	pricingScopes: [
		{
			value: 'all',
			label: __( 'All cycles', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'first',
			label: __( 'First cycle', 'woocommerce-subscriptions-lite' ),
		},
		{
			value: 'n_cycles',
			label: __( 'First N cycles', 'woocommerce-subscriptions-lite' ),
		},
	],
};

/**
 * Normalize the bootstrapped definitions into the camelCase shape the UI uses.
 *
 * @param {Object} definitions Raw definitions from the bootstrap payload.
 * @return {Object} Normalized definitions.
 */
export function normalizeDefinitions( definitions ) {
	return {
		statuses: definitions?.statuses || DEFAULT_DEFINITIONS.statuses,
		billingUnits:
			definitions?.billing_units || DEFAULT_DEFINITIONS.billingUnits,
		pricingTypes:
			definitions?.pricing_types || DEFAULT_DEFINITIONS.pricingTypes,
		pricingScopes:
			definitions?.pricing_scopes || DEFAULT_DEFINITIONS.pricingScopes,
	};
}
