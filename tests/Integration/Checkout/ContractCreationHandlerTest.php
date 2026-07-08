<?php
/**
 * Integration tests for the checkout contract-creation handler.
 *
 * These run END TO END against real WordPress/WooCommerce: the behavioural cases
 * drive the REAL trigger - an order reaching a paid status via `update_status()`
 * fires the bootstrap-bound handler, exactly as a gateway or a merchant confirming
 * an offline payment would.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class ContractCreationHandlerTest extends LiteIntegrationTestCase {

	private const SELLING_PLAN_META = '_wcsl_selling_plan_id';

	/**
	 * Make every product line of an order applicable to all Lite plans and stamp
	 * `$plan` on each - the production shape after add-to-cart.
	 *
	 * @param WC_Order $order The order.
	 * @param Plan     $plan  The plan to stamp.
	 */
	private function apply_and_stamp( WC_Order $order, Plan $plan ): void {
		foreach ( $order->get_items() as $item ) {
			( new ApplicabilityStore() )->set(
				$item->get_product_id(),
				new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL )
			);
			$item->add_meta_data( self::SELLING_PLAN_META, (string) $plan->get_id(), true );
			$item->save();
		}
		$order->save();
	}

	/**
	 * Add a product line to an order. With a plan it is made applicable + stamped;
	 * without one it is a plain (one-time) line.
	 *
	 * @param WC_Order  $order The order.
	 * @param string    $name  Product name.
	 * @param Plan|null $plan  Plan to stamp, or null for a one-time line.
	 */
	private function add_line( WC_Order $order, string $name, ?Plan $plan ): void {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '5.00' );
		$product->save();

		$item_id = $order->add_product( $product, 1 );

		if ( $plan instanceof Plan ) {
			( new ApplicabilityStore() )->set(
				$product->get_id(),
				new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL )
			);
			$item = $order->get_item( $item_id );
			$item->add_meta_data( self::SELLING_PLAN_META, (string) $plan->get_id(), true );
			$item->save();
		}

		$order->calculate_totals();
		$order->save();
	}

	/**
	 * The deferral reason recorded on an order, or '' when none.
	 *
	 * @param WC_Order $order The order.
	 */
	private function deferral_reason( WC_Order $order ): string {
		return (string) wc_get_order( $order->get_id() )->get_meta( ContractCreationHandler::CREATION_DEFERRED_META );
	}

	public function test_creates_one_active_contract_when_the_order_reaches_a_paid_status(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id, [ 'product_name' => 'Checkout Box' ] );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( 'processing' ); // Fires the bootstrap-bound handler.

		$contracts = Subscriptions::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'One contract for a single-plan order.' );

		$contract = Subscriptions::get_for_customer( (int) $contracts[0]->get_id(), $customer_id );
		$this->assertSame( 'active', $contract->get_status() );
		$this->assertSame( (int) $plan->get_id(), $contract->get_selling_plan_id() );
		$this->assertSame( $order->get_id(), $contract->get_origin_order_id() );
		$this->assertSame( 'Checkout Box', $contract->get_items()[0]['item_name'] );
	}

	public function test_two_lines_on_the_same_plan_make_one_multi_line_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id, [ 'product_name' => 'First Box' ] );
		$this->add_line( $order, 'Second Box', $plan );  // Same plan, second product.
		$this->apply_and_stamp( $order, $plan );          // Stamp + apply to both lines.

		$order->update_status( 'processing' );

		$contracts = Subscriptions::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'Same-plan lines consolidate into one contract.' );

		$contract = Subscriptions::get_for_customer( (int) $contracts[0]->get_id(), $customer_id );
		$this->assertEqualsCanonicalizing(
			[ 'First Box', 'Second Box' ],
			array_column( $contract->get_items(), 'item_name' ),
			'The one contract carries both distinct line items.'
		);
	}

	public function test_an_offline_order_creates_the_contract_when_payment_is_confirmed_later(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( 'on-hold' ); // BACS/cheque: awaiting the transfer.
		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ), 'An on-hold offline order has no contract yet.' );

		$order->update_status( 'processing' ); // Merchant confirms the payment.
		$this->assertCount( 1, Subscriptions::list_for_customer( $customer_id ), 'Confirming the offline payment creates the contract.' );
	}

	public function test_two_different_plans_defer_without_a_contract(): void {
		$customer_id = $this->create_customer();
		$monthly     = $this->make_plan( 'month' );
		$weekly      = $this->make_plan( 'week' );
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $monthly );       // First line -> monthly.
		$this->add_line( $order, 'Weekly Box', $weekly ); // Second line -> weekly.

		$order->update_status( 'processing' );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ), 'No contract for divergent plans.' );
		$this->assertSame( ContractCreationHandler::REASON_DIVERGENT_PLANS, $this->deferral_reason( $order ) );
	}

	public function test_a_one_time_line_alongside_a_plan_defers_without_a_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );          // First line -> the plan.
		$this->add_line( $order, 'One-time Mug', null );  // Second line -> one-time.

		$order->update_status( 'processing' );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ), 'No contract for a mixed cart.' );
		$this->assertSame( ContractCreationHandler::REASON_MIXED_CART, $this->deferral_reason( $order ) );
	}

	public function test_two_plans_plus_a_one_time_line_defer_as_divergent_plans(): void {
		// classify_order() checks divergent-plans before mixed-cart; this pins that
		// precedence - if it flipped, the reason below would change.
		$customer_id = $this->create_customer();
		$monthly     = $this->make_plan( 'month' );
		$weekly      = $this->make_plan( 'week' );
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $monthly );
		$this->add_line( $order, 'Weekly Box', $weekly );
		$this->add_line( $order, 'One-time Mug', null );

		$order->update_status( 'processing' );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ) );
		$this->assertSame( ContractCreationHandler::REASON_DIVERGENT_PLANS, $this->deferral_reason( $order ) );
	}

	public function test_is_idempotent_across_repeated_paid_transitions(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( 'processing' ); // Creates the contract.
		$order->update_status( 'completed' );  // Fires again - must be a no-op.

		$this->assertCount( 1, Subscriptions::list_for_customer( $customer_id ), 'A second paid transition creates no second contract.' );
	}

	public function test_an_order_without_plan_items_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id ); // No plan meta.

		$order->update_status( 'processing' );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ) );
	}

	public function test_a_plan_not_applicable_to_the_product_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		// Stamp the plan but never make the product applicable (mode stays 'disable').
		foreach ( $order->get_items() as $item ) {
			$item->add_meta_data( self::SELLING_PLAN_META, (string) $plan->get_id(), true );
			$item->save();
		}
		$order->save();

		$order->update_status( 'processing' );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ), 'A non-applicable plan is excluded.' );
	}

	public function test_an_order_that_never_reaches_a_paid_status_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( 'on-hold' ); // Not a paid status.

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ), 'An unpaid order creates no contract.' );
	}

	public function test_the_handler_is_bound_to_the_paid_status_transition(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_changed' ),
			'Contract creation is bound to the order reaching a paid status.'
		);
	}
}
