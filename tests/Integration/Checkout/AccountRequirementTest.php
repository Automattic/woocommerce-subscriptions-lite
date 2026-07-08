<?php
/**
 * Integration tests for the subscription account requirement.
 *
 * The filter is exercised against the real WooCommerce cart: a real applicable
 * product added with a plan (as the PDP submit carries it) drives the
 * "cart holds a plan" branch, so the test sees exactly what checkout sees.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\AccountRequirement;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\AccountRequirement
 */
final class AccountRequirementTest extends LiteIntegrationTestCase {

	/**
	 * Load the cart and start each test empty.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( did_action( 'wp_loaded' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	/**
	 * Clear cart and POST state between tests.
	 */
	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Add an applicable product to the cart, optionally on a plan.
	 *
	 * @param int|null $plan_id Plan id to post, or null for a one-time add.
	 */
	private function add_product_to_cart( ?int $plan_id ): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( '20.00' );
		$product_id = (int) $product->save();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		if ( null === $plan_id ) {
			unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		} else {
			$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $plan_id;
		}

		WC()->cart->add_to_cart( $product_id );
	}

	public function test_requires_an_account_for_a_guest_with_a_plan_in_the_cart(): void {
		wp_set_current_user( 0 );
		$this->add_product_to_cart( (int) $this->make_plan()->get_id() );

		$this->assertTrue( ( new AccountRequirement() )->require_account_for_subscriptions( false ) );
	}

	public function test_leaves_the_setting_unchanged_for_a_logged_in_customer(): void {
		wp_set_current_user( $this->create_customer() );
		$this->add_product_to_cart( (int) $this->make_plan()->get_id() );

		$this->assertFalse( ( new AccountRequirement() )->require_account_for_subscriptions( false ) );
	}

	public function test_leaves_the_setting_unchanged_for_a_guest_without_a_plan(): void {
		wp_set_current_user( 0 );
		$this->make_plan();
		$this->add_product_to_cart( null ); // One-time add.

		$this->assertFalse( ( new AccountRequirement() )->require_account_for_subscriptions( false ) );
	}

	public function test_preserves_an_already_required_registration(): void {
		wp_set_current_user( 0 ); // Guest, empty cart - not our concern.

		$this->assertTrue( ( new AccountRequirement() )->require_account_for_subscriptions( true ) );
	}

	public function test_the_bootstrap_bound_filter_forces_registration_for_a_guest_plan_cart(): void {
		wp_set_current_user( 0 );
		$this->add_product_to_cart( (int) $this->make_plan()->get_id() );

		// Drive the real WooCommerce filter, proving the class is wired end to end.
		$this->assertTrue( apply_filters( 'woocommerce_checkout_registration_required', false ) );
	}
}
