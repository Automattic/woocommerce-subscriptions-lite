/**
 * Store currency, sourced from WooCommerce's own client-side settings
 * (window.wcSettings) rather than a duplicated server payload. WooCommerce
 * already decodes the symbol and exposes it on admin screens where the
 * wc-settings script is enqueued (SettingsPage declares it as a dependency);
 * we only remap its key names to the shape the formatters expect
 * (symbolPosition -> position, precision -> decimals). Fallbacks keep
 * formatting sane if the global is somehow absent.
 *
 * @return {Object} Currency settings: code, symbol, position, separators, decimals.
 */
function resolveCurrency() {
	const wc = window.wcSettings?.currency || {};
	return {
		code: wc.code || 'USD',
		symbol: wc.symbol || '$',
		position: wc.symbolPosition || 'left',
		thousandSeparator: wc.thousandSeparator ?? ',',
		decimalSeparator: wc.decimalSeparator ?? '.',
		decimals: Number.isFinite( wc.precision ) ? wc.precision : 2,
	};
}

export const config = {
	restBase:
		window.wcSubscriptionsLitePlans?.restBase ||
		'/wc/v3/subscriptions-engine/plans',
	definitions: window.wcSubscriptionsLitePlans?.definitions || {},
	defaultStatus: window.wcSubscriptionsLitePlans?.defaultStatus || 'active',
	extensionSlug:
		window.wcSubscriptionsLitePlans?.extensionSlug ||
		'woocommerce-subscriptions-lite',
	currency: resolveCurrency(),
};
