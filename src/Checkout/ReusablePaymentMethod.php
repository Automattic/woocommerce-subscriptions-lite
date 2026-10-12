<?php
/**
 * ReusablePaymentMethod - tells gateways when a checkout must leave a chargeable payment method.
 *
 * Answers the engine's reusable-payment-method query for carts and orders that start a
 * Lite subscription (a plan line) and for Lite renewal orders (paying one on order-pay
 * should leave a method that can be charged next time). A free first order with a plan
 * line still needs payment, so the gateway runs and saves the method for the renewals.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable payment method answers.
 */
final class ReusablePaymentMethod {

	/**
	 * Bind the query answer and the free-first-order payment filters.
	 */
	public static function register(): void {
		$instance = new self();
		add_filter( 'woocommerce_subscriptions_engine_requires_reusable_payment_method', [ $instance, 'answer' ], 10, 2 );
		add_filter( 'woocommerce_cart_needs_payment', [ $instance, 'cart_needs_payment' ], 10, 2 );
		add_filter( 'woocommerce_order_needs_payment', [ $instance, 'order_needs_payment' ], 10, 3 );
	}

	/**
	 * Whether the order (or, with null, the current cart) needs a reusable payment method.
	 *
	 * @param mixed $requires The answer so far.
	 * @param mixed $order    The order being paid, or null for the current cart.
	 * @return mixed True when Lite will charge it later; else the answer so far.
	 */
	public function answer( $requires, $order = null ) {
		if ( true === $requires ) {
			return $requires;
		}

		if ( $order instanceof WC_Order ) {
			return self::has_plan_line( $order ) || RenewalOrderBuilder::CREATED_VIA === $order->get_created_via() ? true : $requires;
		}

		if ( null === $order && function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ) {
			return self::cart_has_plan_item( WC()->cart ) ? true : $requires;
		}

		return $requires;
	}

	/**
	 * A free cart that starts a subscription still needs payment, so the method is saved.
	 *
	 * @param mixed $needs_payment Whether the cart needs payment.
	 * @param mixed $cart          The cart.
	 * @return mixed
	 */
	public function cart_needs_payment( $needs_payment, $cart ) {
		if ( false !== $needs_payment || ! $cart instanceof WC_Cart || (float) $cart->get_total( 'edit' ) > 0 ) {
			return $needs_payment;
		}

		return self::cart_has_plan_item( $cart ) ? true : $needs_payment;
	}

	/**
	 * A free order that starts a subscription still needs payment while it is in a payable
	 * status, so the method is saved. Never for Lite renewal orders: a free renewal is
	 * completed by Lite.
	 *
	 * @param mixed $needs_payment        Whether the order needs payment.
	 * @param mixed $order                The order.
	 * @param mixed $valid_order_statuses Statuses in which the order can be paid.
	 * @return mixed
	 */
	public function order_needs_payment( $needs_payment, $order, $valid_order_statuses = [] ) {
		if ( false !== $needs_payment || ! $order instanceof WC_Order || ! is_array( $valid_order_statuses )
			|| (float) $order->get_total() > 0
			|| RenewalOrderBuilder::CREATED_VIA === $order->get_created_via()
			|| ! $order->has_status( $valid_order_statuses ) ) {
			return $needs_payment;
		}

		return self::has_plan_line( $order ) ? true : $needs_payment;
	}

	/**
	 * Whether an order has a line carrying a Lite plan.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function has_plan_line( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && (int) $item->get_meta( CartPlanHooks::SELLING_PLAN_ID_KEY ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a cart has an item carrying a Lite plan.
	 *
	 * @param WC_Cart $cart The cart.
	 */
	private static function cart_has_plan_item( WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( is_array( $cart_item ) && (int) ( $cart_item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}
}
