<?php
/**
 * HeldLocks - the engine contract locks Lite holds in this request, by contract id.
 *
 * Every Lite flow that changes a contract runs under the engine's per-contract lock. A flow
 * acquires it through {@see self::run()}, or registers one acquired elsewhere (the due
 * dispatcher's, a locked create) through {@see self::with()}; code it reaches in the same
 * request (settlement inside a gateway charge, the system hold inside a renewal) finds the
 * held lock here instead of acquiring and conflicting with its own operation. Locks never
 * cross requests.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use LogicException;
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
	 * The lock held on the contract in this request, or null.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function get( int $contract_id ): ?ContractLock {
		return self::$locks[ $contract_id ] ?? null;
	}

	/**
	 * The lock held on the contract in this request.
	 *
	 * @param int $contract_id Contract id.
	 * @throws LogicException If none is held: the caller must run inside a locked flow.
	 */
	public static function require_lock( int $contract_id ): ContractLock {
		$lock = self::get( $contract_id );
		if ( null === $lock ) {
			throw new LogicException( sprintf( 'No lock is held on contract %d in this request.', $contract_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output.
		}

		return $lock;
	}

	/**
	 * Run `$flow( ContractLock )` under the contract's lock: the held one when this request
	 * holds it (not released here), else a new one acquired for the call and released after
	 * it, also on a throw.
	 *
	 * @template T
	 * @param int                       $contract_id Contract id.
	 * @param string                    $operation   Operation label for a new lock.
	 * @param callable(ContractLock): T $flow        The locked section.
	 * @return T The flow's result.
	 * @throws ContractLockedException If another operation holds the lock.
	 */
	public static function run( int $contract_id, string $operation, callable $flow ) {
		$held = self::get( $contract_id );
		if ( null !== $held ) {
			return $flow( $held );
		}

		$lock                        = Contracts::lock( $contract_id, $operation );
		self::$locks[ $contract_id ] = $lock;

		try {
			return $flow( $lock );
		} finally {
			unset( self::$locks[ $contract_id ] );
			self::release( $lock );
		}
	}

	/**
	 * Run `$flow( ContractLock )` with a lock acquired elsewhere registered as held. The lock
	 * is unregistered afterwards but never released: its acquirer releases it.
	 *
	 * @template T
	 * @param ContractLock              $lock The acquired lock.
	 * @param callable(ContractLock): T $flow The locked section.
	 * @return T The flow's result.
	 */
	public static function with( ContractLock $lock, callable $flow ) {
		$contract_id = $lock->get_contract_id();
		$previous    = self::get( $contract_id );

		self::$locks[ $contract_id ] = $lock;

		try {
			return $flow( $lock );
		} finally {
			if ( null === $previous ) {
				unset( self::$locks[ $contract_id ] );
			} else {
				self::$locks[ $contract_id ] = $previous;
			}
		}
	}

	/**
	 * Release a lock; a failed release is logged, and the lock expires on its own.
	 *
	 * @param ContractLock $lock The lock.
	 */
	public static function release( ContractLock $lock ): void {
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
}
