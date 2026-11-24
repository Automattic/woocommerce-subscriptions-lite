/**
 * Display formatters for plan list columns. Monetary values are rendered
 * with the store's currency settings (see config.currency); other values
 * are rendered as supplied by the engine.
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { config } from './config';
import { isDiscountEntry } from './fields/discount-marker';

/**
 * Placeholder rendered in list cells with no value (em dash).
 */
export const EMPTY_VALUE = '\u2014';

/**
 * Format a plan's billing cadence, e.g. "1 month" or "3 weeks". The interval
 * is always included, matching the canonical list rendering.
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
	const unitLabel =
		( interval === 1
			? unitDefinition?.singular
			: unitDefinition?.plural ) ||
		unitDefinition?.label ||
		unit;

	return sprintf(
		/* translators: 1: billing interval, 2: billing period unit. */
		__( '%1$d %2$s', 'woocommerce-subscriptions-lite' ),
		interval,
		unitLabel
	);
}

/**
 * Format a numeric amount with the store's currency settings.
 *
 * @param {number|string} value    Amount to format.
 * @param {Object}        currency Currency settings (symbol, position,
 *                                 separators, decimals). Defaults to the
 *                                 bootstrapped store currency.
 * @return {string} Currency-formatted amount, e.g. "$1,234.50".
 */
export function formatCurrency( value, currency = config.currency ) {
	const decimals = Number( currency.decimals );
	const fixed = Number( value || 0 ).toFixed(
		Number.isFinite( decimals ) ? decimals : 2
	);
	const [ whole, fraction ] = fixed.split( '.' );
	const grouped = whole.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		currency.thousandSeparator ?? ','
	);
	const amount = fraction
		? grouped + ( currency.decimalSeparator ?? '.' ) + fraction
		: grouped;

	switch ( currency.position ) {
		case 'right':
			return amount + currency.symbol;
		case 'left_space':
			return currency.symbol + ' ' + amount;
		case 'right_space':
			return amount + ' ' + currency.symbol;
		case 'left':
		default:
			return currency.symbol + amount;
	}
}

/**
 * Format a plan's discount entry (the pricing entry that carries the discount
 * marker) as a discount label.
 *
 * @param {Object} plan     Engine plan object.
 * @param {Object} currency Currency settings for monetary discount types.
 * @return {string} Discount label (e.g. "14%", "$10.00"), or the em dash
 *                  placeholder when no discount applies.
 */
export function formatDiscount( plan, currency = config.currency ) {
	const policies = plan.pricing_policy?.policies;
	const discountEntry = Array.isArray( policies )
		? policies.find( isDiscountEntry )
		: null;
	if ( ! discountEntry ) {
		return EMPTY_VALUE;
	}

	const value = Number( discountEntry.value || 0 );
	let label = EMPTY_VALUE;
	if ( discountEntry.type === 'percentage' ) {
		label = sprintf(
			/* translators: %s: discount percentage value, e.g. '14' for "14%". */
			__( '%s%%', 'woocommerce-subscriptions-lite' ),
			value
		);
	} else if (
		discountEntry.type === 'fixed_amount' ||
		discountEntry.type === 'price'
	) {
		label = formatCurrency( value, currency );
	} else if ( discountEntry.type === 'bogo' ) {
		label = __( 'Buy one, get one', 'woocommerce-subscriptions-lite' );
	}

	if ( Number( discountEntry.duration_cycles ) === 1 ) {
		return `${ label } (${ __(
			'first cycle',
			'woocommerce-subscriptions-lite'
		) })`;
	}
	if ( Number( discountEntry.duration_cycles ) > 1 ) {
		return `${ label } (${ discountEntry.duration_cycles } ${ __(
			'cycles',
			'woocommerce-subscriptions-lite'
		) })`;
	}

	return label;
}

/**
 * Format a plan's expiration as a payment count.
 *
 * @param {Object} plan Engine plan object.
 * @return {string} Payment count (e.g. "5 payments"), or the em dash
 *                  placeholder when the plan is open-ended.
 */
export function formatExpiration( plan ) {
	const maxCycles = Number( plan.billing_policy?.max_cycles || 0 );
	if ( maxCycles > 0 ) {
		return sprintf(
			/* translators: %d: number of payments before the subscription expires. */
			_n(
				'%d payment',
				'%d payments',
				maxCycles,
				'woocommerce-subscriptions-lite'
			),
			maxCycles
		);
	}

	return EMPTY_VALUE;
}
