<?php
/**
 * Integration tests for RenewalProcessor, charging through the engine broker on `cod`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor
 */
final class RenewalProcessorTest extends LiteIntegrationTestCase {

	/**
	 * When the test contracts were paid: 33 days ago (midnight UTC), so they are due now on
	 * the wall clock that settlement from order status hooks reads (plain day arithmetic:
	 * plus one month lands 2 to 5 days ago in every month, with no month-end overflow).
	 *
	 * @var string
	 */
	private $paid_at;

	/**
	 * The due moment of the test contracts (paid + one month).
	 *
	 * @var string
	 */
	private $due_at;

	/**
	 * The end of the period a renewal at the due moment bills (due + one month).
	 *
	 * @var string
	 */
	private $period_end;

	public function set_up(): void {
		parent::set_up();
		$paid             = new DateTimeImmutable( 'today -33 days', new DateTimeZone( 'UTC' ) );
		$due              = $paid->modify( '+1 month' );
		$this->paid_at    = $paid->format( 'Y-m-d H:i:s' );
		$this->due_at     = $due->format( 'Y-m-d H:i:s' );
		$this->period_end = $due->modify( '+1 month' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A moment relative to the wall clock.
	 *
	 * @param string $modifier Relative modifier, e.g. `+1 hour`.
	 */
	private function from_now( string $modifier ): string {
		return ( new DateTimeImmutable( 'now ' . $modifier, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
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
	 * An active monthly `cod` contract due at {@see self::$due_at}.
	 *
	 * @param array<string, mixed> $args Extra {@see self::create_contract()} args.
	 */
	private function make_contract( array $args = [] ): int {
		return $this->create_contract(
			$this->create_customer(),
			$args + [
				'payment_method' => 'cod',
				'date_paid'      => $this->paid_at,
			]
		);
	}

	/**
	 * Run a scheduled renewal at `$now`.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $now         GMT now; the wall clock when empty.
	 * @param bool   $manual      Manual run.
	 */
	private function renew( int $contract_id, string $now = '', bool $manual = false ): ?WC_Order {
		$run_at = '' === $now ? $this->from_now( '' ) : $now;

		// As the due dispatcher runs it: under the contract lock, the contract read after acquiring.
		return HeldLocks::run(
			$contract_id,
			'due',
			function () use ( $contract_id, $run_at, $manual ): ?WC_Order {
				return ( new RenewalProcessor() )->renew( $this->get_contract( $contract_id ), $this->utc( $this->due_at ), $this->utc( $run_at ), $manual );
			}
		);
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
	 * Lite renewal order ids.
	 *
	 * @return array<int, int>
	 */
	private function renewal_order_ids(): array {
		return array_map(
			'intval',
			wc_get_orders(
				[
					'created_via' => RenewalOrderBuilder::CREATED_VIA,
					'status'      => 'any',
					'return'      => 'ids',
					'limit'       => -1,
				]
			)
		);
	}

	public function test_a_due_contract_is_renewed_charged_and_armed_for_the_next_period(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->charge_count() );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$cycle = $this->head( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $cycle->get_status() );
		$this->assertSame( 2, $cycle->get_count() );
		$this->assertSame( $order->get_id(), $cycle->get_order_id() );
		$this->assertSame( $this->due_at, $cycle->get_starts_at_gmt() );
		$this->assertSame( $this->period_end, $cycle->get_ends_at_gmt() );
		$this->assertSame( $this->period_end, $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_failed_charge_fails_the_cycle_and_holds_the_contract(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
	}

	public function test_an_async_charge_leaves_the_cycle_processing_and_is_not_charged_again(): void {
		$this->support_engine_charges( 'cod', 'on-hold' );
		$contract_id = $this->make_contract();

		$this->assertInstanceOf( WC_Order::class, $this->renew( $contract_id ) );
		$this->assertSame( CycleStatus::PROCESSING, $this->head( $contract_id )->get_status() );

		$this->assertNull( $this->renew( $contract_id, $this->from_now( '+1 hour' ) ) );
		$this->assertSame( 1, $this->charge_count() );
		$this->assertCount( 1, $this->renewal_order_ids() );
	}

	/**
	 * @dataProvider provide_charges_without_an_outcome
	 *
	 * @param string $outcome Stub charge outcome.
	 * @param string $note    The fragment that tells this case's order note apart.
	 */
	public function test_a_charge_that_leaves_the_order_pending_holds_the_contract_until_it_is_paid( string $outcome, string $note ): void {
		$this->support_engine_charges( 'cod', $outcome );
		$contract_id = $this->make_contract();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->charge_count() );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status(), 'Lite leaves the order status to the gateway.' );
		$this->assertTrue( $reloaded->needs_payment(), 'The customer can still pay the order.' );
		$this->assertCount(
			1,
			wc_get_order_notes(
				[
					'order_id' => $order->get_id(),
					'search'   => $note,
				]
			)
		);
		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::PENDING, $head->get_status() );
		$this->assertSame( $order->get_id(), $head->get_order_id() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt(), 'No due moment, so the order is not charged again.' );

		$reloaded->payment_complete();

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$resumed = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $resumed->get_status(), 'Paying the order resumes the contract.' );
		$this->assertNotNull( $resumed->get_next_payment_gmt() );
	}

	/**
	 * Charges that report no outcome.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_charges_without_an_outcome(): array {
		return [
			'the gateway does nothing' => [ 'none', 'did not report an outcome' ],
			'the gateway throws'       => [ 'throw', 'reported an error' ],
		];
	}

	public function test_renew_now_recovers_a_renewal_whose_order_could_not_be_built(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		$product_id  = (int) ( $this->get_contract( $contract_id )->get_items()[0]['product_id'] ?? 0 );
		$this->assertGreaterThan( 0, $product_id );
		// The product is not a product while the renewal runs, so the order line cannot be built.
		set_post_type( $product_id, 'post' );

		$this->assertNull( $this->renew( $contract_id ) );
		$this->assertSame( RenewalProcessor::REASON_ORDER_BUILD_FAILED, $this->head( $contract_id )->get_reason() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );

		set_post_type( $product_id, 'product' );
		$result = ( new RowActionController( null, static fn (): bool => true ) )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertNotNull( $head->get_order_id() );
		$this->assertSame( [ $head->get_order_id() ], $this->charged_order_ids );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_renew_now_while_another_operation_holds_the_lock_writes_nothing(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		$this->hold_lock( $contract_id, 'due' );

		try {
			( new RenewalProcessor() )->renew_now( $contract_id );
			$this->fail( 'Expected ContractLockedException.' );
		} catch ( ContractLockedException $e ) {
			$this->assertSame( 'due', $e->get_operation() );
		}

		$this->assertSame( 0, $this->charge_count() );
		$this->assertSame( [], $this->renewal_order_ids() );
		$this->assertCount( 1, Contracts::get_cycles( $contract_id ), 'No cycle appended.' );
	}

	public function test_renew_without_a_held_lock_is_a_programming_error(): void {
		$contract_id = $this->make_contract();

		$this->expectException( LogicException::class );

		( new RenewalProcessor() )->renew( $this->get_contract( $contract_id ), $this->utc( $this->due_at ), $this->utc( $this->from_now( '' ) ), false );
	}

	public function test_a_lost_order_link_deletes_the_draft_and_charges_nothing(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		$this->capture_log();
		// Code reached under the run's lock moves the cycle while the run builds its order.
		$draft_id = 0;
		add_action(
			'woocommerce_after_order_object_save',
			static function ( $order ) use ( $contract_id, &$draft_id ): void {
				if ( $order instanceof WC_Order && RenewalOrderBuilder::CREATED_VIA === $order->get_created_via() ) {
					$draft_id = $order->get_id();
					$head     = Contracts::get_cycles( $contract_id, 1 )[0];
					Contracts::transition_cycle(
						$head->get_id(),
						[
							'from_status' => CycleStatus::PENDING,
							'to_status'   => CycleStatus::CANCELLED,
						],
						HeldLocks::get( $contract_id )
					);
				}
			}
		);

		$this->assertNull( $this->renew( $contract_id ) );

		$this->assertGreaterThan( 0, $draft_id );
		$this->assertFalse( wc_get_order( $draft_id ), 'The draft that lost the link is deleted.' );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertSame( CycleStatus::CANCELLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status(), 'A lost link is not a failed build: no hold.' );
		$this->assertFalse( $this->has_logged( 'could not be built' ) );
	}

	public function test_a_concurrent_append_of_the_same_cycle_leaves_the_renewal_to_the_other_run(): void {
		global $wpdb;

		$this->support_engine_charges( 'cod' );
		$contract_id  = $this->make_contract();
		$cycles_table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CYCLES );
		$raced        = false;
		// A writer under the same lock appends cycle 2 between this run's head read and its own append.
		add_filter(
			'query',
			function ( $query ) use ( $contract_id, $cycles_table, &$raced ) {
				if ( ! $raced && is_string( $query ) && 0 === strpos( $query, "INSERT INTO `{$cycles_table}`" ) ) {
					$raced = true;
					Contracts::add_cycle(
						$contract_id,
						[
							'status'         => CycleStatus::PENDING,
							'count'          => 2,
							'starts_at_gmt'  => $this->due_at,
							'ends_at_gmt'    => $this->period_end,
							'expected_total' => '19.99',
							'currency'       => 'USD',
						],
						HeldLocks::get( $contract_id )
					);
				}
				return $query;
			}
		);
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$this->assertNull( $this->renew( $contract_id ) );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		$this->assertTrue( $raced );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertSame( [], $this->renewal_order_ids() );
		$this->assertCount( 2, Contracts::get_cycles( $contract_id ), 'Only the other run appended.' );
	}

	public function test_a_pending_cycle_without_an_order_is_resumed_by_the_next_lock_holder(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		$cycle       = Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => $this->due_at,
				'ends_at_gmt'    => $this->period_end,
				'expected_total' => '19.99',
				'currency'       => 'USD',
			]
		);
		// The run that appended it died before building the order; its lock ran out.

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$head = $this->head( $contract_id );
		$this->assertSame( $cycle->get_id(), $head->get_id(), 'The interrupted cycle is resumed, not appended again.' );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertSame( $order->get_id(), $head->get_order_id() );
		$this->assertSame( [ $order->get_id() ], $this->charged_order_ids );
	}

	public function test_an_interrupted_renewal_resumes_its_order_without_building_another(): void {
		$this->support_engine_charges( 'cod' );
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'payment_method' => 'cod',
				'date_paid'      => $this->paid_at,
			]
		);
		$existing    = $this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => 'pending',
				'cycle_status' => CycleStatus::PENDING,
			]
		);
		$existing->set_payment_method( 'cod' );
		$existing->save();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $existing->get_id(), $order->get_id() );
		$this->assertSame( [ $existing->get_id() ], $this->charged_order_ids );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertCount( 1, $this->renewal_order_ids() );
	}

	public function test_a_scheduled_run_leaves_a_failed_renewal_alone(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract();
		$this->renew( $contract_id );

		$this->assertNull( $this->renew( $contract_id, $this->from_now( '+1 hour' ) ) );
		$this->assertSame( 1, $this->charge_count() );
	}

	public function test_a_manual_retry_of_a_failed_renewal_charges_the_same_order(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract();
		$failed      = $this->renew( $contract_id );
		$this->assertInstanceOf( WC_Order::class, $failed );
		$this->support_engine_charges( 'cod', 'paid' );

		$order = $this->renew( $contract_id, $this->from_now( '+1 day' ), true );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $failed->get_id(), $order->get_id() );
		$this->assertSame( [ $failed->get_id(), $failed->get_id() ], $this->charged_order_ids );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( $this->period_end, $contract->get_next_payment_gmt() );
	}

	public function test_a_repeat_failure_on_a_retry_fails_the_cycle_again(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract();
		$this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $this->renew( $contract_id, $this->from_now( '+1 day' ), true ) );

		$this->assertSame( 2, $this->charge_count() );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
	}

	public function test_a_renewal_order_that_cannot_be_built_fails_the_renewal_and_holds_the_contract(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		$this->capture_log();
		// WooCommerce does not know the contract's currency, so setting it on the order throws.
		Contracts::update( $contract_id, [ 'currency' => 'ZZZ' ] );
		$draft_id = 0;
		add_action(
			'woocommerce_after_order_object_save',
			static function ( $order ) use ( &$draft_id ): void {
				if ( $order instanceof WC_Order && RenewalOrderBuilder::CREATED_VIA === $order->get_created_via() ) {
					$draft_id = $order->get_id();
				}
			}
		);

		$this->assertNull( $this->renew( $contract_id ) );

		$this->assertGreaterThan( 0, $draft_id );
		$this->assertFalse( wc_get_order( $draft_id ), 'The draft is deleted.' );
		$this->assertSame( 0, $this->charge_count() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertTrue( $this->has_logged( 'could not be built' ) );
		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::FAILED, $head->get_status() );
		$this->assertSame( RenewalProcessor::REASON_ORDER_BUILD_FAILED, $head->get_reason() );
		$this->assertNull( $head->get_order_id() );
		$this->assertTrue( RenewalProcessor::can_renew_now( $contract ), 'Renew now is offered to recover it.' );

		$result = ( new RowActionController( null, static fn (): bool => true ) )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type(), 'Renew now reports the build failure, not "not renewable".' );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_contract_without_a_plan_is_parked(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		Contracts::update( $contract_id, [ 'selling_plan_id' => 999999 ] );
		$this->capture_log();

		$this->assertNull( $this->renew( $contract_id ) );

		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( 1, $this->head( $contract_id )->get_count() );
		$this->assertTrue( $this->has_logged( 'no billing policy' ) );
	}

	public function test_a_free_renewal_is_completed_without_a_charge(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		Contracts::update( $contract_id, [ 'billing_total' => '0' ] );

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( $this->period_end, $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_a_gateway_without_engine_charges_gets_a_manual_renewal_order(): void {
		$this->spy_charge_attempts( 'cod' );
		$contract_id = $this->make_contract();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertTrue( $reloaded->needs_payment() );
		$this->assertSame( 0, $this->charge_count() );
		$cycle = $this->head( $contract_id );
		$this->assertSame( CycleStatus::PENDING, $cycle->get_status() );
		$this->assertSame( $order->get_id(), $cycle->get_order_id() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );

		Payments::process_scheduled_payment( $reloaded );
		$this->assertSame( [ $order->get_id() ], $this->charged_order_ids, 'The spy would have seen a charge.' );
	}

	public function test_an_engine_gateway_without_the_renewal_feature_gets_a_manual_renewal_order(): void {
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function ( $supported, $feature, $gateway ) {
				return Payments::GATEWAY_FEATURE === $feature && is_object( $gateway ) && 'cod' === $gateway->id ? true : $supported;
			},
			10,
			3
		);
		$charges = 0;
		add_action(
			'woocommerce_subscriptions_engine_scheduled_payment_cod',
			static function () use ( &$charges ): void {
				++$charges;
			}
		);
		$contract_id = $this->make_contract();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 0, $charges, 'The gateway supports the engine but not renewal payments.' );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_renew_now_applies_to_active_lite_contracts(): void {
		$this->assertTrue( RenewalProcessor::can_renew_now( $this->get_contract( $this->seed_contract() ) ) );
		$this->assertFalse( RenewalProcessor::can_renew_now( $this->get_contract( $this->seed_contract( [ 'extension_slug' => 'another-extension' ] ) ) ), 'Lite renews only its own contracts.' );
	}

	public function test_renew_now_excludes_drafts_terminal_statuses_and_a_plain_hold(): void {
		foreach ( [ ContractStatus::DRAFT, ContractStatus::CANCELLED, ContractStatus::EXPIRED, ContractStatus::PENDING_CANCELLATION, ContractStatus::ON_HOLD ] as $status ) {
			$this->assertFalse( RenewalProcessor::can_renew_now( $this->get_contract( $this->seed_contract( [ 'status' => $status ] ) ) ), $status );
		}
	}

	public function test_renew_now_applies_to_a_hold_with_an_unpaid_renewal(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id, [ 'status' => 'on-hold' ] );
		$this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => 'failed',
				'cycle_status' => CycleStatus::FAILED,
			]
		);

		$this->assertTrue( RenewalProcessor::can_renew_now( $this->get_contract( $contract_id ) ) );
	}

	public function test_renew_now_renews_an_active_contract(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();

		$order = ( new RenewalProcessor() )->renew_now( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( 2, $this->head( $contract_id )->get_count() );
	}

	public function test_renew_now_refuses_a_customer_hold_and_another_extensions_contract(): void {
		$this->support_engine_charges( 'cod' );
		$held    = $this->make_contract( [ 'status' => 'on-hold' ] );
		$foreign = $this->seed_contract( [ 'extension_slug' => 'another-extension' ] );

		$this->assertNull( ( new RenewalProcessor() )->renew_now( $held ) );
		$this->assertNull( ( new RenewalProcessor() )->renew_now( $foreign ) );
		$this->assertNull( ( new RenewalProcessor() )->renew_now( 4242424 ) );
		$this->assertSame( 0, $this->charge_count() );
	}

	public function test_renew_now_retries_an_unpaid_renewal_on_hold(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract();
		$failed      = $this->renew( $contract_id );
		$this->assertInstanceOf( WC_Order::class, $failed );
		$this->support_engine_charges( 'cod', 'paid' );

		$order = ( new RenewalProcessor() )->renew_now( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $failed->get_id(), $order->get_id() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_charge_settled_by_the_gateway_status_change_uses_the_held_lock(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		$this->capture_log();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( $this->period_end, $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertFalse( as_has_scheduled_action( Settlement::RETRY_HOOK, [ 'order_id' => $order->get_id() ], Settlement::RETRY_GROUP ), 'No settlement retry.' );
		$this->assertFalse( $this->has_logged( 'Settlement: settling renewal order' ) );
		$this->assertNull( $this->get_contract( $contract_id )->get_lock_state() );
	}

	/**
	 * A run charged the order and died before settling; the next lock holder resumes the
	 * pending head, finds its order paid and settles without a second charge.
	 */
	public function test_a_resumed_pending_head_with_a_paid_order_settles_without_charging(): void {
		$this->support_engine_charges( 'cod' );
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'payment_method' => 'cod',
				'date_paid'      => $this->paid_at,
			]
		);
		$this->unhook_settlement();
		$paid = $this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => 'processing',
				'cycle_status' => CycleStatus::PENDING,
			]
		);
		$this->assertTrue( $paid->is_paid() );

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $paid->get_id(), $order->get_id() );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertCount( 1, $this->renewal_order_ids() );
	}

	/**
	 * Under the lock the cycle cannot move, so a lost compare-and-set in leave_for_payment()
	 * is an anomaly: logged, and the contract is not held.
	 */
	public function test_a_lost_pending_check_when_leaving_an_order_for_payment_holds_nothing(): void {
		$this->spy_charge_attempts( 'cod' );
		$contract_id = $this->make_contract();
		$this->capture_log();
		add_action(
			'woocommerce_order_note_added',
			static function ( $note_id ) use ( $contract_id ): void {
				$note = get_comment( (int) $note_id );
				$head = Contracts::get_cycles( $contract_id, 1 )[0];
				if ( $note instanceof \WP_Comment && false !== strpos( $note->comment_content, 'manual payment' ) && CycleStatus::PENDING === $head->get_status() ) {
					Contracts::transition_cycle(
						$head->get_id(),
						[
							'from_status' => CycleStatus::PENDING,
							'to_status'   => CycleStatus::CANCELLED,
						],
						HeldLocks::get( $contract_id )
					);
				}
			}
		);

		$this->assertInstanceOf( WC_Order::class, $this->renew( $contract_id ) );

		$this->assertTrue( $this->has_logged( 'is no longer pending on order' ) );
		$this->assertSame( CycleStatus::CANCELLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status(), 'Not held.' );
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
}
