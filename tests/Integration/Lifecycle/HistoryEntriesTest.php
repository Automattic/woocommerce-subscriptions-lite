<?php
/**
 * Integration tests for the history entries Lite's lifecycle flows record: one entry per
 * change, written before the flow's action fires, and a failed write that never fails the flow.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class HistoryEntriesTest extends LiteIntegrationTestCase {

	/**
	 * The actor Lite records for the current user acting on their own subscription.
	 *
	 * @param int $customer_id Customer id.
	 * @return array{role: string, user_id: int}
	 */
	private static function customer_actor( int $customer_id ): array {
		return [
			'role'    => History::ROLE_CUSTOMER,
			'user_id' => $customer_id,
		];
	}

	/**
	 * The actor Lite records for its own entries.
	 *
	 * @return array{role: string, user_id: null}
	 */
	private static function system_actor(): array {
		return [
			'role'    => History::ROLE_SYSTEM,
			'user_id' => null,
		];
	}

	/**
	 * The history types an action callback finds when `$action` fires.
	 *
	 * @param string $action Lite lifecycle action.
	 * @return \ArrayObject<string, array<int, string>> Its `types` is set when the action fires.
	 */
	private function record_types_seen_by( string $action ): \ArrayObject {
		$seen = new \ArrayObject();
		add_action(
			$action,
			function ( ContractView $contract ) use ( $seen ): void {
				$seen['types'] = $this->get_history_types( $contract->get_id() );
			}
		);

		return $seen;
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
	 * The contract's newest cycle.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function head( int $contract_id ): CycleView {
		return Contracts::get_cycles( $contract_id, 1 )[0];
	}

	/**
	 * A checkout contract with a pending cycle-2 renewal order.
	 *
	 * @return array{0: int, 1: WC_Order}
	 */
	private function seed_renewal(): array {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id, [ 'payment_method' => 'cod' ] );
		$order       = $this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => OrderStatus::PENDING,
				'cycle_status' => CycleStatus::PENDING,
			]
		);

		return [ $contract_id, $order ];
	}

	public function test_checkout_records_one_created_entry(): void {
		$customer_id = $this->create_customer();
		wp_set_current_user( $customer_id );

		$contract_id = $this->create_contract( $customer_id );

		$entries = $this->get_history( $contract_id );
		$this->assertCount( 1, $entries );
		$this->assertSame( History::TYPE_CREATED, $entries[0]->get_type() );
		$this->assertNull( $entries[0]->get_cycle_id() );
		$this->assertSame( [ 'actor' => self::customer_actor( $customer_id ) ], $entries[0]->get_payload() );
	}

	public function test_a_hold_records_a_held_entry_by_the_customer_before_its_action(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );
		$seen        = $this->record_types_seen_by( 'woocommerce_subscriptions_lite_contract_held' );
		wp_set_current_user( $customer_id );

		( new Hold() )->hold( $contract_id );

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD ], $this->get_history_types( $contract_id ) );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD ], $seen['types'], 'The action finds the entry.' );
		$held = $this->get_history( $contract_id, History::TYPE_HELD )[0];
		$this->assertNull( $held->get_cycle_id() );
		$this->assertSame( [ 'actor' => self::customer_actor( $customer_id ) ], $held->get_payload() );
	}

	public function test_a_repeated_hold_records_nothing(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'status' => 'on-hold' ] );

		( new Hold() )->hold( $contract_id );

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_reactivation_records_a_reactivated_entry_before_its_action(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'status' => 'on-hold' ] );
		$seen        = $this->record_types_seen_by( 'woocommerce_subscriptions_lite_contract_reactivated' );
		$manager_id  = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $manager_id );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-01-20 00:00:00' ) );

		$expected = [ History::TYPE_CREATED, History::TYPE_HELD, History::TYPE_REACTIVATED ];
		$this->assertSame( $expected, $this->get_history_types( $contract_id ) );
		$this->assertSame( $expected, $seen['types'] );
		$this->assertSame(
			[
				'actor' => [
					'role'    => History::ROLE_MERCHANT,
					'user_id' => $manager_id,
				],
			],
			$this->get_history( $contract_id, History::TYPE_REACTIVATED )[0]->get_payload()
		);
	}

	public function test_a_cancellation_records_a_cancelled_entry_before_its_action_and_a_repeat_records_nothing(): void {
		$contract_id = $this->create_contract( $this->create_customer() );
		$seen        = $this->record_types_seen_by( 'woocommerce_subscriptions_lite_contract_cancelled' );

		( new Cancellation() )->cancel( $contract_id );
		( new Cancellation() )->cancel( $contract_id );

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_CANCELLED ], $this->get_history_types( $contract_id ) );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_CANCELLED ], $seen['types'] );
		$this->assertSame( [ 'actor' => self::system_actor() ], $this->get_history( $contract_id, History::TYPE_CANCELLED )[0]->get_payload() );
	}

	public function test_a_cancellation_at_period_end_records_the_end_date_and_a_repeat_records_nothing(): void {
		$contract_id = $this->create_contract( $this->create_customer() );
		$seen        = $this->record_types_seen_by( 'woocommerce_subscriptions_lite_contract_pending_cancellation' );

		( new Cancellation() )->cancel_at_period_end( $contract_id );
		( new Cancellation() )->cancel_at_period_end( $contract_id );

		$expected = [ History::TYPE_CREATED, History::TYPE_CANCELLATION_SCHEDULED ];
		$this->assertSame( $expected, $this->get_history_types( $contract_id ) );
		$this->assertSame( $expected, $seen['types'] );
		$this->assertSame(
			[
				'actor'   => self::system_actor(),
				'end_gmt' => '2026-02-15 00:00:00',
			],
			$this->get_history( $contract_id, History::TYPE_CANCELLATION_SCHEDULED )[0]->get_payload()
		);
	}

	public function test_a_failed_history_write_does_not_fail_the_flow(): void {
		$contract_id = $this->create_contract( $this->create_customer() );
		$seen        = $this->record_types_seen_by( 'woocommerce_subscriptions_lite_contract_held' );
		$this->capture_log();
		$this->fail_next_query( self::history_insert() );

		$held = ( new Hold() )->hold( $contract_id );

		$this->assertInstanceOf( ContractView::class, $held );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( [ History::TYPE_CREATED ], $seen['types'], 'The action still fires.' );
		$this->assertSame( [ Package::EXTENSION_SLUG ], $this->logged_sources_of( sprintf( 'History: the held entry of contract %d could not be recorded', $contract_id ) ) );
		$this->assert_lock_released( $contract_id );
	}

	public function test_the_flow_entry_is_written_with_the_flows_lock(): void {
		$contract_id = $this->create_contract( $this->create_customer() );
		$lock_states = [];
		add_filter(
			'query',
			function ( $query ) use ( $contract_id, &$lock_states ) {
				if ( is_string( $query ) && 1 === preg_match( self::history_insert(), $query ) ) {
					$held          = HeldLocks::get( $contract_id );
					$lock_states[] = null === $held ? null : $held->get_operation();
				}
				return $query;
			}
		);

		( new Hold() )->hold( $contract_id );

		$this->assertSame( [ Hold::OPERATION ], $lock_states, 'The entry was written while the hold held its lock, which History passes to the engine.' );
	}

	public function test_a_paid_renewal_records_one_renewal_paid_entry_for_its_cycle(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$seen                    = new \ArrayObject();
		add_action(
			'woocommerce_subscriptions_lite_renewal_paid',
			function ( ContractView $contract ) use ( $seen ): void {
				$seen['types'] = $this->get_history_types( $contract->get_id() );
			}
		);

		$order->payment_complete();
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$cycle = $this->head( $contract_id );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_RENEWAL_PAID ], $this->get_history_types( $contract_id ), 'A re-delivered settlement records nothing more.' );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_RENEWAL_PAID ], $seen['types'] );
		$paid = $this->get_history( $contract_id, History::TYPE_RENEWAL_PAID )[0];
		$this->assertSame( $cycle->get_id(), $paid->get_cycle_id() );
		$this->assertSame( [ 'actor' => self::system_actor() ], $paid->get_payload() );
	}

	public function test_a_paid_entry_lost_by_a_run_is_recorded_by_the_re_delivered_settlement(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->fail_next_query( self::history_insert() );
		$order->payment_complete(); // The bound listener settles it; its entry write fails.
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( [ History::TYPE_CREATED ], $this->get_history_types( $contract_id ) );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_RENEWAL_PAID ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_failed_renewal_records_renewal_failed_then_held_before_its_action(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$seen                    = new \ArrayObject();
		add_action(
			'woocommerce_subscriptions_lite_renewal_failed',
			function ( ContractView $contract ) use ( $seen ): void {
				$seen['types'] = $this->get_history_types( $contract->get_id() );
			}
		);

		$order->update_status( OrderStatus::FAILED );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$expected = [ History::TYPE_CREATED, History::TYPE_RENEWAL_FAILED, History::TYPE_HELD ];
		$this->assertSame( $expected, $this->get_history_types( $contract_id ), 'A re-delivered failure records nothing more.' );
		$this->assertSame( $expected, $seen['types'] );
		$cycle_id = $this->head( $contract_id )->get_id();
		$failed   = $this->get_history( $contract_id, History::TYPE_RENEWAL_FAILED )[0];
		$this->assertSame( $cycle_id, $failed->get_cycle_id() );
		$this->assertSame(
			[
				'actor'    => self::system_actor(),
				'order_id' => $order->get_id(),
			],
			$failed->get_payload()
		);
		$held = $this->get_history( $contract_id, History::TYPE_HELD )[0];
		$this->assertSame( $cycle_id, $held->get_cycle_id() );
		$this->assertSame(
			[
				'actor'  => self::system_actor(),
				'reason' => Hold::REASON_RENEWAL_FAILED,
			],
			$held->get_payload()
		);
	}

	public function test_a_failed_entry_lost_by_a_run_is_recorded_by_the_re_delivered_settlement(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$this->fail_next_query( self::history_insert() );
		$order->update_status( OrderStatus::FAILED ); // The bound listener settles it; its entry write fails.
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD ], $this->get_history_types( $contract_id ) );

		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD, History::TYPE_RENEWAL_FAILED ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_run_cut_short_before_the_cycle_write_records_one_failure_on_re_delivery(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$cycles                  = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CYCLES );
		$this->fail_next_query( '/^UPDATE ' . preg_quote( $cycles, '/' ) . ' SET .*\'' . CycleStatus::FAILED . '\'/' );
		$order->update_status( OrderStatus::FAILED ); // The bound listener raises the count, then its cycle write fails.
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertSame( '1', wc_get_order( $order->get_id() )->get_meta( '_wcsl_renewal_failures' ) );

		( new Settlement() )->settle( wc_get_order( $order->get_id() ), $this->utc( '2026-02-16 12:00:00' ) );
		( new Settlement() )->settle( wc_get_order( $order->get_id() ), $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( '2', wc_get_order( $order->get_id() )->get_meta( '_wcsl_renewal_failures' ), 'The cut-short run skipped a number; the re-delivery reused none.' );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_RENEWAL_FAILED, History::TYPE_HELD ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_failure_of_a_second_order_on_the_same_cycle_records_a_second_entry(): void {
		[ $contract_id, $first ] = $this->seed_renewal();
		$first->update_status( OrderStatus::FAILED );
		( new Settlement() )->settle( $first, $this->utc( '2026-02-16 12:00:00' ) );
		$cycle = $this->head( $contract_id );

		$second = wc_create_order(
			[
				'customer_id' => $first->get_customer_id(),
				'created_via' => $first->get_created_via(),
			]
		);
		$second->set_total( '19.99' );
		$second->save();
		$this->transition_cycle_with_lock(
			$contract_id,
			$cycle->get_id(),
			[
				'from_status' => CycleStatus::FAILED,
				'to_status'   => CycleStatus::PENDING,
				'order_id'    => $second->get_id(),
			]
		);
		$second->update_status( OrderStatus::FAILED );
		( new Settlement() )->settle( $second, $this->utc( '2026-02-17 12:00:00' ) );

		$failures = $this->get_history( $contract_id, History::TYPE_RENEWAL_FAILED );
		$this->assertCount( 2, $failures );
		$this->assertSame( [ $first->get_id(), $second->get_id() ], [ $failures[0]->get_payload()['order_id'], $failures[1]->get_payload()['order_id'] ] );
		$this->assertSame( [ $cycle->get_id(), $cycle->get_id() ], [ $failures[0]->get_cycle_id(), $failures[1]->get_cycle_id() ] );
	}

	public function test_paying_a_failed_renewal_records_renewal_paid_then_reactivated_once(): void {
		[ $contract_id, $order ] = $this->seed_renewal();
		$order->update_status( OrderStatus::FAILED );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );

		$order->payment_complete(); // The bound listener settles the payment.
		( new Settlement() )->settle( wc_get_order( $order->get_id() ), $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame(
			[ History::TYPE_CREATED, History::TYPE_RENEWAL_FAILED, History::TYPE_HELD, History::TYPE_RENEWAL_PAID, History::TYPE_REACTIVATED ],
			$this->get_history_types( $contract_id ),
			'A re-delivered settlement records nothing more.'
		);
		$reactivated = $this->get_history( $contract_id, History::TYPE_REACTIVATED )[0];
		$this->assertSame( $this->head( $contract_id )->get_id(), $reactivated->get_cycle_id() );
		$this->assertSame( [ 'actor' => self::system_actor() ], $reactivated->get_payload() );
	}

	public function test_a_paid_renewal_during_a_customer_hold_records_no_reactivated_entry(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'payment_method' => 'cod',
				'status'         => 'on-hold',
			]
		);
		$order       = $this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => OrderStatus::PENDING,
				'cycle_status' => CycleStatus::PENDING,
			]
		);

		$order->payment_complete();
		( new Settlement() )->settle( wc_get_order( $order->get_id() ), $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD, History::TYPE_RENEWAL_PAID ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_paid_renewal_of_an_active_contract_records_no_reactivated_entry(): void {
		[ $contract_id, $order ] = $this->seed_renewal();

		$order->payment_complete();

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_RENEWAL_PAID ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_dead_renewal_order_records_lites_hold_for_the_failed_renewal(): void {
		[ $contract_id, $order ] = $this->seed_renewal();

		$order->update_status( OrderStatus::CANCELLED );
		( new Settlement() )->settle( $order, $this->utc( '2026-02-16 12:00:00' ) );

		$this->assertSame( [ History::TYPE_CREATED, History::TYPE_HELD ], $this->get_history_types( $contract_id ) );
		$this->assertSame( Hold::REASON_RENEWAL_FAILED, $this->get_history( $contract_id, History::TYPE_HELD )[0]->get_payload()['reason'] );
	}
}
