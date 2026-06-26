export const config = {
	restBase:
		window.wcSubscriptionsLitePlans?.restBase ||
		'/wc/v3/subscriptions-engine/plans',
	definitions: window.wcSubscriptionsLitePlans?.definitions || {},
	defaultStatus: window.wcSubscriptionsLitePlans?.defaultStatus || 'active',
	extensionSlug:
		window.wcSubscriptionsLitePlans?.extensionSlug ||
		'woocommerce-subscriptions-lite',
};
