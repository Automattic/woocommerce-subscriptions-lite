/**
 * Display formatters for plan list columns. Currency-agnostic - values are
 * rendered as supplied by the engine.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Format a plan's billing cadence, e.g. "month" or "3 weeks".
 *
 * @param {Object} plan        Engine plan object.
 * @param {Object} definitions Normalized engine definitions.
 * @return {string} Human-readable frequency label.
 */
export function formatFrequency( plan, definitions = {} ) {
	const billing = plan.billing_policy || {};
	const interval = Number( billing.interval || 1 );
	const unit = billing.period || 'month';
	const units = definitions.billingUnits || [];
	const unitDefinition = units.find(
		( billingUnit ) => billingUnit.value === unit
	);

	if ( interval === 1 ) {
		return unitDefinition?.singular || unitDefinition?.label || unit;
	}

	return sprintf(
		/* translators: 1: billing interval, 2: billing period unit. */
		__( '%1$d %2$s', 'woocommerce-subscriptions-lite' ),
		interval,
		unitDefinition?.plural || unitDefinition?.label || unit
	);
}

/**
 * Format a plan's first pricing policy as a discount label.
 *
 * @param {Object} plan Engine plan object.
 * @return {string} Human-readable discount label, or '-' when none applies.
 */
export function formatDiscount( plan ) {
	const firstPolicy = plan.pricing_policy?.policies?.[ 0 ];
	if ( ! firstPolicy ) {
		return '-';
	}

	const value = Number( firstPolicy.value || 0 );
	let label = '-';
	if ( firstPolicy.type === 'percentage' ) {
		label = sprintf(
			/* translators: %s is a discount percentage value, e.g. '10' for "10% off". */
			__( '%s%% off', 'woocommerce-subscriptions-lite' ),
			value
		);
	} else if ( firstPolicy.type === 'fixed_amount' ) {
		label = sprintf(
			/* translators: %s is a monetary or numeric discount, e.g. '$10' for "$10 off". */
			__( '%s off', 'woocommerce-subscriptions-lite' ),
			value
		);
	} else if ( firstPolicy.type === 'price' ) {
		label = String( value );
	}

	if ( Number( firstPolicy.duration_cycles ) === 1 ) {
		return `${ label } (${ __(
			'first cycle',
			'woocommerce-subscriptions-lite'
		) })`;
	}
	if ( Number( firstPolicy.duration_cycles ) > 1 ) {
		return `${ label } (${ firstPolicy.duration_cycles } ${ __(
			'cycles',
			'woocommerce-subscriptions-lite'
		) })`;
	}

	return label;
}
