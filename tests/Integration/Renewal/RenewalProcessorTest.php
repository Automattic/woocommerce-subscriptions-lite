<?php
/**
 * Integration tests for RenewalProcessor, charging through the engine broker on `cod`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalProcessor;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalProcessor
 */
final class RenewalProcessorTest extends LiteIntegrationTestCase {

	/**
	 * When the test contracts were paid: a month and two days ago (midnight UTC), so they
	 * are due now on the wall clock that settlement from order status hooks reads.
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
		$paid             = new DateTimeImmutable( 'today -1 month -2 days', new DateTimeZone( 'UTC' ) );
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

		return ( new RenewalProcessor() )->renew( $this->get_contract( $contract_id ), $this->utc( $this->due_at ), $this->utc( $run_at ), $manual );
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
	 */
	public function test_a_charge_that_leaves_the_order_pending_is_a_failed_charge( string $outcome ): void {
		$this->support_engine_charges( 'cod', $outcome );
		$contract_id = $this->make_contract();

		$order = $this->renew( $contract_id );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $this->charge_count() );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assertTrue( $reloaded->needs_payment(), 'The customer can still pay the order.' );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
	}

	/**
	 * Charges that report no outcome.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_charges_without_an_outcome(): array {
		return [
			'the gateway does nothing' => [ 'none' ],
			'the gateway throws'       => [ 'throw' ],
		];
	}

	public function test_a_cycle_held_by_another_run_is_left_alone(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract();
		Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => $this->due_at,
				'ends_at_gmt'    => $this->period_end,
				'expected_total' => '19.99',
				'currency'       => 'USD',
				'lease_seconds'  => 900,
			]
		);

		$this->assertNull( $this->renew( $contract_id ) );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertSame( [], $this->renewal_order_ids() );
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
}
