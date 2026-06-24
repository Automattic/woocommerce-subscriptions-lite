<?php
/**
 * Providers - resolves the active customer-portal data provider.
 *
 * Lite owns the choice of implementation; the filter exists for tests and for
 * overlays that need to substitute their own reads. The default is the fixture
 * provider while the portal is built UI-first; once the engine-backed reads are
 * wired, the default flips to {@see EngineDataProvider}.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

defined( 'ABSPATH' ) || exit;

/**
 * Data-provider resolver.
 */
final class Providers {

	/**
	 * Resolve the active {@see DataProvider}.
	 *
	 * Defaults to {@see FixtureDataProvider}. The
	 * `woocommerce_subscriptions_lite_customer_portal_data_provider` filter can
	 * substitute another implementation; a non-DataProvider return is ignored
	 * and the default is used so a misbehaving filter cannot break the portal.
	 *
	 * @return DataProvider
	 */
	public static function resolve(): DataProvider {
		$default = new FixtureDataProvider();

		/**
		 * Filters the customer-portal data provider.
		 *
		 * Lite selects the implementation itself; this filter is for tests and
		 * overlays. Return an instance of
		 * {@see \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\DataProvider};
		 * any other value is ignored.
		 *
		 * @since 0.0.1
		 *
		 * @param DataProvider $provider The default data provider.
		 */
		$provider = apply_filters( 'woocommerce_subscriptions_lite_customer_portal_data_provider', $default );

		return $provider instanceof DataProvider ? $provider : $default;
	}
}
