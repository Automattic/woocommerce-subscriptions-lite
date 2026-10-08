<?php
/**
 * Integration tests for Cancellation: cancel now (-> cancelled, next-due moment and hold
 * anchor cleared) and cancel at period end (-> pending-cancellation, end date stamped).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\LifecycleNotAllowed;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation
 */
final class CancellationTest extends LiteIntegrationTestCase {

	/**
	 * The stored hold anchor ('' when absent).
	 *
	 * @param int $contract_id Contract id.
	 * @return mixed
	 */
	private function get_stored_anchor( int $contract_id ) {
		return Contracts::get_meta( $contract_id, HoldAnchor::META_KEY, true );
	}

	/**
	 * Capture the views `$action` receives from now on.
	 *
	 * @param string $action Action name.
	 * @return \ArrayObject<int, ContractView> The views the action receives.
	 */
	private function capture_action( string $action ): \ArrayObject {
		$received = new \ArrayObject();
		add_action(
			$action,
			static function ( ContractView $contract ) use ( $received ): void {
				$received[] = $contract;
			}
		);

		return $received;
	}

	public function test_cancel_at_period_end_winds_down_and_stamps_the_end_date(): void {
		$contract_id = $this->seed_contract();

		$wound_down = ( new Cancellation() )->cancel_at_period_end( $contract_id );

		$this->assertInstanceOf( ContractView::class, $wound_down );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $wound_down->get_status() );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $stored->get_status() );
		// The next-due moment becomes the "cancels on" date and is disarmed.
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_end_gmt() );
		$this->assertNull( $stored->get_next_payment_gmt() );
	}

	public function test_cancel_at_period_end_preserves_an_existing_end_date(): void {
		$contract_id = $this->seed_contract( [ 'end_gmt' => '2026-09-09 00:00:00' ] );

		( new Cancellation() )->cancel_at_period_end( $contract_id );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( '2026-09-09 00:00:00', $stored->get_end_gmt() );
		$this->assertNull( $stored->get_next_payment_gmt() );
	}

	public function test_cancel_at_period_end_from_on_hold_stamps_the_end_from_the_hold_anchor(): void {
		$contract_id = $this->seed_contract();
		( new Hold() )->hold( $contract_id );

		( new Cancellation() )->cancel_at_period_end( $contract_id );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $stored->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_end_gmt() );
		$this->assertNull( $stored->get_next_payment_gmt() );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_cancel_at_period_end_on_a_pending_cancellation_contract_is_a_no_op(): void {
		$contract_id = $this->seed_contract();
		( new Cancellation() )->cancel_at_period_end( $contract_id );
		$received = $this->capture_action( 'woocommerce_subscriptions_lite_contract_pending_cancellation' );

		$wound_down = ( new Cancellation() )->cancel_at_period_end( $contract_id );

		$this->assertInstanceOf( ContractView::class, $wound_down );
		$this->assertCount( 1, $received );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $stored->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_end_gmt() );
	}

	public function test_cancel_at_period_end_fires_the_pending_cancellation_action(): void {
		$contract_id = $this->seed_contract();
		$received    = $this->capture_action( 'woocommerce_subscriptions_lite_contract_pending_cancellation' );

		( new Cancellation() )->cancel_at_period_end( $contract_id );

		$this->assertCount( 1, $received );
		$this->assertSame( $contract_id, $received[0]->get_id() );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $received[0]->get_status() );
	}

	public function test_cancel_at_period_end_ignores_a_malformed_hold_anchor(): void {
		$contract_id = $this->seed_contract( [ 'status' => ContractStatus::ON_HOLD ] );
		Contracts::update_meta( $contract_id, HoldAnchor::META_KEY, 'not-a-date' );

		( new Cancellation() )->cancel_at_period_end( $contract_id );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $stored->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_end_gmt(), 'The stored next payment, not the malformed anchor, is the period end.' );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_cancel_at_period_end_from_on_hold_without_a_date_leaves_no_end(): void {
		$contract_id = $this->seed_contract(
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		Contracts::update_meta( $contract_id, HoldAnchor::META_KEY, 'not-a-date' );

		( new Cancellation() )->cancel_at_period_end( $contract_id );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $stored->get_status() );
		$this->assertNull( $stored->get_end_gmt(), 'A malformed anchor is never written as the end date.' );
	}

	/**
	 * @dataProvider provide_period_end_rejected_statuses
	 *
	 * @param string $status Stored status cancel at period end must reject.
	 */
	public function test_cancel_at_period_end_rejects_other_statuses( string $status ): void {
		$contract_id = $this->seed_contract( [ 'status' => $status ] );
		$received    = $this->capture_action( 'woocommerce_subscriptions_lite_contract_pending_cancellation' );

		try {
			( new Cancellation() )->cancel_at_period_end( $contract_id );
			$this->fail( 'Expected LifecycleNotAllowed.' );
		} catch ( LifecycleNotAllowed $e ) {
			$stored = $this->get_contract( $contract_id );
			$this->assertSame( $status, $stored->get_status() );
			$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt(), 'Nothing was written.' );
			$this->assertCount( 0, $received, 'The action does not fire.' );
		}
	}

	/**
	 * Statuses cancel at period end rejects.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_period_end_rejected_statuses(): array {
		return [
			'draft'     => [ ContractStatus::DRAFT ],
			'cancelled' => [ ContractStatus::CANCELLED ],
			'expired'   => [ ContractStatus::EXPIRED ],
		];
	}

	public function test_cancel_clears_the_next_payment(): void {
		$contract_id = $this->seed_contract();

		$cancelled = ( new Cancellation() )->cancel( $contract_id );

		$this->assertInstanceOf( ContractView::class, $cancelled );
		$this->assertSame( ContractStatus::CANCELLED, $cancelled->get_status() );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::CANCELLED, $stored->get_status() );
		$this->assertNull( $stored->get_next_payment_gmt() );
	}

	public function test_cancel_clears_the_hold_anchor_of_a_held_contract(): void {
		$contract_id = $this->seed_contract();
		( new Hold() )->hold( $contract_id );

		( new Cancellation() )->cancel( $contract_id );

		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	/**
	 * @dataProvider provide_cancellable_statuses
	 *
	 * @param string $status Stored status cancel accepts.
	 */
	public function test_cancel_accepts_a_live_contract( string $status ): void {
		$contract_id = $this->seed_contract( [ 'status' => $status ] );
		$received    = $this->capture_action( 'woocommerce_subscriptions_lite_contract_cancelled' );

		( new Cancellation() )->cancel( $contract_id );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::CANCELLED, $stored->get_status() );
		$this->assertNull( $stored->get_next_payment_gmt() );
		$this->assertCount( 1, $received );
		$this->assertSame( ContractStatus::CANCELLED, $received[0]->get_status() );
	}

	/**
	 * Statuses cancel accepts (besides the idempotent cancelled).
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_cancellable_statuses(): array {
		return [
			'draft'                => [ ContractStatus::DRAFT ],
			'active'               => [ ContractStatus::ACTIVE ],
			'on hold'              => [ ContractStatus::ON_HOLD ],
			'pending cancellation' => [ ContractStatus::PENDING_CANCELLATION ],
		];
	}

	public function test_cancel_on_a_cancelled_contract_is_a_no_op_that_refires_the_action(): void {
		$contract_id = $this->seed_contract( [ 'status' => ContractStatus::CANCELLED ] );
		$received    = $this->capture_action( 'woocommerce_subscriptions_lite_contract_cancelled' );

		$cancelled = ( new Cancellation() )->cancel( $contract_id );

		$this->assertInstanceOf( ContractView::class, $cancelled );
		$this->assertCount( 1, $received );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_cancel_rejects_an_expired_contract(): void {
		$contract_id = $this->seed_contract( [ 'status' => ContractStatus::EXPIRED ] );
		$received    = $this->capture_action( 'woocommerce_subscriptions_lite_contract_cancelled' );

		try {
			( new Cancellation() )->cancel( $contract_id );
			$this->fail( 'Expected LifecycleNotAllowed for an expired contract.' );
		} catch ( LifecycleNotAllowed $e ) {
			$this->assertSame( ContractStatus::EXPIRED, $this->get_contract( $contract_id )->get_status() );
			$this->assertCount( 0, $received, 'The cancelled action does not fire.' );
		}
	}

	/**
	 * @dataProvider provide_cancel_modes
	 *
	 * @param string $method Cancellation method under test.
	 */
	public function test_an_unregistered_stored_status_is_rejected( string $method ): void {
		$contract_id = $this->seed_contract();
		$this->store_raw_status( $contract_id, 'legacy-paused' );

		try {
			( new Cancellation() )->$method( $contract_id );
			$this->fail( 'Expected LifecycleNotAllowed for an unregistered stored status.' );
		} catch ( LifecycleNotAllowed $e ) {
			$stored = $this->get_contract( $contract_id );
			$this->assertSame( 'legacy-paused', $stored->get_status() );
			$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt(), 'Nothing was written.' );
		}
	}

	/**
	 * The anchor is cleared after the status write, so a failed delete must not abort the
	 * transition: the lifecycle action still fires.
	 *
	 * @dataProvider provide_cancel_modes
	 *
	 * @param string $method Cancellation method under test.
	 * @param string $action Action the method fires.
	 * @param string $status Status the method writes.
	 */
	public function test_a_failed_anchor_clear_does_not_abort_the_transition( string $method, string $action, string $status ): void {
		$contract_id = $this->seed_contract();
		( new Hold() )->hold( $contract_id );
		$received   = $this->capture_action( $action );
		$meta_table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACT_META );
		$break      = static function ( string $query ) use ( $meta_table ): string {
			return 0 === strpos( $query, "DELETE FROM `{$meta_table}`" ) ? 'SELECT broken syntax (' : $query;
		};
		add_filter( 'query', $break );

		try {
			$result = ( new Cancellation() )->$method( $contract_id );
		} finally {
			remove_filter( 'query', $break );
		}

		$this->assertInstanceOf( ContractView::class, $result );
		$this->assertCount( 1, $received, 'The lifecycle action fires.' );
		$this->assertSame( $status, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $this->get_stored_anchor( $contract_id ), 'The anchor is left behind.' );
	}

	/**
	 * Both cancellation modes: method, action, resulting status.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function provide_cancel_modes(): array {
		return [
			'cancel'               => [ 'cancel', 'woocommerce_subscriptions_lite_contract_cancelled', ContractStatus::CANCELLED ],
			'cancel at period end' => [ 'cancel_at_period_end', 'woocommerce_subscriptions_lite_contract_pending_cancellation', ContractStatus::PENDING_CANCELLATION ],
		];
	}

	/**
	 * @dataProvider provide_cancel_modes
	 *
	 * @param string $method Cancellation method under test.
	 */
	public function test_a_missing_contract_returns_null( string $method ): void {
		$this->assertNull( ( new Cancellation() )->$method( 4242424 ) );
	}

	/**
	 * In-flight charge cycles belong to the renewal path: cancel leaves a pending cycle as is.
	 */
	public function test_cancel_leaves_a_pending_cycle_untouched(): void {
		$contract_id = $this->seed_contract();
		Contracts::add_cycle(
			$contract_id,
			[
				'starts_at_gmt' => '2026-01-01 00:00:00',
				'ends_at_gmt'   => '2026-02-01 00:00:00',
				'currency'      => 'USD',
			]
		);

		( new Cancellation() )->cancel( $contract_id );

		$cycles = Contracts::get_cycles( $contract_id );
		$this->assertCount( 1, $cycles );
		$this->assertSame( CycleStatus::PENDING, $cycles[0]->get_status() );
	}

	public function test_cancel_leaves_a_contract_of_another_extension_untouched(): void {
		$contract_id = $this->seed_contract( [ 'extension_slug' => 'another-extension' ] );

		$this->assertNull( ( new Cancellation() )->cancel( $contract_id ) );
		$this->assertNull( ( new Cancellation() )->cancel_at_period_end( $contract_id ) );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}
}
