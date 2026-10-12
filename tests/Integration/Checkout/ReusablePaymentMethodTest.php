<?php
/**
 * Integration tests for ReusablePaymentMethod.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ReusablePaymentMethod;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ReusablePaymentMethod
 */
final class ReusablePaymentMethodTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( did_action( 'wp_loaded' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		ReusablePaymentMethod::register();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		parent::tear_down();
	}

	/**
	 * A saved simple product.
	 *
	 * @param string $price Regular price.
	 */
	private function make_product( string $price = '20.00' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( $price );

		return (int) $product->save();
	}

	/**
	 * Add a product to the cart, on a plan or one-time.
	 *
	 * @param int  $product_id Product.
	 * @param bool $on_plan    Whether to pick a monthly plan.
	 */
	private function add_to_cart( int $product_id, bool $on_plan ): void {
		if ( $on_plan ) {
			( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
			$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $this->make_plan()->get_id();
		} else {
			unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		}
		WC()->cart->add_to_cart( $product_id );
		WC()->cart->calculate_totals();
	}

	/**
	 * An order with one line, optionally stamped with a plan.
	 *
	 * @param bool   $on_plan Whether the line carries a plan.
	 * @param string $price   Product price.
	 * @param string $status  Order status.
	 */
	private function make_order( bool $on_plan, string $price = '19.99', string $status = 'pending' ): WC_Order {
		$order = $this->create_subscription_order( $this->create_customer(), [ 'price' => $price ] );
		if ( $on_plan ) {
			$this->stamp_plan( $order, $this->make_plan() );
		}
		$order->set_date_paid( null );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	public function test_a_plan_cart_requires_a_reusable_method(): void {
		$this->add_to_cart( $this->make_product(), true );

		$this->assertTrue( Payments::requires_reusable_payment_method() );
	}

	public function test_a_plain_cart_does_not(): void {
		$this->add_to_cart( $this->make_product(), false );

		$this->assertFalse( Payments::requires_reusable_payment_method() );
	}

	public function test_a_plan_order_requires_a_reusable_method_and_a_plain_order_does_not(): void {
		$this->assertTrue( Payments::requires_reusable_payment_method( $this->make_order( true ) ) );
		$this->assertFalse( Payments::requires_reusable_payment_method( $this->make_order( false ) ) );
	}

	public function test_a_renewal_order_requires_a_reusable_method(): void {
		$order = wc_create_order( [ 'created_via' => RenewalOrderBuilder::CREATED_VIA ] );

		$this->assertTrue( Payments::requires_reusable_payment_method( $order ) );
	}

	public function test_a_free_plan_order_awaiting_payment_needs_payment(): void {
		$this->assertTrue( $this->make_order( true, '0' )->needs_payment() );
	}

	public function test_a_free_plan_order_already_completed_does_not_need_payment(): void {
		$this->assertFalse( $this->make_order( true, '0', 'completed' )->needs_payment() );
	}

	public function test_a_free_order_without_a_plan_is_untouched(): void {
		$this->assertFalse( $this->make_order( false, '0' )->needs_payment() );
	}

	public function test_a_paid_order_keeps_core_s_answer(): void {
		$this->assertTrue( $this->make_order( true )->needs_payment() );
		$this->assertTrue( $this->make_order( false )->needs_payment() );
	}

	public function test_a_free_renewal_order_does_not_need_payment(): void {
		$order = wc_create_order( [ 'created_via' => RenewalOrderBuilder::CREATED_VIA ] );
		$order->set_total( '0' );
		$order->save();

		$this->assertFalse( $order->needs_payment() );
	}

	public function test_a_free_plan_cart_needs_payment_and_a_free_plain_cart_does_not(): void {
		$this->add_to_cart( $this->make_product( '0' ), true );
		$this->assertTrue( WC()->cart->needs_payment() );

		WC()->cart->empty_cart();
		$this->add_to_cart( $this->make_product( '0' ), false );
		$this->assertFalse( WC()->cart->needs_payment() );
	}
}
