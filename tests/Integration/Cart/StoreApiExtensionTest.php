<?php
/**
 * Integration tests for the Blocks Store API endpoint data.
 *
 * Per-item payloads are built from a real persisted plan; the cart-level flag
 * is read off the real WooCommerce cart after a real add-to-cart.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Cart;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\PricingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Cart\Blocks\StoreApiExtension;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Cart\Blocks\StoreApiExtension
 */
final class StoreApiExtensionTest extends LiteIntegrationTestCase {

	/**
	 * Start each test from an initialized, empty cart.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( did_action( 'wp_loaded' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	/**
	 * Empty the cart between tests.
	 */
	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST[ CartPlanHooks::CART_ITEM_KEY ] );
		parent::tear_down();
	}

	public function test_per_item_payload_exposes_structured_fields(): void {
		$plan = $this->make_plan(
			'month',
			1,
			null,
			[
				'name'           => 'Coffee Club',
				'pricing_policy' => new PricingPolicy(
					[
						[
							'type'  => 'percentage',
							'value' => 10.0,
						],
					],
					[]
				),
			]
		);

		$product = new WC_Product_Simple();
		$product->set_regular_price( '20.00' );

		$data = ( new StoreApiExtension() )->get_item_data(
			[
				CartPlanHooks::CART_ITEM_KEY => (int) $plan->get_id(),
				'data'                       => $product,
			]
		);

		$this->assertSame( (int) $plan->get_id(), $data['plan_id'] );
		$this->assertSame( 'Monthly', $data['plan_label'] );
		$this->assertSame( '/ month', $data['price_cadence'] );
		$this->assertSame( 'month', $data['billing_period'] );
		$this->assertSame( 1, $data['billing_interval'] );
		$this->assertSame( 18.0, (float) $data['recurring_amount'] );
	}

	public function test_per_item_payload_is_empty_for_a_one_time_line(): void {
		$data = ( new StoreApiExtension() )->get_item_data( [ 'data' => new WC_Product_Simple() ] );

		$this->assertSame( [], $data );
	}

	public function test_cart_flag_is_true_when_a_subscription_is_present(): void {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '20.00' );
		$product_id = (int) $product->save();
		$plan_id    = (int) $this->make_plan()->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$_POST[ CartPlanHooks::CART_ITEM_KEY ] = (string) $plan_id;
		WC()->cart->add_to_cart( $product_id );

		$this->assertTrue( ( new StoreApiExtension() )->get_cart_data()['has_subscriptions'] );
	}

	public function test_cart_flag_is_false_for_a_one_time_cart(): void {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '20.00' );
		$product_id = (int) $product->save();

		WC()->cart->add_to_cart( $product_id );

		$this->assertFalse( ( new StoreApiExtension() )->get_cart_data()['has_subscriptions'] );
	}

	public function test_cart_flag_is_false_when_the_plan_no_longer_resolves(): void {
		// A plan line whose plan can no longer be found is priced as one-time,
		// so the flag must agree - resolution, not the raw meta, drives it.
		// Delete the plan after add-to-cart to drive the real find()-returns-null
		// path rather than a stubbed finder.
		$product = new WC_Product_Simple();
		$product->set_regular_price( '20.00' );
		$product_id = (int) $product->save();
		$plan_id    = (int) $this->make_plan()->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$_POST[ CartPlanHooks::CART_ITEM_KEY ] = (string) $plan_id;
		WC()->cart->add_to_cart( $product_id );
		( new PlanRepository() )->delete( $plan_id, Package::EXTENSION_SLUG );

		$this->assertFalse( ( new StoreApiExtension() )->get_cart_data()['has_subscriptions'] );
	}
}
