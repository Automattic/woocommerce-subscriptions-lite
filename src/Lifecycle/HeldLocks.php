<?php
/**
 * HeldLocks - the engine contract locks Lite holds in this request, by contract id.
 *
 * A Lite flow that changes a contract uses the lock this request already holds on it, or
 * acquires one, registers it here and releases it when done. Code it reaches in the same
 * request (settlement inside a gateway charge, a lifecycle action listener) finds the held
 * lock here instead of conflicting with its own operation. Locks never cross requests.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Throwable;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped registry of held contract locks.
 */
final class HeldLocks {

	/**
	 * Held locks by contract id.
	 *
	 * @var array<int, ContractLock>
	 */
	private static $locks = [];

	/**
	 * The lock held on the contract in this request, or null. A listener of a Lite lifecycle
	 * action writes the contract with this lock: an unlocked write is refused while it is held.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function get( int $contract_id ): ?ContractLock {
		return self::$locks[ $contract_id ] ?? null;
	}

	/**
	 * Register a lock as held in this request.
	 *
	 * @param ContractLock $lock The acquired lock.
	 */
	public static function register( ContractLock $lock ): void {
		self::$locks[ $lock->get_contract_id() ] = $lock;
	}

	/**
	 * Unregister a lock without releasing it (its acquirer releases it).
	 *
	 * @param ContractLock $lock The registered lock.
	 */
	public static function unregister( ContractLock $lock ): void {
		if ( self::get( $lock->get_contract_id() ) === $lock ) {
			unset( self::$locks[ $lock->get_contract_id() ] );
		}
	}

	/**
	 * Unregister and release a lock acquired in this request. A failed release is logged, not
	 * thrown, so it never masks the flow's result; the lock expires on its own.
	 *
	 * @param ContractLock $lock The lock.
	 */
	public static function release( ContractLock $lock ): void {
		self::unregister( $lock );

		try {
			$lock->release();
		} catch ( Throwable $e ) {
			wc_get_logger()->warning(
				sprintf( 'HeldLocks: the %s lock on contract %d could not be released; it expires on its own. %s', $lock->get_operation(), $lock->get_contract_id(), $e->getMessage() ),
				[
					'source'      => Package::EXTENSION_SLUG,
					'contract_id' => $lock->get_contract_id(),
				]
			);
		}
	}

	/**
	 * After `Contracts::lock()` returned null: the conflict to report, labelled with the
	 * holder's operation, or null when the contract does not exist or is not Lite's.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function get_conflict( int $contract_id ): ?ContractLockedException {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return null;
		}

		// Null state: the holder released between the two reads; still a conflict, unlabelled.
		$state = $contract->get_lock_state();

		return new ContractLockedException( $contract_id, $state['operation'] ?? null, $state['locked_until'] ?? null );
	}
}
