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
