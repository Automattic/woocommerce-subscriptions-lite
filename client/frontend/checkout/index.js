/**
 * Cart & Checkout Blocks filters for subscription lines.
 *
 * Reads the per-item and cart-level data that Lite's Store API extension
 * exposes (namespace `woocommerce-subscriptions-lite`) to:
 *   - append the billing cadence to the line price/subtotal ("<price/> / month");
 *   - relabel the order total "Total due today" when the cart has a subscription.
 *
 * The cadence string is preformatted server-side (`price_cadence`) so the PHP
 * formatter is the single source of truth and the two surfaces cannot drift.
 * The frequency lives on the price (not a separate row) so the Blocks surface
 * reads the same as the classic cart. No recurring-totals panel - due-today
 * equals the recurring amount in the free tier.
 */

// eslint-disable-next-line import/no-unresolved -- Provided by WooCommerce Blocks at runtime; externalized to `wc-blocks-checkout` at build, not bundled.
import { registerCheckoutFilters } from '@woocommerce/blocks-checkout';
import { __ } from '@wordpress/i18n';

const NAMESPACE = 'woocommerce-subscriptions-lite';

/**
 * The Lite per-item / cart extension payload, or null when absent.
 *
 * @param {Object} extensions Extensions object passed to the filter.
 * @return {Object|null} The namespaced payload.
 */
const liteData = ( extensions ) =>
	extensions && extensions[ NAMESPACE ] ? extensions[ NAMESPACE ] : null;

/**
 * Append the server-formatted cadence to a price-format string
 * ("<price/> / month"), or leave it unchanged for one-time lines.
 *
 * @param {string} value      Price format string containing the `<price/>` token.
 * @param {Object} extensions Cart-item extensions.
 * @return {string} The format string with the cadence appended.
 */
const appendCadence = ( value, extensions ) => {
	const data = liteData( extensions );
	const suffix = data && data.price_cadence ? data.price_cadence : '';
	return suffix ? `${ value } ${ suffix }` : value;
};

registerCheckoutFilters( NAMESPACE, {
	cartItemPrice: appendCadence,
	subtotalPriceFormat: appendCadence,
	totalLabel: ( value, extensions ) => {
		const data = liteData( extensions );
		return data && data.has_subscriptions
			? __( 'Total due today', 'woocommerce-subscriptions-lite' )
			: value;
	},
} );
