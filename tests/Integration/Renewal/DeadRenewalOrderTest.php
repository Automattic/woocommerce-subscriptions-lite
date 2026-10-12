<?php
/**
 * Integration tests for renewal orders that die unpaid: cancelled or trashed by the customer
 * or the merchant.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\LifecycleNotAllowedException;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\Settlement
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalProcessor
 */
final class DeadRenewalOrderTest extends LiteIntegrationTestCase {

	/**
	 * The contract's newest cycle.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function head( int $contract_id ): CycleView {
		$cycles = Contracts::get_cycles( $contract_id, 1 );
		$this->assertCount( 1, $cycles );

		return $cycles[0];
	}

	/**
	 * A `cod` contract whose renewal order is in `$order_status`, billing a `$cycle_status` cycle.
	 *
	 * @param string $status       Contract status.
	 * @param string $order_status Renewal order status.
	 * @param string $cycle_status Cycle status.
	 * @return array{0: int, 1: int, 2: WC_Order} Contract id, customer id, renewal order.
	 */
	private function seed( string $status, string $order_status, string $cycle_status ): array {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'payment_method' => 'cod',
				'status'         => $status,
			]
		);
		$order       = $this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => $order_status,
				'cycle_status' => $cycle_status,
			]
		);
		if ( ContractStatus::ON_HOLD === $status ) {
			// Held for the renewal payment, not by the customer: no hold anchor.
			HoldAnchor::clear( $contract_id );
		}

		return [ $contract_id, $customer_id, $order ];
	}

	public function test_a_cancelled_failed_renewal_order_keeps_the_contract_on_hold_and_renew_now_replaces_it(): void {
		[ $contract_id, $customer_id, $order ] = $this->seed( ContractStatus::ON_HOLD, 'failed', CycleStatus::FAILED );

		$order->update_status( 'cancelled' );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$detail = ( new EngineDataProvider() )->get_contract( $contract_id, $customer_id );
		$this->assertNotNull( $detail );
		$this->assertTrue( $detail['has_unpaid_renewal'], 'The renewal is still owed.' );
		$this->assertSame( '', $detail['unpaid_order_pay_url'], 'A cancelled order offers no pay link.' );
		try {
			( new Reactivation() )->reactivate( $contract_id );
			$this->fail( 'A dead order must not reactivate the contract for free.' );
		} catch ( LifecycleNotAllowedException $e ) {
			$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		}

		$this->support_engine_charges( 'cod' );
		$result = ( new RowActionController( null, static fn (): bool => true ) )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $this->charge_count() );
		$this->assertNotSame( $order->get_id(), $this->charged_order_ids[0], 'A new order is built.' );
		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertSame( $this->charged_order_ids[0], $head->get_order_id(), 'The new order is linked to the cycle.' );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	/**
	 * Cancel the contract's failed renewal order, then let Renew now replace it with a charge
	 * that ends `$outcome`.
	 *
	 * @param string $outcome Stub charge outcome for the replacement.
	 * @return array{0: int, 1: WC_Order, 2: int} Contract id, replaced order, replacement order id.
	 */
	private function replace_a_cancelled_renewal( string $outcome ): array {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ON_HOLD, 'failed', CycleStatus::FAILED );
		$order->update_status( 'cancelled' );
		$this->support_engine_charges( 'cod', $outcome );
		( new RowActionController( null, static fn (): bool => true ) )->handle_renew_now( [ 'contract_id' => $contract_id ] );
		$replacement_id = (int) $this->head( $contract_id )->get_order_id();
		$this->assertNotSame( $order->get_id(), $replacement_id );

		return [ $contract_id, $order, $replacement_id ];
	}

	public function test_a_replaced_order_paid_late_takes_back_a_renewal_that_is_still_unpaid(): void {
		[ $contract_id, $order, $replacement_id ] = $this->replace_a_cancelled_renewal( 'failed' );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );

		wc_get_order( $order->get_id() )->payment_complete();

		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertSame( $order->get_id(), $head->get_order_id(), 'The paid order is relinked to the renewal.' );
		$this->assertSame( 'cancelled', wc_get_order( $replacement_id )->get_status(), 'The unpaid replacement is cancelled.' );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_replaced_order_paid_after_its_replacement_paid_is_flagged_for_a_refund(): void {
		[ $contract_id, $order, $replacement_id ] = $this->replace_a_cancelled_renewal( 'paid' );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->capture_log();

		wc_get_order( $order->get_id() )->payment_complete();
		wc_get_order( $order->get_id() )->update_status( 'completed' );

		$this->assertSame( $replacement_id, $this->head( $contract_id )->get_order_id() );
		$this->assertTrue( $this->has_logged( 'a refund is needed' ) );
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$this->assertCount(
			1,
			array_filter(
				$notes,
				static function ( $note ) use ( $replacement_id ): bool {
					return false !== strpos( $note->content, '#' . $replacement_id );
				}
			),
			'One note names the replacement that paid, however often the order changes status.'
		);
	}

	public function test_customers_cannot_cancel_a_renewal_order_from_my_account(): void {
		[ , $customer_id, $order ] = $this->seed( ContractStatus::ON_HOLD, 'failed', CycleStatus::FAILED );
		$plain                     = wc_create_order( [ 'customer_id' => $customer_id ] );
		$plain->set_total( '10' );
		$plain->save();

		$this->assertArrayNotHasKey( 'cancel', wc_get_account_orders_actions( $order ) );
		$this->assertArrayHasKey( 'pay', wc_get_account_orders_actions( $order ), 'The customer can still pay it.' );
		$this->assertArrayHasKey( 'cancel', wc_get_account_orders_actions( $plain ), 'Other orders keep the cancel action.' );
	}

	public function test_a_trashed_manual_renewal_order_fails_its_cycle(): void {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ON_HOLD, 'pending', CycleStatus::PENDING );

		$order_id = $order->get_id();
		$order->delete();

		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::FAILED, $head->get_status() );
		$this->assertSame( Settlement::REASON_ORDER_CANCELLED, $head->get_reason() );
		$this->assertSame( $order_id, $head->get_order_id() );
		$this->assertSame( 'trash', wc_get_order( $order_id )->get_status(), 'The order was trashed, not deleted.' );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_permanently_deleted_unpaid_renewal_order_fails_its_cycle(): void {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ACTIVE, 'on-hold', CycleStatus::PROCESSING );

		$order->delete( true );

		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::FAILED, $head->get_status() );
		$this->assertSame( Settlement::REASON_ORDER_CANCELLED, $head->get_reason() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_an_unpaid_renewal_order_trashed_from_the_legacy_posts_screen_fails_its_cycle(): void {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'The suite runs on the orders table; the legacy posts screen only exists with post storage.' );
		}
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ACTIVE, 'on-hold', CycleStatus::PROCESSING );

		wp_trash_post( $order->get_id() );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_deleted_paid_renewal_order_changes_nothing(): void {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ACTIVE, 'completed', CycleStatus::BILLED );

		$order->delete( true );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_cancelled_async_renewal_order_holds_an_active_contract(): void {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ACTIVE, 'on-hold', CycleStatus::PROCESSING );

		$order->update_status( 'cancelled' );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
	}

	public function test_a_cancelled_order_of_a_billed_cycle_changes_nothing(): void {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ACTIVE, 'completed', CycleStatus::BILLED );

		$order->update_status( 'cancelled' );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_paying_a_restored_order_settles_the_failed_cycle(): void {
		[ $contract_id, , $order ] = $this->seed( ContractStatus::ON_HOLD, 'pending', CycleStatus::PENDING );
		$order->update_status( 'cancelled' );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );

		$order->update_status( 'pending' );
		$order->payment_complete();

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}
}
