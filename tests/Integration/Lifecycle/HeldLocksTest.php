<?php
/**
 * Integration tests for HeldLocks: acquire, register, share within the request, release, conflicts.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks
 */
final class HeldLocksTest extends LiteIntegrationTestCase {

	public function test_a_registered_lock_is_found_until_it_is_released(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'hold' );

		HeldLocks::register( $lock );
		$this->assertSame( $lock, HeldLocks::get( $contract_id ) );

		HeldLocks::release( $lock );
		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertNull( $this->get_contract( $contract_id )->get_lock_state(), 'Released.' );
	}

	public function test_unregister_keeps_the_lock_held(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'engine_due_dispatch' );

		HeldLocks::register( $lock );
		HeldLocks::unregister( $lock );

		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertSame( 'engine_due_dispatch', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null );
		$lock->release();
	}

	public function test_unregister_leaves_another_registered_lock_in_place(): void {
		$contract_id = $this->seed_contract();
		$first       = $this->hold_lock( $contract_id, 'engine_due_dispatch' );
		$first->release();
		$second = $this->hold_lock( $contract_id, 'hold' );

		HeldLocks::register( $second );
		HeldLocks::unregister( $first );

		$this->assertSame( $second, HeldLocks::get( $contract_id ) );
		HeldLocks::release( $second );
	}

	public function test_acquire_takes_and_registers_the_lock(): void {
		$contract_id = $this->seed_contract();

		$lock = HeldLocks::acquire( $contract_id, 'hold' );

		$this->assertNotNull( $lock );
		$this->assertSame( $lock, HeldLocks::get( $contract_id ) );
		$this->assertSame( 'hold', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null );
		HeldLocks::release( $lock );
	}

	public function test_a_lost_acquire_is_null_and_registers_nothing(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'cancel' );

		$this->assertNull( HeldLocks::acquire( $contract_id, 'hold' ) );
		$this->assertNull( HeldLocks::acquire( 999999, 'hold' ) );
		$this->assertNull( HeldLocks::get( $contract_id ) );
		$lock->release();
	}

	public function test_a_held_lock_is_a_conflict_labelled_with_its_operation(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'cancel' );

		$conflict = HeldLocks::get_conflict( $contract_id );

		$this->assert_lock_conflict( $conflict, $contract_id, 'cancel' );
		$this->assertStringContainsString( '#' . $contract_id, $conflict->get_error_message() );
		$lock->release();
	}

	public function test_a_refused_write_is_the_same_conflict_and_is_logged(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'cancel' );
		$this->capture_log();

		try {
			Contracts::update( $contract_id, [ 'next_payment_gmt' => '2099-03-01 00:00:00' ] );
			$this->fail( 'Expected the unlocked write to be refused.' );
		} catch ( ContractLockedException $e ) {
			$this->assert_lock_conflict( HeldLocks::get_refused_conflict( $e ), $contract_id, 'cancel' );
		} finally {
			$lock->release();
		}

		$this->assertTrue( $this->has_logged( sprintf( 'a write to contract %d was refused mid-flow; another operation (cancel)', $contract_id ) ) );
	}

	public function test_a_missing_or_foreign_contract_is_no_conflict(): void {
		$foreign_id = $this->seed_contract( [ 'extension_slug' => 'another-extension' ] );
		$lock       = $this->hold_lock( $foreign_id, 'other' );

		$this->assertNull( HeldLocks::get_conflict( 999999 ) );
		$this->assertNull( HeldLocks::get_conflict( $foreign_id ) );
		$lock->release();
	}

	public function test_a_flow_write_with_the_held_lock_passes_while_an_unlocked_write_is_refused(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'hold' );

		try {
			$updated = Contracts::update( $contract_id, [ 'next_payment_gmt' => '2099-02-01 00:00:00' ], $lock );
			$this->assertSame( '2099-02-01 00:00:00', $updated->get_next_payment_gmt() );
			$this->assertSame( '2099-02-01 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The locked write landed.' );

			$this->expectException( ContractLockedException::class );
			Contracts::update( $contract_id, [ 'next_payment_gmt' => '2099-03-01 00:00:00' ] );
		} finally {
			$lock->release();
		}
	}
}
