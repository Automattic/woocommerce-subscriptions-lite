<?php
/**
 * RenewalGateways - which payment gateways can charge Lite renewals automatically.
 *
 * One answer for every caller: the renewal path asks it before charging, and checkout asks
 * it to offer only gateways a subscription can renew with. A gateway qualifies when it
 * speaks the engine contract and declares WooCommerce's `subscriptions` feature.
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
}
