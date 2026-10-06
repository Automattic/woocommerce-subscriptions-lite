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
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Shipping;
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
		$this->assertInstanceOf( ContractView::class, $contract );
		$this->assertSame( 'active', $contract->get_status() );
		$this->assertSame( Package::EXTENSION_SLUG, $contract->get_owner() );
		$this->assertSame( $customer_id, $contract->get_customer_id() );
		$this->assertSame( 'USD', $contract->get_currency() );
		$this->assertSame( (int) $plan->get_id(), $contract->get_selling_plan_id() );
		$this->assertSame( $order->get_id(), $contract->get_origin_order_id() );
		$this->assertSame( 'dummy', $contract->get_payment_method() );
		$this->assertSame( 'Dummy Payments', $contract->get_payment_method_title() );
		$this->assertSame( '2026-01-15 00:00:00', $contract->get_start_gmt() );
		$this->assertSame( '2026-02-15 00:00:00', $contract->get_next_payment_gmt() );
		$this->assertSame( '19.99000000', $contract->get_billing_total() );

		$items = (array) $contract->get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( 'Checkout Box', $items[0]['item_name'] );
		$this->assertSame( 'line_item', $items[0]['item_type'] );

		$addresses = (array) $contract->get_addresses();
		$this->assertSame( '12 Analytical Row', $addresses['billing']['address_1'] );
		$this->assertSame( 'ada@example.com', $addresses['billing']['email'] );
		$this->assertSame( '1 Engine Court', $addresses['shipping']['address_1'] );

		$snapshot = (array) $contract->get_plan_snapshot();
		$this->assertSame( (int) $plan->get_id(), $snapshot['selling_plan_id'] );
		$this->assertSame( $plan->get_name(), $snapshot['name'] );
		$this->assertSame( 'month', $snapshot['billing_policy']['period'] );
		$this->assertArrayHasKey( 'pricing_policy', $snapshot );
		$this->assertArrayNotHasKey( 'category', $snapshot );

		$history = Subscriptions::get_history( $contract->get_id() );
		$this->assertCount( 1, $history );
		$this->assertSame( 'billed', $history[0]->get_status() );
		$this->assertSame( 1, $history[0]->get_count() );
		$this->assertSame( $order->get_id(), $history[0]->get_order_id() );
		$this->assertSame( '19.99000000', $history[0]->get_expected_total() );
		$this->assertSame( '2026-01-15 00:00:00', $history[0]->get_starts_at_gmt() );
		$this->assertSame( '2026-02-15 00:00:00', $history[0]->get_ends_at_gmt() );
	}

	public function test_billing_total_excludes_a_fee_line(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id, [ 'price' => '20.00' ] );

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Setup fee' );
		$fee->set_total( '5.00' );
		$order->add_item( $fee );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_total( '4.00' );
		$order->add_item( $shipping );
		$order->calculate_totals();
		$order->save();
		$this->apply_and_stamp( $order, $plan );
		$this->assertSame( '29.00', $order->get_total() );

		$order->update_status( 'processing' );

		$contracts = Subscriptions::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertSame( '24.00000000', $contracts[0]->get_billing_total(), 'Plan line + shipping; the one-time fee is not billed again.' );
		$this->assertSame( '4.00000000', $contracts[0]->get_shipping_total() );
		$this->assertSame( '24.00000000', Subscriptions::get_history( $contracts[0]->get_id() )[0]->get_expected_total() );
	}

	public function test_a_failed_cycle_write_leaves_a_draft(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$cycles = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CYCLES );
		$fail   = static function ( string $query ) use ( $cycles ): string {
			return 0 === strpos( $query, "INSERT INTO `{$cycles}`" ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		try {
			$order->update_status( 'processing' ); // Must not throw out of the hook.
		} finally {
			remove_filter( 'query', $fail );
		}

		$contracts = Subscriptions::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertSame( 'draft', $contracts[0]->get_status() );
		$this->assertNull( $contracts[0]->get_next_payment_gmt(), 'A draft is never armed.' );
		$this->assertSame( [], Subscriptions::get_history( $contracts[0]->get_id() ) );

		$notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' );
		$this->assertContains(
			sprintf( 'Subscription #%d was created as a draft but could not be activated. This order needs manual review.', $contracts[0]->get_id() ),
			$notes
		);
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
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_subscription_contract_id' ), 'The guard reads the contract by origin order, not order meta.' );
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
