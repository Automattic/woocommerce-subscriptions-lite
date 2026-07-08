<?php
/**
 * AccountRequirement - force account creation when the cart holds a subscription.
 *
 * A subscription needs an owning customer so the buyer can manage it from My
 * Account, so a guest checking out with a plan line is required to register. One
 * filter (`woocommerce_checkout_registration_required`) covers classic and
 * Blocks/Store API checkout, which is why the deprecated guest-checkout-toggling
 * dance is not needed.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Require a customer account for subscription checkouts.
 */
final class AccountRequirement {

	/**
	 * Wire the registration-required filter. Called once from the bootstrap.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_checkout_registration_required', [ new self(), 'require_account_for_subscriptions' ] );
	}

	/**
	 * Require registration when a guest's cart holds a plan line; otherwise leave
	 * the store's setting untouched.
	 *
	 * @param mixed $required Whether registration is already required (a bool from core).
	 * @return bool
	 */
	public function require_account_for_subscriptions( $required ): bool {
		if ( ! is_user_logged_in() && $this->cart_contains_plan() ) {
			return true;
		}

		return (bool) $required;
	}

	/**
	 * Whether the current cart holds at least one plan line.
	 */
	private function cart_contains_plan(): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( null === $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] ) ) {
				return true;
			}
		}

		return false;
	}
}
