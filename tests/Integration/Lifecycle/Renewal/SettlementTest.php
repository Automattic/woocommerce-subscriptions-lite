<?php
/**
 * Integration tests for Settlement: renewal order outcomes on cycles and contracts.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle\Renewal;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingDay;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement
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

	/**
	 * Link a pending cycle of another extension's contract to `$order`, after the Lite cycle,
	 * so it is the newest cycle linked to the order.
	 *
	 * @param WC_Order $order The Lite renewal order.
	 * @return int The foreign contract id.
	 */
	private function link_foreign_cycle( WC_Order $order ): int {
		$foreign_id = $this->seed_contract( [ 'extension_slug' => 'another-extension' ] );
		$this->add_cycle_with_lock(
			$foreign_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => '2026-02-15 00:00:00',
				'ends_at_gmt'    => '2026-03-15 00:00:00',
				'expected_total' => '19.99',
				'currency'       => 'USD',
				'order_id'       => $order->get_id(),
			],
			'another-extension'
		);
		$this->assertSame( $foreign_id, Contracts::find_cycles_by_order( $order->get_id() )[0]->get_contract_id(), 'The foreign cycle is the newest linked one.' );

		return $foreign_id;
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
		$this->assert_lock_released( $contract_id );
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
				'extension_slug'   => Package::EXTENSION_SLUG,
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-05-01 00:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertNull( $this->head( $contract_id )->get_reason() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-05-15 00:00:00', $contract->get_next_payment_gmt() );
	}

	/**
	 * A contract paid on 31 Dec (billing day 31) whose failed renewal for 31 Jan - 28 Feb is
	 * paid late: the contract is on hold with no next payment until the payment.
	 *
	 * @return array{0: int, 1: WC_Order}
	 */
	private function seed_failed_month_end_renewal(): array {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'payment_method' => 'cod',
				'date_paid'      => '2025-12-31 10:00:00',
			]
		);
		$order       = $this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => 'failed',
				'cycle_status' => CycleStatus::FAILED,
				'ends_at_gmt'  => '2026-02-28 10:00:00',
			]
		);
		Contracts::update(
			$contract_id,
			[
				'extension_slug'   => Package::EXTENSION_SLUG,
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		$this->pay( $order );

		return [ $contract_id, $order ];
	}

	public function test_a_late_payment_rearms_on_the_stored_billing_day(): void {
		[ $contract_id, $order ] = $this->seed_failed_month_end_renewal();
		$this->assertSame( 31, BillingDay::read( $contract_id ) );

		( new Settlement() )->settle( $order, $this->utc( '2026-04-10 00:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-04-30 10:00:00', $contract->get_next_payment_gmt(), 'From 28 Feb on the 31st: 31 Mar, then 30 Apr past now.' );
		$this->assertSame( 31, BillingDay::read( $contract_id ) );
	}

	/**
	 * Store a billing day for a contract under its lock.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $billing_day Day of the month.
	 */
	private function store_billing_day( int $contract_id, int $billing_day ): void {
		$lock = $this->hold_lock( $contract_id );
		try {
			BillingDay::store( $lock, $billing_day );
		} finally {
			$lock->release();
		}
	}

	public function test_a_late_payment_off_the_billing_day_schedule_reanchors_it(): void {
		[ $contract_id, $order ] = $this->seed_failed_month_end_renewal();
		$this->store_billing_day( $contract_id, 3 );

		( new Settlement() )->settle( $order, $this->utc( '2026-04-10 00:00:00' ) );

		$this->assertSame( '2026-04-28 10:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt(), 'Whole months from 28 Feb, not 3 Mar.' );
		$this->assertSame( 28, BillingDay::read( $contract_id ) );
	}

	public function test_a_late_payment_a_day_off_the_billing_day_schedule_keeps_it(): void {
		[ $contract_id, $order ] = $this->seed_failed_month_end_renewal();
		$this->store_billing_day( $contract_id, 1 );

		( new Settlement() )->settle( $order, $this->utc( '2026-04-10 00:00:00' ) );

		$this->assertSame( '2026-05-01 10:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt(), '28 Feb is a day from 1 Mar: 1 Apr, then 1 May past now.' );
		$this->assertSame( 1, BillingDay::read( $contract_id ) );
	}

	public function test_a_late_payment_during_a_customer_hold_moves_the_anchor_on_the_stored_billing_day(): void {
		[ $contract_id, $order ] = $this->seed_failed_month_end_renewal();
		$this->store_hold_anchor( $contract_id, '2026-02-28 10:00:00' );

		( new Settlement() )->settle( $order, $this->utc( '2026-04-10 00:00:00' ) );

		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( '2026-04-30 10:00:00', HoldAnchor::read( $contract_id ) );
	}

	public function test_a_late_payment_during_a_customer_hold_off_the_billing_day_schedule_reanchors_it(): void {
		[ $contract_id, $order ] = $this->seed_failed_month_end_renewal();
		$this->store_billing_day( $contract_id, 3 );
		$this->store_hold_anchor( $contract_id, '2026-02-28 10:00:00' );

		( new Settlement() )->settle( $order, $this->utc( '2026-04-10 00:00:00' ) );

		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( '2026-04-28 10:00:00', HoldAnchor::read( $contract_id ), 'Whole months from 28 Feb, not 3 Mar.' );
		$this->assertSame( 28, BillingDay::read( $contract_id ) );
	}

	public function test_a_payment_during_a_customer_hold_bills_the_cycle_and_keeps_the_hold(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'on-hold', CycleStatus::PROCESSING );
		Contracts::update(
			$contract_id,
			[
				'extension_slug'   => Package::EXTENSION_SLUG,
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		$this->store_hold_anchor( $contract_id, '2026-03-15 00:00:00' );
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-04-01 00:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertSame( '2026-02-16 10:00:00', $contract->get_last_payment_gmt() );
		$this->assertSame( '2026-04-15 00:00:00', HoldAnchor::read( $contract_id ), 'The anchor moves to the next due moment, rolled past now.' );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired );
	}

	public function test_an_async_charge_that_fails_during_a_customer_hold_drops_the_anchor(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'on-hold', CycleStatus::PROCESSING );
		Contracts::update(
			$contract_id,
			[
				'extension_slug'   => Package::EXTENSION_SLUG,
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		$this->store_hold_anchor( $contract_id, '2026-03-15 00:00:00' );
		$order->update_status( 'failed' );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertNull( HoldAnchor::read( $contract_id ), 'The anchor would sit on the end of an unpaid period.' );
	}

	public function test_a_failed_order_on_an_active_contract_drops_a_leftover_hold_anchor(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->store_hold_anchor( $contract_id, '2026-01-15 00:00:00' );
		$order->update_status( 'failed' );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertNull( HoldAnchor::read( $contract_id ), 'The hold is for the failed payment, not a customer hold.' );
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

	public function test_an_on_hold_order_moves_the_cycle_to_processing_and_off_the_due_scan(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'on-hold' );
		$this->assertNotNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );

		( new Settlement() )->settle( $order );

		$this->assertSame( CycleStatus::PROCESSING, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( [], $this->fired );
	}

	public function test_a_pending_order_leaves_the_cycle_alone(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'pending' );

		( new Settlement() )->settle( $order );

		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertSame( [], $this->fired );
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
		$this->add_cycle_with_lock(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => '2026-02-15 00:00:00',
				'ends_at_gmt'    => '2026-03-15 00:00:00',
				'expected_total' => '19.99',
				'currency'       => 'USD',
				'order_id'       => $order->get_id(),
			],
			'another-extension'
		);
		$order->update_status( 'failed' );

		( new Settlement() )->settle( $order );

		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertNotSame( Package::EXTENSION_SLUG, $this->get_contract( $contract_id )->get_extension_slug() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_foreign_cycle_linked_to_the_same_order_is_ignored(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$foreign_id              = $this->link_foreign_cycle( $order );
		$order->update_status( OrderStatus::FAILED );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( CycleStatus::PENDING, $this->head( $foreign_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $foreign_id )->get_status() );
	}

	public function test_a_removed_order_ignores_a_foreign_cycle_linked_to_it(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$foreign_id              = $this->link_foreign_cycle( $order );

		( new Settlement() )->handle_removed_order( $order->get_id() );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( CycleStatus::PENDING, $this->head( $foreign_id )->get_status() );
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

	public function test_the_hook_entry_point_logs_a_failed_settlement_instead_of_rethrowing(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->payment_complete();
		$contracts_table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		add_filter(
			'query',
			static function ( $query ) use ( $contracts_table ) {
				if ( is_string( $query ) && 0 === strpos( $query, "UPDATE `{$contracts_table}`" ) ) {
					throw new Error( 'The database went away.' );
				}
				return $query;
			}
		);
		$this->capture_log();

		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertTrue( $this->has_logged( 'settling renewal order' ) );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assert_lock_released( $contract_id );
		$this->assertSame( [], $this->pending_retries( $order->get_id() ), 'A non-storage failure is not retried.' );
	}

	/**
	 * @dataProvider provide_contract_states_after_a_settled_payment
	 *
	 * @param string      $status          Contract status after the settlement.
	 * @param string|null $next_payment    Its next due moment.
	 * @param string|null $anchor          Its hold anchor.
	 * @param string      $expected_status Status after a repeat paid signal.
	 * @param string|null $expected_next   Next due moment after it.
	 * @param string|null $expected_anchor Hold anchor after it.
	 */
	public function test_a_repeat_paid_signal_completes_only_a_contract_that_does_not_show_the_payment( string $status, ?string $next_payment, ?string $anchor, string $expected_status, ?string $expected_next, ?string $expected_anchor ): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->pay( $order );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		Contracts::update(
			$contract_id,
			[
				'extension_slug'   => Package::EXTENSION_SLUG,
				'status'           => $status,
				'next_payment_gmt' => $next_payment,
			]
		);
		$this->store_hold_anchor( $contract_id, $anchor );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-20 12:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( $expected_status, $contract->get_status() );
		$this->assertSame( $expected_next, $contract->get_next_payment_gmt() );
		$this->assertSame( $expected_anchor, HoldAnchor::read( $contract_id ) );
	}

	/**
	 * Contract states after the 2026-02-15 to 2026-03-15 cycle was billed, and what a repeat
	 * paid signal leaves.
	 *
	 * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: string, 4: ?string, 5: ?string}>
	 */
	public function provide_contract_states_after_a_settled_payment(): array {
		return [
			'active, armed at the cycle end'         => [ 'active', '2026-03-15 00:00:00', null, 'active', '2026-03-15 00:00:00', null ],
			'active, armed later (edited)'           => [ 'active', '2026-04-15 00:00:00', null, 'active', '2026-04-15 00:00:00', null ],
			'active, no due moment (cut short)'      => [ 'active', null, null, 'active', '2026-03-15 00:00:00', null ],
			'active, due before the cycle end'       => [ 'active', '2026-02-15 00:00:00', null, 'active', '2026-03-15 00:00:00', null ],
			'Lite hold (cut short)'                  => [ 'on-hold', null, null, 'active', '2026-03-15 00:00:00', null ],
			'customer hold, anchor at a later point' => [ 'on-hold', null, '2026-04-15 00:00:00', 'on-hold', null, '2026-04-15 00:00:00' ],
			'customer hold, anchor before the end'   => [ 'on-hold', null, '2026-02-15 00:00:00', 'on-hold', null, '2026-03-15 00:00:00' ],
			'cancelled'                              => [ 'cancelled', null, null, 'cancelled', null, null ],
		];
	}

	public function test_the_bound_listeners_settle_a_renewal_order_from_its_status(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		Settlement::register();

		$order->payment_complete();

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( 1, $this->fired['woocommerce_subscriptions_lite_renewal_paid'] ?? 0 );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	/**
	 * Pending settlement retries for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array<int, int> Action ids.
	 */
	private function pending_retries( int $order_id ): array {
		$actions = $this->get_pending_actions( 'woocommerce_subscriptions_lite_settle_order' );

		return array_keys(
			array_filter(
				$actions,
				static function ( $action ) use ( $order_id ): bool {
					return ( $action->get_args()['order_id'] ?? null ) === $order_id;
				}
			)
		);
	}

	public function test_a_paid_order_under_another_operations_lock_is_retried_once_and_settles_after_release(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$lock                    = $this->hold_lock( $contract_id, 'hold' );
		$this->pay( $order );

		( new Settlement() )->handle_order( $order->get_id() );
		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertCount( 1, $this->pending_retries( $order->get_id() ), 'One retry, however many signals.' );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status(), 'Nothing written under the other lock.' );
		$this->assertSame( [], $this->fired );

		$lock->release();
		( new Settlement() )->retry( $order->get_id() );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$this->assertNotNull( $this->get_contract( $contract_id )->get_next_payment_gmt(), 'Re-armed.' );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired );
		$this->assert_lock_released( $contract_id );
	}

	public function test_settle_under_another_operations_lock_returns_the_conflict_and_writes_nothing(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$lock                    = $this->hold_lock( $contract_id, 'hold' );
		$this->pay( $order );

		$conflict = ( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assert_lock_conflict( $conflict, $contract_id, 'hold' );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertSame( [], $this->fired );
		$lock->release();
		$this->assertNull( ( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) ) );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
	}

	public function test_a_retry_refused_again_schedules_the_next_one(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->hold_lock( $contract_id, 'hold' );
		$this->pay( $order );
		( new Settlement() )->handle_order( $order->get_id() );
		$first = $this->pending_retries( $order->get_id() );
		\ActionScheduler::store()->mark_complete( (int) $first[0] );

		( new Settlement() )->retry( $order->get_id() );

		$this->assertCount( 1, $this->pending_retries( $order->get_id() ) );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
	}

	public function test_a_running_retry_refused_again_schedules_the_next_one(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->hold_lock( $contract_id, 'hold' );
		$this->pay( $order );
		( new Settlement() )->handle_order( $order->get_id() );
		$running = $this->pending_retries( $order->get_id() );
		\ActionScheduler::store()->log_execution( (int) $running[0] );

		( new Settlement() )->retry( $order->get_id() );

		$next = $this->pending_retries( $order->get_id() );
		$this->assertCount( 1, $next, 'The running retry does not count as pending.' );
		$this->assertNotSame( $running, $next );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
	}

	public function test_an_unpaid_renewal_order_deleted_under_another_lock_fails_its_cycle_on_the_retry(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$lock                    = $this->hold_lock( $contract_id, 'renewal' );
		$order_id                = $order->get_id();

		( new Settlement() )->handle_removed_order( $order_id );
		$order->delete( true );
		$this->assertCount( 1, $this->pending_retries( $order_id ) );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );

		$lock->release();
		( new Settlement() )->retry( $order_id );

		$cycle = $this->head( $contract_id );
		$this->assertSame( CycleStatus::FAILED, $cycle->get_status() );
		$this->assertSame( Settlement::REASON_ORDER_CANCELLED, $cycle->get_reason() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertSame( '', Contracts::get_meta( $contract_id, HoldAnchor::META_KEY, true ), 'The system hold carries no anchor.' );
		$this->assert_lock_released( $contract_id );
	}

	public function test_a_paid_order_whose_cycle_was_cancelled_is_flagged_for_a_refund_once(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->transition_cycle_with_lock(
			$contract_id,
			$this->head( $contract_id )->get_id(),
			[
				'from_status' => CycleStatus::PENDING,
				'to_status'   => CycleStatus::CANCELLED,
			]
		);
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'Refund this payment' ) );
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_refund_flagged' ) );
		$this->assertSame( CycleStatus::CANCELLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( [], $this->fired );
	}

	/**
	 * A customer hold that takes the lock first is not overwritten: the settlement retries and
	 * then reads the contract on hold with its anchor, so the hold absorbs the payment.
	 */
	public function test_a_settlement_racing_a_customer_hold_keeps_the_hold(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$hold_lock               = $this->hold_lock( $contract_id, Hold::OPERATION );
		$this->pay( $order );

		( new Settlement() )->handle_order( $order->get_id() );
		$hold_lock->release();
		( new Hold() )->hold( $contract_id );
		( new Settlement() )->retry( $order->get_id() );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status(), 'The customer hold stands.' );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertNotSame( '', Contracts::get_meta( $contract_id, HoldAnchor::META_KEY, true ), 'The hold keeps its anchor.' );
	}

	public function test_the_bound_listeners_retry_a_refused_settlement_through_the_retry_hook(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		Settlement::register();
		$lock = $this->hold_lock( $contract_id, 'hold' );

		$order->payment_complete();

		$this->assertCount( 1, $this->pending_retries( $order->get_id() ) );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );

		$lock->release();
		do_action( 'woocommerce_subscriptions_lite_settle_order', $order->get_id() );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
	}

	public function test_a_retry_carries_whether_the_order_was_paid(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->hold_lock( $contract_id, 'hold' );
		$this->pay( $order );

		( new Settlement() )->handle_order( $order->get_id() );

		$actions = $this->get_pending_actions(
			'woocommerce_subscriptions_lite_settle_order',
			[
				'order_id' => $order->get_id(),
				'paid'     => true,
			]
		);
		$this->assertCount( 1, $actions );
	}

	public function test_a_paid_order_deleted_before_its_retry_fails_nothing_and_logs_a_warning(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order_id                = $order->get_id();
		$order->delete( true );
		$this->capture_log();

		( new Settlement() )->retry( $order_id, true );

		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status(), 'The cycle is not failed.' );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status(), 'The contract is not held.' );
		$this->assertTrue( $this->has_logged( 'was paid and then deleted before its settlement' ) );
	}

	/**
	 * Regex for the engine's cycle-by-order read (`Contracts::find_cycles_by_order()`).
	 */
	private static function cycle_lookup(): string {
		return '/FROM ' . preg_quote( SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CYCLES ), '/' ) . ' WHERE order_id/';
	}

	/**
	 * The order's failed settlement attempt count, read from the database.
	 *
	 * @param int $order_id Order id.
	 */
	private function settle_attempts( int $order_id ): string {
		$order = wc_get_order( $order_id );
		$order->read_meta_data( true );

		return (string) $order->get_meta( '_wcsl_settle_attempts' );
	}

	/**
	 * Run the order's pending retry as Action Scheduler would (no longer pending once it runs),
	 * with the cycle lookup failing.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $paid     The retry's paid argument.
	 */
	private function run_failing_retry( int $order_id, bool $paid ): void {
		foreach ( $this->pending_retries( $order_id ) as $action_id ) {
			\ActionScheduler::store()->mark_complete( $action_id );
		}
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->retry( $order_id, $paid );
	}

	/**
	 * A failed cycle lookup must not read as "no cycle linked" (a replaced order): the failure is
	 * logged and a retry scheduled for after the lock expires; nothing is written or flagged.
	 */
	public function test_a_failed_cycle_lookup_on_a_paid_order_schedules_a_retry_and_settles_nothing(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->payment_complete();
		$this->capture_log();

		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertTrue( $this->has_logged( sprintf( 'settling renewal order %d failed', $order->get_id() ) ) );
		$this->assertTrue( $this->has_logged( 'a retry is scheduled.' ) );
		$actions = $this->get_pending_actions(
			'woocommerce_subscriptions_lite_settle_order',
			[
				'order_id' => $order->get_id(),
				'paid'     => true,
			]
		);
		$this->assertCount( 1, $actions );
		$run_at = reset( $actions )->get_schedule()->get_date()->getTimestamp();
		$this->assertEqualsWithDelta( time() + 660, $run_at, 10, 'Retried once the lock has expired.' );
		$this->assertSame( '', $this->settle_attempts( $order->get_id() ), 'The hook signal is not a counted retry run.' );
		$this->assertSame( [], $this->get_order_notes_containing( $order->get_id(), 'The subscription for this renewal order' ) );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_refund_flagged' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order->get_id(), 'Refund this payment' ) );
		$this->assertSame( [], $this->fired );
	}

	public function test_the_retry_after_a_failed_cycle_lookup_settles_the_paid_order(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->payment_complete();
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order->get_id() );
		$this->assertCount( 1, $this->pending_retries( $order->get_id() ) );

		( new Settlement() )->retry( $order->get_id(), true );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $contract->get_next_payment_gmt() );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired );
		$this->assertSame( '', $this->settle_attempts( $order->get_id() ) );
		$this->assert_lock_released( $contract_id );
	}

	/**
	 * A customer pays a failed renewal late (the My Account pay link completes the payment) while
	 * the cycle lookup fails: the contract stays on the system hold until the retry resumes it.
	 */
	public function test_a_late_payment_of_a_failed_renewal_whose_lookup_fails_is_settled_by_the_retry(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'failed', CycleStatus::FAILED );
		Contracts::update(
			$contract_id,
			[
				'extension_slug'   => Package::EXTENSION_SLUG,
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		Settlement::register();

		$this->fail_next_query( self::cycle_lookup() );
		$order->payment_complete();

		$this->assertCount(
			1,
			$this->get_pending_actions(
				'woocommerce_subscriptions_lite_settle_order',
				[
					'order_id' => $order->get_id(),
					'paid'     => true,
				]
			)
		);
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );

		do_action( 'woocommerce_subscriptions_lite_settle_order', $order->get_id(), true );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), (string) $contract->get_next_payment_gmt(), 'Rolled past now.' );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired );
	}

	public function test_retry_runs_that_keep_failing_stop_at_the_cap_with_one_note(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order_id                = $order->get_id();
		$order->payment_complete();
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		$this->capture_log();

		$this->run_failing_retry( $order_id, true );
		$this->assertSame( '1', $this->settle_attempts( $order_id ) );
		$this->assertCount( 1, $this->pending_retries( $order_id ) );

		$this->run_failing_retry( $order_id, true );
		$this->assertSame( '2', $this->settle_attempts( $order_id ) );
		$this->assertCount( 1, $this->pending_retries( $order_id ) );
		$this->assertFalse( $this->has_logged( 'gave up settling' ) );

		$this->run_failing_retry( $order_id, true );
		$this->assertSame( [], $this->pending_retries( $order_id ), 'No retry after the cap.' );
		$this->assertTrue( $this->has_logged( sprintf( 'gave up settling renewal order %d after 3 failed attempts', $order_id ) ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order_id, 'still could not be updated after several automatic attempts' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order_id, 'no automatic retry could be scheduled' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order_id, 'table_that_does_not_exist' ), 'No database text in notes.' );
		$this->assertTrue( $this->has_logged( 'no retry is scheduled.' ) );
		$this->assertSame( '', $this->settle_attempts( $order_id ), 'Cleared, so a later failure gets its own attempts.' );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
	}

	public function test_a_capped_run_whose_retry_cannot_be_scheduled_gets_only_the_cap_note(): void {
		[ , $order ] = $this->seed_renewal();
		$order_id    = $order->get_id();
		$order->payment_complete();
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		$this->run_failing_retry( $order_id, true );
		$this->run_failing_retry( $order_id, true );
		add_filter( 'pre_as_schedule_single_action', '__return_zero' ); // Action Scheduler cannot store it.

		$this->run_failing_retry( $order_id, true );

		$this->assertCount( 1, $this->get_order_notes_containing( $order_id, 'still could not be updated after several automatic attempts' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order_id, 'no automatic retry could be scheduled' ), 'The cap schedules nothing, so it has nothing to report.' );
		$this->assertSame( '', $this->settle_attempts( $order_id ) );
	}

	public function test_a_retry_run_whose_successor_cannot_be_scheduled_drops_its_count(): void {
		[ , $order ] = $this->seed_renewal();
		$order_id    = $order->get_id();
		$order->payment_complete();
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		$this->run_failing_retry( $order_id, true );
		$this->assertSame( '1', $this->settle_attempts( $order_id ) );
		add_filter( 'pre_as_schedule_single_action', '__return_zero' ); // Action Scheduler cannot store it.
		$this->capture_log();

		$this->run_failing_retry( $order_id, true );

		$this->assertSame( [], $this->pending_retries( $order_id ) );
		$this->assertSame( '', $this->settle_attempts( $order_id ), 'No retry continues the count.' );
		$this->assertTrue( $this->has_logged( sprintf( 'settling renewal order %d failed', $order_id ) ) );
		$this->assertTrue( $this->has_logged( 'no retry is scheduled.' ) );
		$this->assertFalse( $this->has_logged( 'a retry is scheduled.' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order_id, 'no automatic retry could be scheduled' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order_id, 'still could not be updated after several automatic attempts' ) );
	}

	public function test_a_note_that_cannot_be_added_for_an_unretried_settlement_is_logged(): void {
		[ , $order ] = $this->seed_renewal();
		$order->payment_complete();
		add_filter( 'pre_as_schedule_single_action', '__return_zero' ); // Action Scheduler cannot store it.
		add_filter( 'woocommerce_new_order_note_data', [ self::class, 'throw_on_note' ] );
		$this->capture_log();

		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertTrue( $this->has_logged( sprintf( 'failed to note the unretried settlement on renewal order %d', $order->get_id() ) ) );
		$this->assertSame( [], $this->pending_retries( $order->get_id() ) );
	}

	public function test_a_cap_that_cannot_be_recorded_is_logged_and_not_retried(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order_id                = $order->get_id();
		$order->payment_complete();
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		$this->run_failing_retry( $order_id, true );
		$this->run_failing_retry( $order_id, true );
		add_filter( 'woocommerce_new_order_note_data', [ self::class, 'throw_on_note' ] );
		$this->capture_log();

		$this->run_failing_retry( $order_id, true );

		$this->assertTrue( $this->has_logged( sprintf( 'failed to record a failed settlement on renewal order %d', $order_id ) ) );
		$this->assertTrue( $this->has_logged( sprintf( 'failed to note the unretried settlement on renewal order %d', $order_id ) ) );
		$this->assertSame( [], $this->pending_retries( $order_id ), 'A count that cannot be recorded does not loop.' );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
	}

	public function test_a_retry_run_that_ends_in_a_non_storage_error_is_not_counted_or_retried(): void {
		[ , $order ] = $this->seed_renewal();
		$order_id    = $order->get_id();
		$order->payment_complete();
		$this->capture_log();
		add_action(
			'woocommerce_subscriptions_lite_renewal_paid',
			static function () {
				throw new \Error( 'A bug in a listener.' );
			}
		);

		( new Settlement() )->retry( $order_id, true );

		$this->assertTrue( $this->has_logged( sprintf( 'settling renewal order %d failed: A bug in a listener.', $order_id ) ) );
		$this->assertSame( '', $this->settle_attempts( $order_id ) );
		$this->assertSame( [], $this->pending_retries( $order_id ) );
	}

	/**
	 * `woocommerce_new_order_note_data` callback that makes adding the note throw.
	 *
	 * @throws \RuntimeException Always.
	 */
	public static function throw_on_note(): void {
		throw new \RuntimeException( 'The note could not be added.' );
	}

	public function test_a_failure_after_the_cap_gets_its_own_attempts_and_note(): void {
		[ , $order ] = $this->seed_renewal();
		$order_id    = $order->get_id();
		$order->payment_complete();
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		for ( $run = 1; $run <= 3; $run++ ) {
			$this->run_failing_retry( $order_id, true );
		}

		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		$this->assertCount( 1, $this->pending_retries( $order_id ) );
		$this->assertSame( '', $this->settle_attempts( $order_id ) );

		$this->run_failing_retry( $order_id, true );
		$this->assertSame( '1', $this->settle_attempts( $order_id ), 'Counted from zero again.' );
		$this->run_failing_retry( $order_id, true );
		$this->run_failing_retry( $order_id, true );

		$this->assertSame( [], $this->pending_retries( $order_id ) );
		$this->assertCount( 2, $this->get_order_notes_containing( $order_id, 'still could not be updated after several automatic attempts' ), 'One note per episode.' );
	}

	public function test_a_pending_lock_retry_absorbs_a_failure_retry_and_a_refused_run_is_not_counted(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order_id                = $order->get_id();
		$lock                    = $this->hold_lock( $contract_id, 'hold' );
		$order->payment_complete();
		( new Settlement() )->handle_order( $order_id );
		$lock->release();

		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order_id );
		$this->assertCount( 1, $this->pending_retries( $order_id ), 'The 60 s lock retry covers the failure retry.' );

		$this->run_failing_retry( $order_id, true );
		$this->assertSame( '1', $this->settle_attempts( $order_id ) );

		$this->hold_lock( $contract_id, 'hold' );
		foreach ( $this->pending_retries( $order_id ) as $action_id ) {
			\ActionScheduler::store()->mark_complete( $action_id );
		}
		( new Settlement() )->retry( $order_id, true );

		$this->assertSame( '1', $this->settle_attempts( $order_id ), 'A lock conflict neither counts nor clears.' );
		$this->assertCount( 1, $this->pending_retries( $order_id ) );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
	}

	public function test_a_failed_order_whose_lookup_fails_is_failed_and_held_by_the_retry(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->update_status( OrderStatus::FAILED );
		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertCount(
			1,
			$this->get_pending_actions(
				'woocommerce_subscriptions_lite_settle_order',
				[
					'order_id' => $order->get_id(),
					'paid'     => false,
				]
			)
		);
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );

		( new Settlement() )->retry( $order->get_id(), false );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_failed' => 1 ], $this->fired );
	}

	/**
	 * The contract write fails after the cycle was billed: the retry takes the billed-cycle
	 * branch and completes the contract write.
	 */
	public function test_a_contract_write_failure_after_billing_is_completed_by_the_retry(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->payment_complete();
		$this->fail_next_query( '/^UPDATE `' . preg_quote( SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS ), '/' ) . '`/' );
		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( [], $this->fired );
		$this->assertCount( 1, $this->pending_retries( $order->get_id() ) );

		( new Settlement() )->retry( $order->get_id(), true );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertGreaterThanOrEqual( (string) $this->head( $contract_id )->get_ends_at_gmt(), (string) $contract->get_next_payment_gmt() );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired );
	}

	public function test_a_failed_charge_backstop_run_counts_and_reschedules(): void {
		[ , $order ] = $this->seed_renewal();
		$order_id    = $order->get_id();
		Settlement::schedule_charge_settlement( $order_id );

		$this->run_failing_retry( $order_id, false );

		$this->assertSame( '1', $this->settle_attempts( $order_id ) );
		$this->assertCount(
			1,
			$this->get_pending_actions(
				'woocommerce_subscriptions_lite_settle_order',
				[
					'order_id' => $order_id,
					'paid'     => false,
				]
			)
		);
	}

	public function test_a_retry_that_cannot_be_scheduled_is_logged_and_noted(): void {
		[ , $order ] = $this->seed_renewal();
		$order->payment_complete();
		add_filter( 'pre_as_schedule_single_action', '__return_zero' ); // Action Scheduler cannot store it.
		$this->capture_log();

		$this->fail_next_query( self::cycle_lookup() );
		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertSame( [], $this->pending_retries( $order->get_id() ) );
		$this->assertTrue( $this->has_logged( sprintf( 'could not schedule the settlement retry of renewal order %d', $order->get_id() ) ) );
		$this->assertTrue( $this->has_logged( 'no retry is scheduled.' ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'no automatic retry could be scheduled' ) );
		$this->assertSame( [], $this->get_order_notes_containing( $order->get_id(), 'table_that_does_not_exist' ), 'No database text in notes.' );
	}

	public function test_a_lock_retry_that_cannot_be_scheduled_is_logged_and_noted(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->hold_lock( $contract_id, 'hold' );
		$order->payment_complete();
		add_filter( 'pre_as_schedule_single_action', '__return_zero' ); // Action Scheduler cannot store it.
		$this->capture_log();

		( new Settlement() )->handle_order( $order->get_id() );

		$this->assertSame( [], $this->pending_retries( $order->get_id() ) );
		$this->assertTrue( $this->has_logged( sprintf( 'could not schedule the settlement retry of renewal order %d', $order->get_id() ) ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'no automatic retry could be scheduled' ) );
	}

	public function test_a_retry_after_another_signal_settled_the_order_writes_nothing_and_clears_the_count(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->pay( $order );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		$before = $this->get_contract( $contract_id );
		$order->update_meta_data( '_wcsl_settle_attempts', '2' ); // Earlier retry runs failed.
		$order->save_meta_data();

		( new Settlement() )->retry( $order->get_id(), true );

		$after = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( $before->get_status(), $after->get_status() );
		$this->assertSame( $before->get_next_payment_gmt(), $after->get_next_payment_gmt() );
		$this->assertSame( $before->get_last_payment_gmt(), $after->get_last_payment_gmt() );
		$this->assertSame( [ 'woocommerce_subscriptions_lite_renewal_paid' => 1 ], $this->fired, 'Not fired again.' );
		$this->assertSame( [], $this->pending_retries( $order->get_id() ) );
		$this->assertSame( '', $this->settle_attempts( $order->get_id() ), 'A completed run clears the count.' );
	}
}
