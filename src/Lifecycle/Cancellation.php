<?php
/**
 * Cancellation - cancel a subscription now or at the end of the current period.
 *
 * Both modes clear the contract's next-due moment, which is what stops renewals, and its
 * {@see HoldAnchor}. An in-flight charge cycle is left to the renewal path. Both run under
 * the engine's contract lock ({@see HeldLocks}).
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

defined( 'ABSPATH' ) || exit;

/**
 * Cancel a contract.
 */
final class Cancellation {

	/**
	 * Operation label of a cancellation's contract lock.
	 */
	public const OPERATION = 'cancel';

	/**
	 * Statuses {@see self::cancel()} moves to cancelled.
	 */
	private const CANCELLABLE_STATUSES = [ ContractStatus::DRAFT, ContractStatus::ACTIVE, ContractStatus::ON_HOLD, ContractStatus::PENDING_CANCELLATION ];

	/**
	 * Whether a contract in `$status` can be cancelled now.
	 *
	 * @param string $status Contract status slug.
	 */
	public static function can_cancel( string $status ): bool {
		return in_array( $status, self::CANCELLABLE_STATUSES, true );
	}

	/**
	 * Cancel a draft, active, on-hold or pending-cancellation contract now, under its lock.
	 * Cancelling a cancelled contract writes nothing and still succeeds.
	 *
	 * @param int $contract_id Contract id.
	 * @return ContractView|null The cancelled contract, or null when it does not exist or is not Lite's.
	 * @throws LifecycleNotAllowedException If the contract's status cannot be cancelled.
	 * @throws ContractLockedException If another operation holds the contract's lock.
	 */
	public function cancel( int $contract_id ): ?ContractView {
		if ( ! Hold::is_lite_contract( $contract_id ) ) {
			return null;
		}

		return HeldLocks::run(
			$contract_id,
			self::OPERATION,
			static function ( ContractLock $lock ) use ( $contract_id ): ?ContractView {
				return self::cancel_locked( $contract_id, $lock );
			}
		);
	}

	/**
	 * The locked section of {@see self::cancel()}: read and decide, the status write, then
	 * the anchor clear.
	 *
	 * @param int          $contract_id Contract id.
	 * @param ContractLock $lock        The held lock.
	 * @throws LifecycleNotAllowedException If the contract's status cannot be cancelled.
	 */
	private static function cancel_locked( int $contract_id, ContractLock $lock ): ?ContractView {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract ) {
			return null;
		}

		$status = $contract->get_status();
		if ( ContractStatus::CANCELLED !== $status && ! self::can_cancel( $status ) ) {
			throw new LifecycleNotAllowedException( 'Only a draft, active, on-hold or pending-cancellation contract can be cancelled.' );
		}

		$cancelled_contract = $contract;
		if ( ContractStatus::CANCELLED !== $status ) {
			$cancelled_contract = Contracts::update(
				$contract_id,
				[
					'status'           => ContractStatus::CANCELLED,
					'next_payment_gmt' => null,
				],
				$lock
			);
			if ( null === $cancelled_contract ) {
				return null;
			}

			HoldAnchor::clear( $contract_id, $lock );
		}

		/**
		 * Fires after a contract is cancelled, also for a repeated cancel. Runs under the
		 * contract lock.
		 *
		 * @param ContractView $cancelled_contract The cancelled contract.
		 */
		do_action( 'woocommerce_subscriptions_lite_contract_cancelled', $cancelled_contract );

		return $cancelled_contract;
	}

	/**
	 * Wind an active or on-hold contract down at the end of the current period, under its
	 * lock: it moves to pending-cancellation and, when it has no end date yet, ends at its
	 * next-due moment (the hold anchor for a held contract). Repeating it on a
	 * pending-cancellation contract writes nothing and still succeeds.
	 *
	 * @param int $contract_id Contract id.
	 * @return ContractView|null The pending-cancellation contract, or null when it does not exist or is not Lite's.
	 * @throws LifecycleNotAllowedException If the contract is not active, on hold or pending cancellation.
	 * @throws ContractLockedException If another operation holds the contract's lock.
	 */
	public function cancel_at_period_end( int $contract_id ): ?ContractView {
		if ( ! Hold::is_lite_contract( $contract_id ) ) {
			return null;
		}

		return HeldLocks::run(
			$contract_id,
			self::OPERATION,
			function ( ContractLock $lock ) use ( $contract_id ): ?ContractView {
				return $this->cancel_at_period_end_locked( $contract_id, $lock );
			}
		);
	}

	/**
	 * The locked section of {@see self::cancel_at_period_end()}.
	 *
	 * @param int          $contract_id Contract id.
	 * @param ContractLock $lock        The held lock.
	 * @throws LifecycleNotAllowedException If the contract is not active, on hold or pending cancellation.
	 */
	private function cancel_at_period_end_locked( int $contract_id, ContractLock $lock ): ?ContractView {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract ) {
			return null;
		}

		$status = $contract->get_status();
		if ( ! in_array( $status, [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD, ContractStatus::PENDING_CANCELLATION ], true ) ) {
			throw new LifecycleNotAllowedException( 'Only an active or on-hold contract can be cancelled at period end.' );
		}

		$pending_contract = $contract;
		if ( ContractStatus::PENDING_CANCELLATION !== $status ) {
			$pending_contract = Contracts::update( $contract_id, $this->get_period_end_fields( $contract ), $lock );
			if ( null === $pending_contract ) {
				return null;
			}

			HoldAnchor::clear( $contract_id, $lock );
		}

		/**
		 * Fires after a contract is set to end with its current period, also for a repeat.
		 * Runs under the contract lock.
		 *
		 * @param ContractView $pending_contract The pending-cancellation contract.
		 */
		do_action( 'woocommerce_subscriptions_lite_contract_pending_cancellation', $pending_contract );

		return $pending_contract;
	}

	/**
	 * The fields that wind `$contract` down: pending-cancellation, no next-due moment, and
	 * the period end as its end date unless one is already set.
	 *
	 * @param ContractView $contract Active or on-hold contract.
	 * @return array<string, string|null>
	 */
	private function get_period_end_fields( ContractView $contract ): array {
		$fields = [
			'status'           => ContractStatus::PENDING_CANCELLATION,
			'next_payment_gmt' => null,
		];

		$period_end = $contract->get_next_payment_gmt();
		if ( null === $period_end && ContractStatus::ON_HOLD === $contract->get_status() ) {
			$period_end = HoldAnchor::read( $contract->get_id() );
		}

		if ( null === $contract->get_end_gmt() && null !== $period_end ) {
			$fields['end_gmt'] = $period_end;
		}

		return $fields;
	}
}
