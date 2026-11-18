<?php
/**
 * RenewalGatewayNotice - warns store managers when no enabled gateway can renew.
 *
 * Subscription checkouts offer only gateways that can charge renewals automatically, so a
 * store selling on plans with no such gateway enabled cannot sell subscriptions at all.
 * The warning stays while that holds, on WooCommerce screens, Lite's screen and Plugins.
 * WooCommerce removes other plugins' notices from its React Settings > Payments page, so
 * it does not show there (an in-page message is WOOSUBS-2177).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Payments\RenewalGateways;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use WP_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notice for a store with no enabled gateway that can charge renewals.
 */
final class RenewalGatewayNotice {

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		add_action( 'admin_notices', [ new self(), 'render' ] );
	}

	/**
	 * Print the warning when it applies. Checks run cheapest first: for a store with an
	 * enabled gateway that can renew, the notice runs no query of its own (the first call
	 * on a request may load the gateways).
	 */
	public function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) || ! self::is_targeted_screen() ) {
			return;
		}

		if ( RenewalGateways::has_enabled_renewal_gateway() ) {
			return;
		}

		if ( ! ( new ApplicabilityStore() )->has_product_on_plans() ) {
			return;
		}

		$message = sprintf(
			'%1$s %2$s',
			'<strong>' . esc_html__( 'Customers can\'t buy subscriptions.', 'woocommerce-subscriptions-lite' ) . '</strong>',
			RenewalGateways::get_setup_message()
		);

		wp_admin_notice( $message, [ 'type' => 'warning' ] );
	}

	/**
	 * Whether the current screen is a WooCommerce screen, Lite's subscriptions screen or
	 * the Plugins screen.
	 */
	private static function is_targeted_screen(): bool {
		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen ) {
			return false;
		}

		return in_array( $screen->id, [ 'plugins', 'woocommerce_page_' . PageController::PAGE_SLUG ], true )
			|| in_array( $screen->id, wc_get_screen_ids(), true );
	}
}
