<?php
/**
 * End-to-end renewal scenarios driven through the engine's due dispatcher: Lite's due
 * handler, processor and settlement on real orders, with `cod` standing in for a gateway
 * that accepts engine charges.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\CustomerActionRules;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\LifecycleNotAllowedException;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\UnpaidRenewal;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * @coversNothing
 */
final class RenewalScenariosTest extends LiteIntegrationTestCase {

	/**
	 * When a contract that is due now was paid. Plain day arithmetic: 33 days back plus one
	 * month lands 2 to 5 days ago in every month, where `-1 month -2 days` overflows near
	 * month ends (on 1, 2 and 31 March its due moment is in the future).
	 */
	private const PAID_A_PERIOD_AGO = '-33 days';

	/**
	 * The wall clock (UTC). Settlement from order status hooks reads it, so the scenarios
	 * dispatch on it too.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * An active monthly contract paid `$paid_ago` before midnight today (UTC).
	 *
	 * @param string               $paid_ago Relative modifier in days, e.g. {@see self::PAID_A_PERIOD_AGO}.
	 * @param array<string, mixed> $args     Extra {@see self::create_contract()} args.
	 */
	private function make_contract_paid( string $paid_ago, array $args = [] ): int {
		$paid_at = ( new DateTimeImmutable( 'today ' . $paid_ago, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );

		return $this->create_contract(
			$this->create_customer(),
			$args + [
				'payment_method' => 'cod',
				'date_paid'      => $paid_at,
			]
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
	 * Lite renewal orders.
	 *
	 * @return array<int, WC_Order>
	 */
	private function renewal_orders(): array {
		$orders = wc_get_orders(
			[
				'created_via' => RenewalOrderBuilder::CREATED_VIA,
				'status'      => 'any',
				'limit'       => -1,
			]
		);

		return is_array( $orders ) ? array_values( $orders ) : [];
	}

	/**
	 * The contract's next payment as a moment.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function next_payment( int $contract_id ): DateTimeImmutable {
		$next = $this->get_contract( $contract_id )->get_next_payment_gmt();
		$this->assertNotNull( $next );

		return new DateTimeImmutable( $next, new DateTimeZone( 'UTC' ) );
	}

	public function test_a_reactivated_past_due_contract_is_charged_once_across_two_dispatches(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );
		( new Hold() )->hold( $contract_id );
		( new Reactivation() )->reactivate( $contract_id );
		$due_at = $this->next_payment( $contract_id );
		$this->assertGreaterThan( $this->now(), $due_at, 'Reactivation rolls the past-due anchor forward.' );

		$scan_at = $due_at->modify( '+1 hour' );
		$this->assertSame( 1, $this->run_due_dispatch( $scan_at ) );
		$this->assertSame( 0, $this->run_due_dispatch( $scan_at->modify( '+1 second' ) ) );

		$this->assertSame( 1, $this->charge_count() );
		$this->assertCount( 1, $this->renewal_orders() );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( $this->head( $contract_id )->get_ends_at_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The next period starts where the billed one ends.' );
		$this->assertGreaterThan( $scan_at, $this->next_payment( $contract_id ) );
	}

	public function test_a_contract_several_periods_behind_is_billed_once_with_the_next_due_in_the_future(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract_paid( '-160 days' );
		$now         = $this->now();

		$this->assertSame( 1, $this->run_due_dispatch( $now ) );

		$this->assertSame( 1, $this->charge_count() );
		$cycle = $this->head( $contract_id );
		$this->assertSame( 2, $cycle->get_count(), 'One period is billed, not one per missed period.' );
		$this->assertSame( CycleStatus::BILLED, $cycle->get_status() );
		$this->assertLessThanOrEqual( $now, new DateTimeImmutable( $cycle->get_starts_at_gmt(), new DateTimeZone( 'UTC' ) ) );
		$this->assertGreaterThan( $now, new DateTimeImmutable( $cycle->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ) );
		$this->assertGreaterThan( $now, $this->next_payment( $contract_id ) );
		$this->assertSame( 0, $this->run_due_dispatch( $now->modify( '+1 second' ) ) );
	}

	public function test_overlapping_ticks_notify_once_and_an_async_charge_leaves_the_due_scan(): void {
		$this->support_engine_charges( 'cod', 'on-hold' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );
		$now         = $this->now();

		$this->assertSame( 1, $this->run_due_dispatch( $now ) );
		$this->assertSame( 0, $this->run_due_dispatch( $now ), 'An overlapping tick loses the claim.' );
		$this->assertCount( 1, $this->renewal_orders() );
		$this->assertSame( CycleStatus::PROCESSING, $this->head( $contract_id )->get_status() );
		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The async charge takes the contract off the due scan.' );

		$this->assertSame( 0, $this->run_due_dispatch( $now->modify( '+901 seconds' ) ), 'Not notified again once the claim expires.' );
		$this->assertSame( 1, $this->charge_count() );

		$this->renewal_orders()[0]->payment_complete();

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( $this->head( $contract_id )->get_ends_at_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The confirmed payment arms the next period.' );
	}

	public function test_a_failed_charge_holds_the_contract_until_the_customer_pays_the_order(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );

		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$orders = $this->renewal_orders();
		$this->assertCount( 1, $orders );
		$this->assertTrue( $orders[0]->needs_payment(), 'The failed order is payable on order-pay.' );

		$orders[0]->payment_complete();

		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( $this->head( $contract_id )->get_ends_at_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The next period starts where the paid one ends.' );
		$this->assertGreaterThan( $this->now(), $this->next_payment( $contract_id ) );
	}

	/**
	 * Make the next contract write of `next_payment_gmt` throw once, as if the request died
	 * between the cycle settlement and the contract write that follows it.
	 */
	private function interrupt_next_schedule_write(): void {
		$contracts_table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$interrupted     = false;
		add_filter(
			'query',
			static function ( $query ) use ( $contracts_table, &$interrupted ) {
				if ( ! $interrupted && is_string( $query ) && 0 === strpos( $query, "UPDATE `{$contracts_table}`" ) && false !== strpos( $query, 'next_payment_gmt' ) ) {
					$interrupted = true;
					throw new RuntimeException( 'The request died.' );
				}
				return $query;
			}
		);
	}

	public function test_a_paid_settlement_cut_short_is_completed_by_the_next_dispatch_without_a_second_charge(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );
		$due_before  = $this->next_payment( $contract_id );
		$now         = $this->now();
		$this->interrupt_next_schedule_write();

		$this->assertSame( 1, $this->run_due_dispatch( $now ) );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertEquals( $due_before, $this->next_payment( $contract_id ), 'The contract write was lost.' );

		$this->assertSame( 1, $this->run_due_dispatch( $now->modify( '+901 seconds' ) ) );

		$this->assertSame( 1, $this->charge_count() );
		$this->assertCount( 1, $this->renewal_orders() );
		$cycle = $this->head( $contract_id );
		$this->assertSame( 2, $cycle->get_count() );
		$this->assertSame( $cycle->get_ends_at_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertGreaterThan( $now, $this->next_payment( $contract_id ) );
	}

	public function test_a_failed_settlement_cut_short_holds_the_contract_on_the_next_dispatch(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );
		$now         = $this->now();
		$this->interrupt_next_schedule_write();

		$this->assertSame( 1, $this->run_due_dispatch( $now ) );
		$this->assertSame( CycleStatus::FAILED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status(), 'The contract write was lost.' );

		$this->assertSame( 1, $this->run_due_dispatch( $now->modify( '+901 seconds' ) ) );

		$this->assertSame( 1, $this->charge_count() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
	}

	public function test_a_customer_hold_survives_a_late_async_payment(): void {
		$this->support_engine_charges( 'cod', 'on-hold' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );
		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );
		$cycle_end = $this->head( $contract_id )->get_ends_at_gmt();

		( new Hold() )->hold( $contract_id );
		$this->renewal_orders()[0]->payment_complete();

		$contract = $this->get_contract( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status(), 'The late payment does not undo the customer hold.' );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertSame( $cycle_end, HoldAnchor::read( $contract_id ) );

		( new Reactivation() )->reactivate( $contract_id );

		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( $cycle_end, $this->get_contract( $contract_id )->get_next_payment_gmt(), 'Billing resumes after the paid period.' );
	}

	public function test_a_gateway_without_engine_charges_gets_a_manual_renewal_order(): void {
		$this->support_engine_charges( 'cod' );
		$this->spy_charge_attempts( 'bacs' );
		$contract_id = $this->make_contract_paid(
			self::PAID_A_PERIOD_AGO,
			[
				'payment_method'       => 'bacs',
				'payment_method_title' => 'Direct bank transfer',
			]
		);

		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );

		$this->assertSame( 0, $this->charge_count() );
		$orders = $this->renewal_orders();
		$this->assertCount( 1, $orders );
		$this->assertSame( 'pending', $orders[0]->get_status() );
		$this->assertSame( 'bacs', $orders[0]->get_payment_method() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
	}

	public function test_a_failed_renewal_blocks_reactivation(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->make_contract_paid( self::PAID_A_PERIOD_AGO );
		$this->run_due_dispatch( $this->now() );
		$contract = $this->get_contract( $contract_id );

		$this->assertNotNull( UnpaidRenewal::find( $contract ) );
		$this->assertFalse( CustomerActionRules::can_reactivate( $contract->get_status(), null !== UnpaidRenewal::find( $contract ) ) );
		$this->assertTrue( CustomerActionRules::needs_payment( $contract->get_status(), null !== UnpaidRenewal::find( $contract ) ) );

		$this->expectException( LifecycleNotAllowedException::class );
		( new Reactivation() )->reactivate( $contract_id );
	}

	public function test_a_bogo_plan_renewal_grants_bonus_units(): void {
		$this->support_engine_charges( 'cod' );
		$plan  = $this->make_plan( 'month', 1, null, [ 'pricing_policy' => [ 'policies' => [ [ 'type' => 'bogo' ] ] ] ] );
		$order = $this->create_subscription_order(
			$this->create_customer(),
			[
				'quantity'       => 2,
				'payment_method' => 'cod',
				'date_paid'      => ( new DateTimeImmutable( 'today ' . self::PAID_A_PERIOD_AGO, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' ),
			]
		);
		$this->stamp_plan( $order, $plan );
		$this->assertNotNull( ( new ContractCreationHandler() )->create_contract( $order, $plan ) );

		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );

		$orders = $this->renewal_orders();
		$this->assertCount( 1, $orders );
		$items = array_values( $orders[0]->get_items( 'line_item' ) );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $items[0] );
		$this->assertSame( 4, $items[0]->get_quantity(), '2 paid units earn 2 bonus units.' );
		$this->assertTrue( $orders[0]->is_paid() );
	}
}
