<?php
/**
 * HeldLocks - the engine contract locks Lite holds in this request, by contract id.
 *
 * A Lite flow that changes a contract uses the lock this request already holds on it, or
 * acquires one, registers it here and releases it when done; a lost acquire is a `WP_Error`
 * outcome ({@see self::get_conflict()}), not an exception. Code it reaches in the same
 * request (settlement inside a gateway charge, a lifecycle action listener) finds the held
 * lock here instead of conflicting with its own operation. Locks never cross requests.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use DateTimeImmutable;
use Throwable;
use WP_Error;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped registry of held contract locks.
 */
final class HeldLocks {

	/**
	 * Error code of a lock conflict: another operation holds the contract's lock.
	 */
	private const CONFLICT_CODE = 'woocommerce_subscriptions_lite_contract_locked';

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
	 * Acquire the contract's lock for `$operation` and register it as held; release it with
	 * {@see self::release()}. Null when another operation holds it or the contract is missing:
	 * {@see self::get_conflict()} tells the two apart.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $operation   Operation label.
	 */
	public static function acquire( int $contract_id, string $operation ): ?ContractLock {
		$lock = Contracts::lock( $contract_id, $operation );
		if ( null !== $lock ) {
			self::register( $lock );
		}

		return $lock;
	}

	/**
	 * After a lost {@see self::acquire()}: the lock conflict, labelled with the holder's
	 * operation, or null when the contract does not exist or is not Lite's.
	 *
	 * @param int $contract_id Contract id.
	 */
	public static function get_conflict( int $contract_id ): ?WP_Error {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return null;
		}

		// Null state: the holder released between the two reads; still a conflict, unlabelled.
		$state = $contract->get_lock_state();

		return self::build_conflict( $contract_id, $state['operation'] ?? null, $state['locked_until'] ?? null );
	}

	/**
	 * The lock conflict for a write the engine refused mid-flow, in the shape of a lost acquire.
	 *
	 * @param ContractLockedException $refused The refused write.
	 */
	public static function get_refused_conflict( ContractLockedException $refused ): WP_Error {
		return self::build_conflict( $refused->get_contract_id(), $refused->get_operation(), $refused->get_locked_until() );
	}

	/**
	 * A `woocommerce_subscriptions_lite_contract_locked` error naming the holder's operation.
	 *
	 * @param int                    $contract_id  Contract id.
	 * @param string|null            $operation    The holder's operation label, or null when unknown.
	 * @param DateTimeImmutable|null $locked_until The holder's lock expiry (UTC), or null when unknown.
	 */
	private static function build_conflict( int $contract_id, ?string $operation, ?DateTimeImmutable $locked_until ): WP_Error {
		return new WP_Error(
			self::CONFLICT_CODE,
			StatusLabels::get_lock_conflict_message( $contract_id, $operation ),
			[
				'contract_id'  => $contract_id,
				'operation'    => $operation,
				'locked_until' => $locked_until,
			]
		);
	}
}
