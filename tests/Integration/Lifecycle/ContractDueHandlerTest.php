<?php
/**
 * Integration tests for ContractDueHandler.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\ContractDueHandler;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\ContractDueHandler
 */
final class ContractDueHandlerTest extends LiteIntegrationTestCase {

	/**
	 * The dispatch context for a due moment, with the dispatcher's lock taken in the test.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $due_at      GMT due moment.
	 * @return array<string, mixed>
	 */
	private function context( int $contract_id, string $due_at = '2026-02-15 00:00:00' ): array {
		return [
			'due_at' => new DateTimeImmutable( $due_at, new DateTimeZone( 'UTC' ) ),
			'lock'   => $this->hold_lock( $contract_id, 'due' ),
		];
	}

	public function test_an_active_contract_is_renewed(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		( new ContractDueHandler() )->handle( $this->get_contract( $contract_id ), $this->context( $contract_id ) );

		$this->assertSame( 1, $this->charge_count() );
		$head = Contracts::get_cycles( $contract_id, 1 )[0];
		$this->assertSame( 2, $head->get_count() );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertNotNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_a_held_contract_with_a_due_moment_has_it_cleared_without_an_order(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->seed_contract(
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => '2026-02-15 00:00:00',
			]
		);
		$this->capture_log();

		( new ContractDueHandler() )->handle( $this->get_contract( $contract_id ), $this->context( $contract_id ) );

		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( [], Contracts::get_cycles( $contract_id ) );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertTrue( $this->has_logged( 'only active contracts renew' ) );
	}

	public function test_a_malformed_notification_is_ignored(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$contract    = $this->get_contract( $contract_id );
		$this->capture_log();

		( new ContractDueHandler() )->handle( $contract, [] );
		( new ContractDueHandler() )->handle( $contract, [ 'due_at' => '2026-02-15 00:00:00' ] );
		$context = $this->context( $contract_id );
		( new ContractDueHandler() )->handle( $contract_id, $context );
		( new ContractDueHandler() )->handle( $contract, [ 'due_at' => $context['due_at'] ] );
		( new ContractDueHandler() )->handle( $contract, $this->context( $this->seed_contract() ) );

		$this->assertSame( 0, $this->charge_count() );
		$this->assertCount( 1, Contracts::get_cycles( $contract_id ) );
		$this->assertSame( $contract->get_next_payment_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertTrue( $this->has_logged( 'ignored a due notification' ) );
	}

	public function test_the_renewal_runs_under_the_dispatchers_lock(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$refused     = [];
		add_action(
			'woocommerce_subscriptions_lite_renewal_paid',
			static function ( ContractView $contract ) use ( &$refused ): void {
				try {
					Contracts::update( $contract->get_id(), [ 'end_gmt' => '2099-12-31 00:00:00' ] );
				} catch ( ContractLockedException $e ) {
					$refused[] = $e->get_operation();
				}
			}
		);
		$context = $this->context( $contract_id );

		( new ContractDueHandler() )->handle( $this->get_contract( $contract_id ), $context );

		$this->assertSame( [ 'due' ], $refused, 'An unlocked write from a callback is refused while the lock is held.' );
		$this->assertNull( $this->get_contract( $contract_id )->get_end_gmt() );
		$this->assertSame( 'due', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null, 'The handler leaves the release to the dispatcher.' );
		$this->assertNull( HeldLocks::get( $contract_id ) );
	}

	public function test_a_failing_flow_is_logged_and_rethrown(): void {
		$contract_id = $this->seed_contract(
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => '2026-02-15 00:00:00',
			]
		);
		$context     = $this->context( $contract_id );
		$context['lock']->release();
		$this->hold_lock( $contract_id, 'other' );
		$this->capture_log();

		try {
			( new ContractDueHandler() )->handle( $this->get_contract( $contract_id ), $context );
			$this->fail( 'Expected the failure to propagate to the dispatcher.' );
		} catch ( ContractLockedException $e ) {
			$this->assertSame( 'other', $e->get_operation() );
		}

		$this->assertTrue( $this->has_logged( 'renewing contract ' . $contract_id . ' failed' ) );
	}
}
