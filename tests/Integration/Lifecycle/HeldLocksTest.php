<?php
/**
 * Integration tests for HeldLocks: register, share within the request, release, conflicts.
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
		$lock        = $this->hold_lock( $contract_id, 'due' );

		HeldLocks::register( $lock );
		HeldLocks::unregister( $lock );

		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertSame( 'due', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null );
		$lock->release();
	}

	public function test_unregister_leaves_another_registered_lock_in_place(): void {
		$contract_id = $this->seed_contract();
		$first       = $this->hold_lock( $contract_id, 'due' );
		$first->release();
		$second = $this->hold_lock( $contract_id, 'hold' );

		HeldLocks::register( $second );
		HeldLocks::unregister( $first );

		$this->assertSame( $second, HeldLocks::get( $contract_id ) );
		HeldLocks::release( $second );
	}

	public function test_a_held_lock_is_a_conflict_labelled_with_its_operation(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'cancel' );

		$conflict = HeldLocks::get_conflict( $contract_id );

		$this->assertInstanceOf( ContractLockedException::class, $conflict );
		$this->assertSame( $contract_id, $conflict->get_contract_id() );
		$this->assertSame( 'cancel', $conflict->get_operation() );
		$this->assertNotNull( $conflict->get_locked_until() );
		$lock->release();
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
