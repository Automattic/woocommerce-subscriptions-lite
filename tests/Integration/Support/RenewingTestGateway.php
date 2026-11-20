<?php
/**
 * RenewingTestGateway - the `dummy` payment gateway the integration suite pays with.
 *
 * The suite loads only WooCommerce and Lite, so it registers this stand-in for the
 * Dummy Payments gateway the dev site runs: it declares the engine feature and
 * `subscriptions`, so contract creation accepts it, and charges nothing. Tests that
 * need engine charges or a gateway that cannot renew use a core gateway (`cod`).
 * Disabled by default, so it changes no checkout or admin-notice test.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Support;

use WC_Payment_Gateway;

/**
 * A disabled `dummy` gateway that can charge Lite renewals.
 */
final class RenewingTestGateway extends WC_Payment_Gateway {

	/**
	 * Gateway id, as the dev site's Dummy Payments gateway.
	 */
	public const ID = 'dummy';

	/**
	 * Set the id, title and features; disabled.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->title              = 'Dummy Payments';
		$this->method_title       = 'Dummy Payments';
		$this->method_description = 'Integration-test gateway that can charge renewals.';
		$this->enabled            = 'no';
		$this->supports           = [ 'products', 'subscriptions', 'subscriptions_engine' ];
	}

	/**
	 * Register it on `woocommerce_payment_gateways`.
	 *
	 * @param mixed $gateways Gateway classes or instances.
	 * @return mixed The gateways with this one added.
	 */
	public static function add( $gateways ) {
		if ( is_array( $gateways ) ) {
			$gateways[] = new self();
		}

		return $gateways;
	}
}
