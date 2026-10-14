<?php
/**
 * Integration tests for PaymentRequirement.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\PaymentRequirement
 */
final class PaymentRequirementTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( did_action( 'wp_loaded' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
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

	/**
	 * A plan order brought to a $0 total by a credit (a negative fee), so its subscription
	 * still renews for the plan line.
	 *
	 * @param string $status Order status.
	 */
	private function make_credited_plan_order( string $status = 'pending' ): WC_Order {
		$order = $this->make_order( true, '19.99', $status );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Store credit' );
		$fee->set_total( '-19.99' );
		$order->add_item( $fee );
		$order->set_total( '0' );
		$order->save();

		return $order;
	}

	public function test_a_free_plan_order_that_renews_for_a_charge_needs_payment(): void {
		$this->assertTrue( $this->make_credited_plan_order()->needs_payment() );
	}

	public function test_a_free_plan_order_that_renews_for_free_does_not_need_payment(): void {
		$this->assertFalse( $this->make_order( true, '0' )->needs_payment() );
	}

	public function test_a_free_plan_order_already_completed_does_not_need_payment(): void {
		$this->assertFalse( $this->make_credited_plan_order( 'completed' )->needs_payment() );
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

	public function test_a_free_plan_cart_that_renews_for_a_charge_needs_payment(): void {
		add_action(
			'woocommerce_cart_calculate_fees',
			static function ( $cart ): void {
				$cart->add_fee( 'Store credit', -20 );
			}
		);
		$this->add_to_cart( $this->make_product(), true );

		$this->assertSame( 0.0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertTrue( WC()->cart->needs_payment() );
	}

	public function test_free_plan_and_plain_carts_do_not_need_payment(): void {
		$this->add_to_cart( $this->make_product( '0' ), true );
		$this->assertFalse( WC()->cart->needs_payment(), 'A subscription that renews for free collects no method.' );

		WC()->cart->empty_cart();
		$this->add_to_cart( $this->make_product( '0' ), false );
		$this->assertFalse( WC()->cart->needs_payment() );
	}
}
