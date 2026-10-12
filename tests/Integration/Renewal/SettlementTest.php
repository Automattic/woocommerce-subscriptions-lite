<?php
/**
 * Integration tests for Settlement: renewal order outcomes on cycles and contracts.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\Settlement
 */
final class SettlementTest extends LiteIntegrationTestCase {

	/**
	 * Renewal actions fired, as `action => count`.
	 *
	 * @var array<string, int>
	 */
	private $fired = [];

	public function set_up(): void {
		parent::set_up();
		// Drive settle() directly with a fixed clock; the bootstrap-bound listeners are
		// covered by the hook test below (hooks are restored after each test).
		$this->unhook_settlement();
		$this->fired = [];
		foreach ( [ 'woocommerce_subscriptions_lite_renewal_paid', 'woocommerce_subscriptions_lite_renewal_failed' ] as $action ) {
			add_action(
				$action,
				function () use ( $action ): void {
					$this->fired[ $action ] = ( $this->fired[ $action ] ?? 0 ) + 1;
				}
			);
		}
	}

	/**
	 * Remove the Settlement listeners the bootstrap bound.
	 */
	private function unhook_settlement(): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Settlement ) {
						remove_filter( $hook_name, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * A UTC moment.
	 *
	 * @param string $datetime GMT datetime string.
	 */
	private function utc( string $datetime ): DateTimeImmutable {
		return new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A checkout contract (cycle 1 from 2026-01-15 to 2026-02-15) with a cycle-2 renewal
	 * order in `$order_status` billing a `$cycle_status` cycle (2026-02-15 to 2026-03-15).
	 *
	 * @param string $order_status Renewal order status.
	 * @param string $cycle_status Cycle status.
	 * @param string $status       Contract status.
	 * @return array{0: int, 1: WC_Order}
	 */
	private function seed_renewal( string $order_status = 'pending', string $cycle_status = CycleStatus::PENDING, string $status = 'active' ): array {
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

		return [ $contract_id, $order ];
	}

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
	 * Mark an order paid on a fixed date.
	 *
	 * @param WC_Order $order Order.
	 */
	private function pay( WC_Order $order ): void {
		$order->payment_complete();
		$order->set_date_paid( '2026-02-16 10:00:00' );
		$order->save();
	}

	public function test_a_paid_order_bills_a_pending_cycle_once_and_arms_the_next_period(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$cycle    = $this->head( $contract_id );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $cycle->get_status() );
		$this->assertSame( $order->get_id(), $cycle->get_order_id() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-03-15 00:00:00', $contract->get_next_payment_gmt() );
		$this->assertSame( '2026-02-16 10:00:00', $contract->get_last_payment_gmt() );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired );
	}

	public function test_a_paid_order_bills_a_processing_cycle(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'on-hold', CycleStatus::PROCESSING );
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( 1, $this->fired['woocommerce_subscriptions_lite_renewal_paid'] ?? 0 );
	}

	public function test_paying_a_failed_order_reactivates_the_contract_and_rolls_the_next_due_past_now(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'failed', CycleStatus::FAILED );
		Contracts::update(
			$contract_id,
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		HoldAnchor::store( $contract_id, '2026-03-15 00:00:00' );
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-05-01 00:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertNull( $this->head( $contract_id )->get_reason() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-05-15 00:00:00', $contract->get_next_payment_gmt() );
		$this->assertNull( HoldAnchor::read( $contract_id ) );
	}

	public function test_a_failed_order_fails_the_cycle_and_holds_the_contract_once(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->update_status( 'failed' );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$cycle    = $this->head( $contract_id );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::FAILED, $cycle->get_status() );
		$this->assertSame( Settlement::REASON_PAYMENT_FAILED, $cycle->get_reason() );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertSame( '2026-02-16 12:00:00', $contract->get_last_attempt_gmt() );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_failed' => 1 ], $this->fired );
	}

	public function test_an_on_hold_order_moves_the_cycle_to_processing(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'on-hold' );

		( new Settlement() )->settle( $order );

		$this->assertSame( CycleStatus::PROCESSING, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( [], $this->fired );
	}

	public function test_a_pending_order_after_a_charge_moves_the_cycle_to_processing(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'pending' );

		( new Settlement() )->settle( $order );

		$this->assertSame( CycleStatus::PROCESSING, $this->head( $contract_id )->get_status() );
	}

	public function test_an_order_that_is_not_a_renewal_order_is_ignored(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$origin      = wc_get_order( (int) $this->get_contract( $contract_id )->get_origin_order_id() );
		$this->assertInstanceOf( WC_Order::class, $origin );
		$origin->update_status( 'failed' );

		( new Settlement() )->settle( $origin );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_cycle_of_another_extension_is_ignored(): void {
		$contract_id = $this->seed_contract( [ 'extension_slug' => 'another-extension' ] );
		$order       = wc_create_order(
			[
				'customer_id' => 1,
				'created_via' => RenewalOrderBuilder::CREATED_VIA,
			]
		);
		Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => '2026-02-15 00:00:00',
				'ends_at_gmt'    => '2026-03-15 00:00:00',
				'expected_total' => '19.99',
				'currency'       => 'USD',
				'order_id'       => $order->get_id(),
			]
		);
		$order->update_status( 'failed' );

		( new Settlement() )->settle( $order );

		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertNotSame( Package::EXTENSION_SLUG, $this->get_contract( $contract_id )->get_extension_slug() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_late_payment_on_a_cancelled_contract_bills_the_cycle_and_keeps_the_status(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'pending', CycleStatus::PENDING, 'cancelled' );
		$before                  = $this->get_contract( $contract_id );
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::CANCELLED, $contract->get_status() );
		$this->assertSame( $before->get_next_payment_gmt(), $contract->get_next_payment_gmt() );
		$this->assertSame( '2026-02-16 10:00:00', $contract->get_last_payment_gmt() );
	}

	public function test_a_late_payment_on_a_pending_cancellation_contract_keeps_the_status(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'pending', CycleStatus::PENDING, 'pending-cancellation' );
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_the_bound_listeners_settle_a_renewal_order_from_its_status(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		Settlement::register();

		$order->payment_complete();

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( 1, $this->fired['woocommerce_subscriptions_lite_renewal_paid'] ?? 0 );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}
}
