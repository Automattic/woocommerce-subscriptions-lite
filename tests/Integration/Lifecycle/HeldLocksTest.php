<?php
/**
 * Integration tests for HeldLocks: acquire, share within the request, release.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use LogicException;
use RuntimeException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks
 */
final class HeldLocksTest extends LiteIntegrationTestCase {

	public function test_run_acquires_shares_and_releases_the_lock(): void {
		$contract_id = $this->seed_contract();

		$seen = HeldLocks::run(
			$contract_id,
			'hold',
			function ( ContractLock $lock ) use ( $contract_id ): ContractLock {
				$this->assertSame( $lock, HeldLocks::get( $contract_id ), 'Nested code finds the held lock.' );
				$this->assertSame( 'hold', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null );
				return $lock;
			}
		);

		$this->assertSame( 'hold', $seen->get_operation() );
		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertNull( $this->get_contract( $contract_id )->get_lock_state(), 'Released after the flow.' );
		$this->hold_lock( $contract_id )->release();
	}

	public function test_run_releases_the_lock_when_the_flow_throws(): void {
		$contract_id = $this->seed_contract();

		try {
			HeldLocks::run(
				$contract_id,
				'hold',
				static function (): void {
					throw new RuntimeException( 'Boom.' );
				}
			);
			$this->fail( 'The flow exception propagates.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Boom.', $e->getMessage() );
		}

		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertNull( $this->get_contract( $contract_id )->get_lock_state() );
	}

	public function test_nested_run_reuses_the_held_lock_and_does_not_release_it(): void {
		$contract_id = $this->seed_contract();

		HeldLocks::run(
			$contract_id,
			'renewal',
			function ( ContractLock $outer ) use ( $contract_id ): void {
				$inner = HeldLocks::run(
					$contract_id,
					'settlement',
					static function ( ContractLock $lock ): ContractLock {
						return $lock;
					}
				);
				$this->assertSame( $outer, $inner );
				$this->assertSame( 'renewal', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null, 'Still locked after the nested run.' );
			}
		);
	}

	public function test_run_while_another_holder_has_the_lock_throws_and_registers_nothing(): void {
		$contract_id = $this->seed_contract();
		$this->hold_lock( $contract_id, 'cancel' );
		$ran = false;

		try {
			HeldLocks::run(
				$contract_id,
				'hold',
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
			$this->fail( 'A held lock conflicts.' );
		} catch ( ContractLockedException $e ) {
			$this->assertSame( 'cancel', $e->get_operation() );
		}

		$this->assertFalse( $ran );
		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertSame( 'cancel', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null );
	}

	public function test_with_registers_a_lock_and_never_releases_it(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'due' );

		HeldLocks::with(
			$lock,
			function ( ContractLock $held ) use ( $contract_id, $lock ): void {
				$this->assertSame( $lock, $held );
				$this->assertSame( $lock, HeldLocks::get( $contract_id ) );
			}
		);

		$this->assertNull( HeldLocks::get( $contract_id ) );
		$this->assertSame( 'due', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null );
		$lock->release();
	}

	public function test_require_lock_without_a_held_lock_throws(): void {
		$this->expectException( LogicException::class );

		HeldLocks::require_lock( $this->seed_contract() );
	}

	public function test_a_flow_write_with_the_held_lock_passes_while_an_unlocked_write_is_refused(): void {
		$contract_id = $this->seed_contract();

		HeldLocks::run(
			$contract_id,
			'hold',
			function ( ContractLock $lock ) use ( $contract_id ): void {
				Contracts::update( $contract_id, [ 'next_payment_gmt' => '2099-02-01 00:00:00' ], $lock );

				$this->expectException( ContractLockedException::class );
				Contracts::update( $contract_id, [ 'next_payment_gmt' => '2099-03-01 00:00:00' ] );
			}
		);
	}
}
