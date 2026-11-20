<?php
/**
 * RenewalGateways - which payment gateways can charge Lite renewals automatically.
 *
 * One answer for every caller: the renewal path asks it before charging, and checkout asks
 * it to offer only gateways a subscription can renew with. A gateway qualifies when it
 * speaks the engine contract and declares WooCommerce's `subscriptions` feature. The admin
 * warning asks whether any enabled gateway qualifies, and shares the checkout's setup message.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Payments
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Payments;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal capability of payment gateways.
 */
final class RenewalGateways {

	/**
	 * The gateway feature, besides the engine's, that says a gateway can charge renewals
	 * automatically: the WooCommerce feature gateways already declare for subscriptions.
	 */
	public const FEATURE = 'subscriptions';

	/**
	 * Whether the gateway can charge Lite renewals automatically.
	 *
	 * @param string $gateway_id Gateway id.
	 */
	public static function can_charge_renewals( string $gateway_id ): bool {
		return Payments::gateway_supports( $gateway_id, self::FEATURE );
	}

	/**
	 * Whether an enabled gateway can charge Lite renewals automatically. Reads the enabled
	 * setting the merchant controls, not checkout availability, which depends on the cart.
	 */
	public static function has_enabled_renewal_gateway(): bool {
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			if ( wc_string_to_bool( $gateway->enabled ) && self::can_charge_renewals( (string) $gateway_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a store manager does when no enabled gateway can charge renewals: escaped HTML
	 * linking to the payments settings.
	 */
	public static function get_setup_message(): string {
		return sprintf(
			/* translators: 1: opening link tag to the payments settings, 2: closing link tag. */
			esc_html__( 'No enabled payment method can charge subscription renewals automatically. Enable one that supports subscriptions in %1$sPayments settings%2$s.', 'woocommerce-subscriptions-lite' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '">',
			'</a>'
		);
	}
}
