<?php
/**
 * PlanReadFailureLog - one log line per cart item whose plan could not be read.
 *
 * A cart is priced several times per request and each line is displayed by several
 * hooks (classic cart, mini-cart, Store API), and each one reads the line's plan. When
 * that read fails, the first failure for a cart item is logged and the rest of the
 * request stays quiet.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Cart
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Cart;

use RuntimeException;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped record of the cart items whose failed plan read is already logged.
 */
final class PlanReadFailureLog {

	/**
	 * Keys of the cart items whose failed plan read was logged in this request.
	 *
	 * @var array<string, true>
	 */
	private static $logged_cart_items = [];

	/**
	 * Log a failed plan read with the engine message, which customer-facing text never shows,
	 * unless a failure for the same cart item was already logged in this request. A row without
	 * a key is always logged.
	 *
	 * @param string           $cart_item_key Cart item key, or '' when the row carries none.
	 * @param string           $context       What failed, with the class prefix.
	 * @param RuntimeException $error         The read failure.
	 */
	public static function log( string $cart_item_key, string $context, RuntimeException $error ): void {
		if ( '' !== $cart_item_key ) {
			if ( isset( self::$logged_cart_items[ $cart_item_key ] ) ) {
				return;
			}
			self::$logged_cart_items[ $cart_item_key ] = true;
		}

		wc_get_logger()->error( sprintf( '%s: %s', $context, $error->getMessage() ), [ 'source' => Package::EXTENSION_SLUG ] );
	}

	/**
	 * Forget the logged cart items, so each test starts a fresh request.
	 *
	 * @internal For tests only.
	 */
	public static function reset(): void {
		self::$logged_cart_items = [];
	}
}
