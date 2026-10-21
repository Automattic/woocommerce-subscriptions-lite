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

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\ScheduledActions;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DomainException;
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
	 * @param PlanView $plan  The plan to stamp.
	 */
	private function apply_and_stamp( WC_Order $order, PlanView $plan ): void {
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
	 * @param WC_Order      $order The order.
	 * @param string        $name  Product name.
	 * @param PlanView|null $plan  Plan to stamp, or null for a one-time line.
	 */
	private function add_line( WC_Order $order, string $name, ?PlanView $plan ): void {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '5.00' );
		$product->save();

		$item_id = $order->add_product( $product, 1 );

		if ( $plan instanceof PlanView ) {
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

		$contracts = Contracts::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'One contract for a single-plan order.' );

		$contract = Contracts::get_for_customer( (int) $contracts[0]->get_id(), $customer_id );
		$this->assertInstanceOf( ContractView::class, $contract );
		$this->assertSame( 'active', $contract->get_status() );
		$this->assertSame( Package::EXTENSION_SLUG, $contract->get_extension_slug() );
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

		$history = Contracts::get_cycles( $contract->get_id() );
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

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertSame( '24.00000000', $contracts[0]->get_billing_total(), 'Plan line + shipping; the one-time fee is not billed again.' );
		$this->assertSame( '4.00000000', $contracts[0]->get_shipping_total() );
		$this->assertSame( '24.00000000', Contracts::get_cycles( $contracts[0]->get_id() )[0]->get_expected_total() );
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

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertSame( 'draft', $contracts[0]->get_status() );
		$this->assertNull( $contracts[0]->get_lock_state(), 'The stuck draft is not left locked.' );
		$this->assertNull( $contracts[0]->get_next_payment_gmt(), 'A draft is never armed.' );
		$this->assertSame( [], Contracts::get_cycles( $contracts[0]->get_id() ) );

		$notes       = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' );
		$draft_notes = array_filter(
			$notes,
			static fn( $note ) => 1 === preg_match( '/#' . $contracts[0]->get_id() . '\b/', (string) $note )
		);
		$this->assertCount( 1, $draft_notes, 'One order note names the draft contract.' );
	}

	public function test_two_lines_on_the_same_plan_make_one_multi_line_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id, [ 'product_name' => 'First Box' ] );
		$this->add_line( $order, 'Second Box', $plan );  // Same plan, second product.
		$this->apply_and_stamp( $order, $plan );          // Stamp + apply to both lines.

		$order->update_status( 'processing' );

		$contracts = Contracts::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'Same-plan lines consolidate into one contract.' );

		$contract = Contracts::get_for_customer( (int) $contracts[0]->get_id(), $customer_id );
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
		$this->assertSame( [], Contracts::list_for_customer( $customer_id ), 'An on-hold offline order has no contract yet.' );

		$order->update_status( 'processing' ); // Merchant confirms the payment.
		$this->assertCount( 1, Contracts::list_for_customer( $customer_id ), 'Confirming the offline payment creates the contract.' );
	}

	public function test_two_different_plans_defer_without_a_contract(): void {
		$customer_id = $this->create_customer();
		$monthly     = $this->make_plan( 'month' );
		$weekly      = $this->make_plan( 'week' );
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $monthly );       // First line -> monthly.
		$this->add_line( $order, 'Weekly Box', $weekly ); // Second line -> weekly.

		$order->update_status( 'processing' );

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ), 'No contract for divergent plans.' );
		$this->assertSame( ContractCreationHandler::REASON_DIVERGENT_PLANS, $this->deferral_reason( $order ) );
	}

	public function test_a_one_time_line_alongside_a_plan_defers_without_a_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );          // First line -> the plan.
		$this->add_line( $order, 'One-time Mug', null );  // Second line -> one-time.

		$order->update_status( 'processing' );

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ), 'No contract for a mixed cart.' );
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

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ) );
		$this->assertSame( ContractCreationHandler::REASON_DIVERGENT_PLANS, $this->deferral_reason( $order ) );
	}

	public function test_is_idempotent_across_repeated_paid_transitions(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( 'processing' ); // Creates the contract.
		$order->update_status( 'completed' );  // Fires again - must be a no-op.

		$this->assertCount( 1, Contracts::list_for_customer( $customer_id ), 'A second paid transition creates no second contract.' );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_subscription_contract_id' ), 'The guard reads the contract by origin order, not order meta.' );
	}

	public function test_a_paid_renewal_order_with_plan_lines_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $this->make_plan() );
		$order->set_created_via( RenewalOrderBuilder::CREATED_VIA );
		$order->save();

		$order->update_status( 'processing' );

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ) );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	public function test_an_order_without_plan_items_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id ); // No plan meta.

		$order->update_status( 'processing' );

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ) );
	}

	public function test_a_stamped_plan_creates_the_contract_without_an_applicability_recheck(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		// Stamp the plan but leave the product's mode at 'disable': the cart priced the
		// line on the stamped plan, so checkout trusts the stamp like the cart does.
		foreach ( $order->get_items() as $item ) {
			$item->add_meta_data( self::SELLING_PLAN_META, (string) $plan->get_id(), true );
			$item->save();
		}
		$order->save();

		$order->update_status( 'processing' );

		$contracts = Contracts::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts );
		$this->assertSame( (int) $plan->get_id(), $contracts[0]->get_selling_plan_id() );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	/**
	 * A plan selected under 'inherit_select' at add-to-cart, then detached from the
	 * product before the payment lands, still becomes the contract. Archiving has its
	 * own tests below.
	 */
	public function test_an_inherit_select_plan_detached_after_add_to_cart_still_creates_the_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$other       = $this->make_plan( 'week' );
		$order       = $this->create_subscription_order( $customer_id );
		$store       = new ApplicabilityStore();
		foreach ( $order->get_items() as $item ) {
			$store->set( $item->get_product_id(), new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $plan->get_id(), $other->get_id() ] ) );
			$item->add_meta_data( self::SELLING_PLAN_META, (string) $plan->get_id(), true );
			$item->save();
		}
		$order->save();
		$order->update_status( 'on-hold' ); // Awaiting the offline payment.

		foreach ( $order->get_items() as $item ) {
			$store->set( $item->get_product_id(), new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $other->get_id() ] ) );
		}

		$order->update_status( 'processing' );

		$contracts = Contracts::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'The line was charged the plan price, so its contract is created.' );
		$this->assertSame( (int) $plan->get_id(), $contracts[0]->get_selling_plan_id() );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	public function test_a_deleted_plan_defers_without_a_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		( new PlanRepository() )->delete( $plan->get_id(), Package::EXTENSION_SLUG );
		$order->update_status( 'processing' );

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ) );
		$this->assertSame( ContractCreationHandler::REASON_PLAN_UNAVAILABLE, $this->deferral_reason( $order ) );
	}

	/**
	 * The order classification checks plan-unavailable before divergent plans and mixed carts:
	 * a line whose plan is gone defers the whole order even beside a billable line.
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $with_one_time_line Whether the order also has a one-time line.
	 */
	public function test_a_deleted_plan_beside_a_billable_line_defers_as_plan_unavailable( bool $with_one_time_line ): void {
		$customer_id = $this->create_customer();
		$monthly     = $this->make_plan( 'month' );
		$doomed      = $this->make_plan( 'week' );
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $monthly );
		$this->add_line( $order, 'Weekly Box', $doomed );
		if ( $with_one_time_line ) {
			$this->add_line( $order, 'One-time Mug', null );
		}

		( new PlanRepository() )->delete( $doomed->get_id(), Package::EXTENSION_SLUG );
		$order->update_status( 'processing' );

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ), 'No contract for the remaining billable line.' );
		$this->assertSame( ContractCreationHandler::REASON_PLAN_UNAVAILABLE, $this->deferral_reason( $order ) );
	}

	public function test_a_plan_archived_after_add_to_cart_still_creates_the_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$this->assertInstanceOf(
			PlanView::class,
			Plans::update(
				$plan->get_id(),
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => PlanStatus::ARCHIVED,
				]
			)
		);
		$order->update_status( 'processing' );

		$contracts = Contracts::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'The cart priced the line on the plan, so checkout honours it.' );
		$this->assertSame( (int) $plan->get_id(), $contracts[0]->get_selling_plan_id() );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	public function test_an_offline_order_confirmed_after_the_plan_is_archived_creates_the_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$order->update_status( 'on-hold' ); // Awaiting the offline payment.

		$this->assertInstanceOf(
			PlanView::class,
			Plans::update(
				$plan->get_id(),
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => PlanStatus::ARCHIVED,
				]
			)
		);
		$order->update_status( 'processing' ); // The merchant confirms the payment.

		$contracts = Contracts::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts );
		$this->assertSame( 'active', $contracts[0]->get_status() );
	}

	public function test_an_order_that_never_reaches_a_paid_status_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( 'on-hold' ); // Not a paid status.

		$this->assertSame( [], Contracts::list_for_customer( $customer_id ), 'An unpaid order creates no contract.' );
	}

	public function test_the_handler_is_bound_to_the_paid_status_transition(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_changed' ),
			'Contract creation is bound to the order reaching a paid status.'
		);
	}

	public function test_the_contract_carries_the_order_creation_key_and_is_left_unlocked(): void {
		global $wpdb;

		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( OrderStatus::PROCESSING );

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertNull( $contracts[0]->get_lock_state() );
		$table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$key = $wpdb->get_var( $wpdb->prepare( "SELECT creation_key FROM {$table} WHERE id = %d", $contracts[0]->get_id() ) );
		$this->assertSame( 'order:' . $order->get_id(), $key );
	}

	public function test_a_second_creation_for_the_same_order_is_a_duplicate(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$first = ( new ContractCreationHandler() )->create_contract( $order, $plan );
		$this->assertInstanceOf( ContractView::class, $first );

		$this->assertNull( ( new ContractCreationHandler() )->create_contract( $order, $plan ), 'The creation key is taken.' );

		$this->assertCount( 1, Contracts::list_for_customer( $customer_id ) );
	}

	public function test_create_contract_refuses_a_plan_without_billing_terms(): void {
		$plan  = $this->make_unvalidated_plan(
			'month',
			1,
			null,
			[
				'billing_policy' => [
					'period'   => 'month',
					'interval' => '1',
				],
			]
		);
		$order = $this->create_subscription_order( $this->create_customer() );
		$this->apply_and_stamp( $order, $plan );

		try {
			( new ContractCreationHandler() )->create_contract( $order, $plan );
			$this->fail( 'A plan without billing terms must be refused.' );
		} catch ( DomainException $e ) {
			$this->assertSame( DomainException::class, get_class( $e ) );
		}

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
	}

	/**
	 * Pending resume actions for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array<int, int> Action ids.
	 */
	private function pending_resumes( int $order_id ): array {
		return array_keys( $this->get_pending_actions( 'woocommerce_subscriptions_lite_resume_checkout', [ 'order_id' => $order_id ] ) );
	}

	/**
	 * A creation that died right after the locked insert leaves a locked draft: a paid signal
	 * meanwhile is refused and schedules a resume; once the lock expires, the next paid status
	 * change resumes it to active with cycle 1.
	 */
	public function test_a_draft_left_by_a_creation_cut_short_is_resumed_once_its_lock_expires(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$created  = Contracts::create(
			[
				'extension_slug'  => Package::EXTENSION_SLUG,
				'creation_key'    => 'order:' . $order->get_id(),
				'status'          => ContractStatus::DRAFT,
				'customer_id'     => $customer_id,
				'currency'        => 'USD',
				'selling_plan_id' => $plan->get_id(),
				'origin_order_id' => $order->get_id(),
				'billing_total'   => '19.99',
			],
			[ 'operation' => ContractCreationHandler::OPERATION ]
		);
		$draft_id = $created['contract']->get_id();

		$order->update_status( OrderStatus::PROCESSING );
		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$draft = $this->get_contract( $draft_id );
		$this->assertSame( ContractStatus::DRAFT, $draft->get_status(), 'Refused while the creation may still run.' );
		$this->assertSame( ContractCreationHandler::OPERATION, $draft->get_lock_state()['operation'] ?? null );
		$this->assertSame( [], Contracts::get_cycles( $draft_id ) );
		$this->assertCount( 1, $this->pending_resumes( $order->get_id() ), 'Resumed later, once.' );

		$this->expire_held_locks();
		$order->update_status( OrderStatus::COMPLETED );

		$contract = $this->get_contract( $draft_id );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-02-15 00:00:00', $contract->get_next_payment_gmt() );
		$cycles = Contracts::get_cycles( $draft_id );
		$this->assertCount( 1, $cycles );
		$this->assertSame( CycleStatus::BILLED, $cycles[0]->get_status() );
		$this->assertSame( $order->get_id(), $cycles[0]->get_order_id() );
		$this->assertCount( 1, Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'activated after its creation was interrupted' ) );
		$this->assertSame( [], $this->pending_resumes( $order->get_id() ), 'The resume is dropped once active.' );
	}

	/**
	 * A creation that died after cycle 1 is activated by the resume action scheduled before
	 * the draft was written, without a second cycle.
	 */
	public function test_the_resume_action_activates_a_draft_that_has_cycle_one(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$contracts = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$die       = static function ( string $query ) use ( $contracts ): string {
			return 0 === strpos( $query, "UPDATE `{$contracts}`" ) && false !== strpos( $query, "'" . ContractStatus::ACTIVE . "'" ) ? '' : $query;
		};
		add_filter( 'query', $die );
		try {
			$order->update_status( OrderStatus::PROCESSING );
		} finally {
			remove_filter( 'query', $die );
		}

		$drafts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $drafts );
		$this->assertSame( ContractStatus::DRAFT, $drafts[0]->get_status() );
		$this->assertCount( 1, Contracts::get_cycles( $drafts[0]->get_id() ), 'Cut short after cycle 1.' );
		$this->assertCount( 1, $this->pending_resumes( $order->get_id() ), 'Scheduled before the draft was written.' );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$contract = $this->get_contract( $drafts[0]->get_id() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-02-15 00:00:00', $contract->get_next_payment_gmt() );
		$this->assertNull( $contract->get_lock_state() );
		$this->assertCount( 1, Contracts::get_cycles( $drafts[0]->get_id() ), 'No second cycle.' );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'activated after its creation was interrupted' ) );
	}

	public function test_a_successful_creation_leaves_no_resume_action_and_the_action_is_a_no_op(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$order->update_status( OrderStatus::PROCESSING );
		$this->assertSame( [], $this->pending_resumes( $order->get_id() ) );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertCount( 1, Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order->get_id(), 'activated after its creation was interrupted' ) );
	}

	/**
	 * A creation that keeps failing before the draft is written is resumed at most three times
	 * (the attempt cap), then the chain stops with one final note; a later paid status change
	 * still creates the contract and clears the count.
	 */
	public function test_a_persistently_failing_resume_stops_after_the_cap_and_a_later_success_still_works(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$contracts = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$fail      = static function ( string $query ) use ( $contracts ): string {
			return 0 === strpos( $query, "INSERT INTO `{$contracts}`" ) ? '' : $query;
		};
		$final     = 'still could not be created after several automatic attempts';
		add_filter( 'query', $fail );
		try {
			$order->update_status( OrderStatus::PROCESSING );
			$this->assertCount( 1, $this->pending_resumes( $order->get_id() ) );

			for ( $run = 1; $run < 3; $run++ ) {
				do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
				$this->assertCount( 1, $this->pending_resumes( $order->get_id() ), "Rescheduled after failed run {$run}." );
			}
			do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
			$this->assertSame( [], $this->pending_resumes( $order->get_id() ), 'Not rescheduled past the cap.' );
			$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), $final ) );

			do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() ); // A stray run past the cap.
			$this->assertSame( [], $this->pending_resumes( $order->get_id() ) );
			$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), $final ), 'One final note only.' );
		} finally {
			remove_filter( 'query', $fail );
		}
		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );

		$order->update_status( OrderStatus::COMPLETED );

		$created = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $created );
		$this->assertSame( ContractStatus::ACTIVE, $created[0]->get_status() );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ), 'A success clears the count.' );
		$this->assertSame( [], $this->pending_resumes( $order->get_id() ) );
	}

	public function test_a_failed_resume_run_followed_by_a_successful_one_clears_the_count(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );

		$contracts = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$fail      = static function ( string $query ) use ( $contracts ): string {
			return 0 === strpos( $query, "INSERT INTO `{$contracts}`" ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		try {
			$order->update_status( OrderStatus::PROCESSING );
			do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
		} finally {
			remove_filter( 'query', $fail );
		}
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ) );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$created = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $created );
		$this->assertSame( ContractStatus::ACTIVE, $created[0]->get_status() );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ) );
		$this->assertSame( [], $this->pending_resumes( $order->get_id() ) );
	}

	/**
	 * A draft whose activation keeps failing is resumed again after each failed run (the run
	 * that fired is consumed, as Action Scheduler would), until the cap stops it with a note.
	 */
	public function test_a_draft_whose_activation_keeps_failing_is_rescheduled_until_the_cap(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$args = [ 'order_id' => $order->get_id() ];

		$contracts = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$fail      = static function ( string $query ) use ( $contracts ): string {
			return 0 === strpos( $query, "UPDATE `{$contracts}`" ) && false !== strpos( $query, "'" . ContractStatus::ACTIVE . "'" ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		try {
			$order->update_status( OrderStatus::PROCESSING );
			$drafts = Contracts::find_by_origin_order( $order->get_id() );
			$this->assertSame( ContractStatus::DRAFT, $drafts[0]->get_status(), 'Cut short at activation.' );

			for ( $run = 1; $run < 3; $run++ ) {
				ScheduledActions::unschedule( 'woocommerce_subscriptions_lite_resume_checkout', $args );
				do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
				$this->assertCount( 1, $this->pending_resumes( $order->get_id() ), "Rescheduled after failed run {$run}." );
			}
			ScheduledActions::unschedule( 'woocommerce_subscriptions_lite_resume_checkout', $args );
			do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
		} finally {
			remove_filter( 'query', $fail );
		}

		$this->assertSame( [], $this->pending_resumes( $order->get_id() ), 'Not rescheduled past the cap.' );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'still could not be created after several automatic attempts' ) );
		$this->assertSame( ContractStatus::DRAFT, $this->get_contract( $drafts[0]->get_id() )->get_status() );
	}

	public function test_the_resume_leaves_a_cancelled_draft_alone(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$created  = Contracts::create(
			[
				'extension_slug'  => Package::EXTENSION_SLUG,
				'creation_key'    => 'order:' . $order->get_id(),
				'status'          => ContractStatus::DRAFT,
				'customer_id'     => $customer_id,
				'currency'        => 'USD',
				'selling_plan_id' => $plan->get_id(),
				'origin_order_id' => $order->get_id(),
				'billing_total'   => '19.99',
			],
			[ 'operation' => ContractCreationHandler::OPERATION ]
		);
		$draft_id = $created['contract']->get_id();
		Contracts::update( $draft_id, [ 'status' => ContractStatus::CANCELLED ], $created['lock'] );
		$created['lock']->release();

		$order->update_status( OrderStatus::PROCESSING );
		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $draft_id )->get_status() );
		$this->assertSame( [], Contracts::get_cycles( $draft_id ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order->get_id(), 'activated after its creation was interrupted' ) );
		$this->assertSame( [], $this->pending_resumes( $order->get_id() ) );
		$this->assertCount( 1, Contracts::find_by_origin_order( $order->get_id() ), 'No second contract.' );
	}

	/**
	 * Leave a Lite draft for a paid order whose activation was cut short.
	 *
	 * @param WC_Order $order The order, stamped with its plan.
	 * @return int The draft id.
	 */
	private function leave_draft( WC_Order $order ): int {
		$contracts = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$fail      = static function ( string $query ) use ( $contracts ): string {
			return 0 === strpos( $query, "UPDATE `{$contracts}`" ) && false !== strpos( $query, "'" . ContractStatus::ACTIVE . "'" ) ? '' : $query;
		};
		add_filter( 'query', $fail );
		try {
			$order->update_status( OrderStatus::PROCESSING );
		} finally {
			remove_filter( 'query', $fail );
		}
		$drafts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertSame( ContractStatus::DRAFT, $drafts[0]->get_status() );

		return $drafts[0]->get_id();
	}

	public function test_a_draft_whose_plan_no_longer_resolves_is_a_failed_resume_until_the_cap(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$draft_id = $this->leave_draft( $order );
		( new PlanRepository() )->delete( $plan->get_id(), Package::EXTENSION_SLUG );
		$this->capture_log();
		// Only the run's own reschedule may satisfy the pending check below.
		ScheduledActions::unschedule( 'woocommerce_subscriptions_lite_resume_checkout', [ 'order_id' => $order->get_id() ] );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertSame( ContractStatus::DRAFT, $this->get_contract( $draft_id )->get_status() );
		$this->assertTrue( $this->has_logged( 'cannot be resumed: its plan no longer resolves' ) );
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ), 'Counted as a failed run.' );
		$this->assertCount( 1, $this->pending_resumes( $order->get_id() ), 'Resumed again later.' );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertSame( [], $this->pending_resumes( $order->get_id() ), 'Not rescheduled past the cap.' );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'still could not be created after several automatic attempts' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order->get_id(), 'activated after its creation was interrupted' ) );
	}

	public function test_a_draft_is_resumed_with_its_own_plan_when_the_order_no_longer_resolves_to_one(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$draft_id = $this->leave_draft( $order );
		$plain    = new WC_Product_Simple();
		$plain->set_regular_price( '5' );
		$plain->save();
		$order->add_product( $plain, 1 );
		$order->save();

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $draft_id )->get_status(), 'A mixed cart now, yet the draft resumes.' );
		$this->assertSame( $plan->get_id(), $this->get_contract( $draft_id )->get_selling_plan_id() );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'activated after its creation was interrupted' ) );
	}

	/**
	 * A resume whose activation write is refused because another operation took the draft's
	 * lock meanwhile (forced by a query filter that hands the lock row to a `renewal` holder
	 * just before the write) is not a failed run: the refusal is logged and the resume rescheduled.
	 */
	public function test_a_resume_refused_mid_flow_is_rescheduled_without_counting_a_failure(): void {
		global $wpdb;

		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->apply_and_stamp( $order, $plan );
		$draft_id = $this->leave_draft( $order );
		ScheduledActions::unschedule( 'woocommerce_subscriptions_lite_resume_checkout', [ 'order_id' => $order->get_id() ] );
		$contracts = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$taken     = 0;
		$take_lock = static function ( string $query ) use ( $contracts, $draft_id, &$taken, $wpdb ): string {
			if ( 0 !== $taken || 1 !== preg_match( "/^UPDATE `?{$contracts}`? /", $query ) || false === strpos( $query, "'" . ContractStatus::ACTIVE . "'" ) ) {
				return $query;
			}
			++$taken;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$contracts} SET lock_token = %s, lock_operation = 'renewal', locked_until_gmt = DATE_ADD( UTC_TIMESTAMP(), INTERVAL 10 MINUTE ) WHERE id = %d", str_repeat( 'f', 32 ), $draft_id ) );

			return $query;
		};
		$this->capture_log();
		add_filter( 'query', $take_lock );
		try {
			do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
		} finally {
			remove_filter( 'query', $take_lock );
		}

		$this->assertSame( 1, $taken, 'The filter handed the lock over.' );
		$this->assertSame( ContractStatus::DRAFT, $this->get_contract( $draft_id )->get_status() );
		$this->assertTrue( $this->has_logged( sprintf( 'a write to contract %d was refused mid-flow; another operation (renewal)', $draft_id ) ) );
		$this->assertCount( 1, $this->pending_resumes( $order->get_id() ), 'Resumed again later.' );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ), 'Not counted as a failed run.' );
	}
}
