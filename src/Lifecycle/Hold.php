<?php
/**
 * Hold - put an active subscription on hold (suspend billing).
 *
 * Moves the contract active -> on-hold and clears its next-due moment, which is what
 * stops renewals; the cleared moment is kept as the {@see HoldAnchor} so
 * {@see Reactivation} can resume from it. A contract with no due moment (a renewal charge
 * still in flight, or a renewal parked for a missing billing policy) is anchored on the end
 * of its newest period instead, so every customer hold carries an anchor: that is what tells
 * it apart from Lite's own hold, and a payment that lands during it keeps it on hold.
 *
 * Lite's own hold ({@see self::system_hold()}) is placed while a renewal waits to be paid: a
 * failed or unbuildable renewal, a dead renewal order or a manual renewal order. It drops the
 * anchor instead, so the hold ends when the unpaid renewal is paid.
 *
 * Both run under the engine's contract lock ({@see HeldLocks}).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Put a contract on hold.
 */
final class Hold {

	/**
	 * Operation label of the customer hold's contract lock.
	 */
	public const OPERATION = 'hold';

	/**
	 * Hold an active contract under its lock: read and decide after acquiring, then the
	 * anchor, then the status (last). Holding an on-hold contract writes nothing and still
	 * succeeds. A failed anchor write throws a `RuntimeException` before anything is disarmed.
	 *
	 * @param int $contract_id Contract id.
	 * @return ContractView|WP_Error|null The held contract; a lock conflict when another operation
	 *                                    holds the lock; null when it does not exist or is not Lite's.
	 * @throws LifecycleNotAllowedException If the contract is neither active nor on hold.
	 */
	public function hold( int $contract_id ) {
		$lock     = HeldLocks::get( $contract_id );
		$acquired = null === $lock;
		if ( $acquired ) {
			$lock = HeldLocks::acquire( $contract_id, self::OPERATION );
			if ( null === $lock ) {
				return HeldLocks::get_conflict( $contract_id );
			}
		}

		try {
			$contract = Contracts::get( $contract_id );
			if ( null === $contract || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
				return null;
			}

			$status = $contract->get_status();
			if ( ContractStatus::ACTIVE !== $status && ContractStatus::ON_HOLD !== $status ) {
				throw new LifecycleNotAllowedException( 'Only an active contract can be held.' );
			}

			$held_contract = $contract;
			if ( ContractStatus::ACTIVE === $status ) {
				// Anchor first, so a reader that sees the contract on hold always finds it.
				HoldAnchor::store( $lock, $contract->get_next_payment_gmt() ?? self::newest_period_end( $contract_id ) );

				$held_contract = Contracts::update(
					$contract_id,
					[
						'status'           => ContractStatus::ON_HOLD,
						'next_payment_gmt' => null,
					],
					$lock
				);
				if ( null === $held_contract ) {
					return null;
				}
			}

			/**
			 * Fires after a contract is put on hold, also for a repeated hold. Runs under the
			 * contract lock: a callback writes the contract with `HeldLocks::get( $id )`, since
			 * an unlocked write is refused.
			 *
			 * @param ContractView $held_contract The held contract.
			 */
			do_action( 'woocommerce_subscriptions_lite_contract_held', $held_contract );

			return $held_contract;
		} finally {
			if ( $acquired ) {
				HeldLocks::release( $lock );
			}
		}
	}

	/**
	 * Place Lite's hold for an unpaid renewal: on hold with no due moment, and no hold anchor.
	 * No checks and no `contract_held` action; the renewal flow calling it has decided, under
	 * `$lock`. The anchor goes first, so a reader that sees the contract on hold never finds a
	 * stale one.
	 *
	 * @param ContractLock         $lock   The caller's lock on the contract.
	 * @param array<string, mixed> $fields Further contract fields to write with the hold.
	 * @throws ContractLockedException If the lock was lost.
	 */
	public static function system_hold( ContractLock $lock, array $fields = [] ): void {
		HoldAnchor::clear( $lock );
		Contracts::update(
			$lock->get_contract_id(),
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			] + $fields,
			$lock
		);
	}

	/**
	 * The period end of the contract's newest cycle, or null when it has none.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function newest_period_end( int $contract_id ): ?string {
		$head = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;

		return null === $head ? null : $head->get_ends_at_gmt();
	}
}
