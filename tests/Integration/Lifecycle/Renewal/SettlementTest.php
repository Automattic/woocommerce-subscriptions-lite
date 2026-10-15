<?php
/**
 * Integration tests for Settlement: renewal order outcomes on cycles and contracts.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\ScheduledActions;
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
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-05-01 00:00:00' ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertNull( $this->head( $contract_id )->get_reason() );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( '2026-05-15 00:00:00', $contract->get_next_payment_gmt() );
	}

	public function test_a_payment_during_a_customer_hold_bills_the_cycle_and_keeps_the_hold(): void {
		[ $contract_id, $order ] = $this->seed_renewal( 'on-hold', CycleStatus::PROCESSING );
		Contracts::update(
			$contract_id,
			[
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
		$actions = as_get_scheduled_actions(
			[
				'hook'     => 'woocommerce_subscriptions_lite_settle_order',
				'group'    => ScheduledActions::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);

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
	}

	public function test_a_paid_order_whose_cycle_was_cancelled_is_flagged_for_a_refund_once(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		Contracts::transition_cycle(
			$this->head( $contract_id )->get_id(),
			[
				'from_status' => CycleStatus::PENDING,
				'to_status'   => CycleStatus::CANCELLED,
			]
		);
		$this->pay( $order );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$notes = array_filter(
			wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ),
			static function ( $note ): bool {
				return false !== strpos( (string) $note, 'Refund this payment' );
			}
		);
		$this->assertCount( 1, $notes );
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_late_payment_flagged' ) );
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

		$actions = as_get_scheduled_actions(
			[
				'hook'   => 'woocommerce_subscriptions_lite_settle_order',
				'args'   => [
					'order_id' => $order->get_id(),
					'paid'     => true,
				],
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ids'
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
}
