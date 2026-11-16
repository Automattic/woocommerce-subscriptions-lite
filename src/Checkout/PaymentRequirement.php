<?php
/**
 * PaymentRequirement - make subscription checkouts collect a payment method Lite can charge later.
 *
 * The payment-side counterpart of {@see AccountRequirement}. Answers the engine's reusable-payment-method query for carts and orders that start a
 * Lite subscription (a plan line) and for Lite renewal orders (paying one on order-pay
 * should leave a method that can be charged next time). A free first order whose
 * subscription will charge later (a recurring total above 0) still needs payment, so the
 * gateway runs and saves the method for the renewals; a free subscription needs none.
 * A subscription checkout offers only gateways that can charge its renewals automatically:
 * Lite has no manual-renewal fallback to sell a subscription with any other method.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Payments\RenewalGateways;
use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Payment requirements for subscription checkouts.
 */
final class PaymentRequirement {

	/**
	 * Bind the saved-method query, the free-first-order payment filters and the gateway filter.
	 */
	public static function register(): void {
		$instance = new self();
		add_filter( 'woocommerce_subscriptions_engine_requires_reusable_payment_method', [ $instance, 'is_saving_payment_method_required' ], 10, 2 );
		add_filter( 'woocommerce_cart_needs_payment', [ $instance, 'is_cart_payment_required' ], 10, 2 );
		add_filter( 'woocommerce_order_needs_payment', [ $instance, 'is_order_payment_required' ], 10, 3 );
		add_filter( 'woocommerce_available_payment_gateways', [ $instance, 'get_available_payment_gateways' ] );
	}

	/**
	 * The gateways the shopper may pay with: only those that can charge renewals
	 * automatically while the payment starts a subscription, all of them otherwise.
	 *
	 * @param mixed $gateways Available gateways keyed by id.
	 * @return mixed The gateways, filtered when the payment starts a subscription.
	 */
	public function get_available_payment_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::is_subscription_payment() ) {
			return $gateways;
		}

		return array_filter(
			$gateways,
			static function ( $gateway_id ): bool {
				return RenewalGateways::can_charge_renewals( (string) $gateway_id );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Whether the payment in this request starts a Lite subscription: the current cart
	 * holds a plan item. The cart is read only once the session has loaded.
	 */
	private static function is_subscription_payment(): bool {
		return did_action( 'wp_loaded' ) > 0 && function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && self::cart_has_item_with_plan( WC()->cart );
	}

	/**
	 * Whether paying the order (or, with null, the current cart) must save a reusable payment method.
	 *
	 * The filter value is left untyped: another callback may have returned a non-bool.
	 *
	 * @param mixed $is_required Whether another extension already requires it.
	 * @param mixed $order       The order being paid, or null for the current cart.
	 * @return bool True when another extension requires it or Lite will charge the method later.
	 */
	public function is_saving_payment_method_required( $is_required, $order = null ): bool {
		if ( (bool) $is_required ) {
			return true;
		}

		if ( $order instanceof WC_Order ) {
			return self::order_has_item_with_plan( $order ) || RenewalOrderBuilder::CREATED_VIA === $order->get_created_via();
		}

		return null === $order && function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && self::cart_has_item_with_plan( WC()->cart );
	}

	/**
	 * Whether the cart needs payment: a free cart that starts a subscription charging later
	 * still does, so the method is saved.
	 *
	 * @param mixed $is_required Whether the cart needs payment so far.
	 * @param mixed $cart        The cart.
	 * @return bool
	 */
	public function is_cart_payment_required( $is_required, $cart ): bool {
		if ( (bool) $is_required ) {
			return true;
		}

		// Only a free cart is Lite's case. Core already requires payment for a paid cart, so
		// a false here means another extension turned it off on purpose; leave that alone.
		return $cart instanceof WC_Cart && (float) $cart->get_total( 'edit' ) <= 0 && self::get_cart_recurring_total( $cart ) > 0;
	}

	/**
	 * Whether the order needs payment: a free order that starts a subscription charging later
	 * still does while it is in a payable status, so the method is saved. Never for Lite
	 * renewal orders: a free renewal is completed by Lite.
	 *
	 * @param mixed $is_required          Whether the order needs payment so far.
	 * @param mixed $order                The order.
	 * @param mixed $valid_order_statuses Statuses in which the order can be paid.
	 * @return bool
	 */
	public function is_order_payment_required( $is_required, $order, $valid_order_statuses = [] ): bool {
		if ( (bool) $is_required ) {
			return true;
		}

		// Only a free order is Lite's case; a false for a paid order is another extension's
		// deliberate choice, as in is_cart_payment_required().
		return $order instanceof WC_Order && is_array( $valid_order_statuses )
			&& (float) $order->get_total() <= 0
			&& RenewalOrderBuilder::CREATED_VIA !== $order->get_created_via()
			&& $order->has_status( $valid_order_statuses )
			&& self::get_order_recurring_total( $order ) > 0;
	}

	/**
	 * The amount the order's subscription renews for: its plan lines with their tax, plus
	 * shipping (the contract's billing total, as checkout records it).
	 *
	 * @param WC_Order $order The order.
	 */
	private static function get_order_recurring_total( WC_Order $order ): float {
		$total    = 0.0;
		$has_plan = false;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && (int) $item->get_meta( CartPlanHooks::SELLING_PLAN_ID_KEY ) > 0 ) {
				$has_plan = true;
				$total   += (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}

		return $has_plan ? $total + (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() : 0.0;
	}

	/**
	 * The amount the cart's subscription renews for: its plan items with their tax, plus
	 * shipping.
	 *
	 * @param WC_Cart $cart The cart.
	 */
	private static function get_cart_recurring_total( WC_Cart $cart ): float {
		$total    = 0.0;
		$has_plan = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( is_array( $cart_item ) && (int) ( $cart_item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] ?? 0 ) > 0 ) {
				$has_plan = true;
				$total   += (float) ( $cart_item['line_total'] ?? 0 ) + (float) ( $cart_item['line_tax'] ?? 0 );
			}
		}

		return $has_plan ? $total + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() : 0.0;
	}

	/**
	 * Whether an order has a line carrying a Lite plan.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function order_has_item_with_plan( WC_Order $order ): bool {
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
	private static function cart_has_item_with_plan( WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( is_array( $cart_item ) && (int) ( $cart_item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}
}
