<?php
/**
 * Reactivation - resume a held subscription (resume billing).
 *
 * Moves the contract on-hold -> active and re-arms its next-due moment, recomputed
 * forward from the {@see HoldAnchor} so the next renewal is never due at a past date.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\UnpaidRenewal;

defined( 'ABSPATH' ) || exit;

/**
 * Reactivate a held contract.
 */
final class Reactivation {

	/**
	 * Operation label of the reactivation's contract lock.
	 */
	public const OPERATION = 'reactivate';

	/**
	 * Reactivate an on-hold contract under its lock; every check reads after acquiring. The
	 * anchor is the next payment when one was set while held (hold clears it, so it was set
	 * deliberately), else the hold anchor.
	 *
	 * @param int                    $contract_id Contract id.
	 * @param DateTimeImmutable|null $now         The current moment; the UTC wall clock when omitted.
	 * @return ContractView|WP_Error|null The reactivated contract; a lock conflict when another
	 *                                    operation holds the lock; null when it does not exist or is not Lite's.
	 * @throws LifecycleNotAllowedException If the contract is not on hold, or a renewal payment is outstanding.
	 */
	public function reactivate( int $contract_id, ?DateTimeImmutable $now = null ) {
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

			// An active contract must never reach the forward roll: it would skip an owed charge.
			if ( ContractStatus::ON_HOLD !== $contract->get_status() ) {
				throw new LifecycleNotAllowedException( 'Only an on-hold contract can be reactivated.' );
			}

			// A renewal waiting on payment resumes the contract when it is paid, not before.
			if ( null !== UnpaidRenewal::find( $contract ) ) {
				throw new LifecycleNotAllowedException( 'A renewal payment is outstanding.' );
			}

			$utc          = new DateTimeZone( 'UTC' );
			$utc_now      = ( $now ?? new DateTimeImmutable( 'now', $utc ) )->setTimezone( $utc );
			$anchor       = $contract->get_next_payment_gmt() ?? HoldAnchor::read( $contract_id );
			$next_payment = $this->recompute_next_payment( $contract, $anchor, $utc_now );

			$reactivated_contract = Contracts::update(
				$contract_id,
				[
					'status'           => ContractStatus::ACTIVE,
					'next_payment_gmt' => $next_payment,
				],
				$lock
			);
			if ( null === $reactivated_contract ) {
				return null;
			}

			HoldAnchor::clear( $lock );

			/**
			 * Fires after a held contract is reactivated (re-armed, or unscheduled when it had no
			 * anchor). Runs under the contract lock: a callback writes the contract with
			 * `HeldLocks::get( $id )`, since an unlocked write is refused.
			 *
			 * @param ContractView $reactivated_contract The reactivated contract.
			 */
			do_action( 'woocommerce_subscriptions_lite_contract_reactivated', $reactivated_contract );

			return $reactivated_contract;
		} finally {
			if ( $acquired ) {
				HeldLocks::release( $lock );
			}
		}
	}

	/**
	 * The next payment after a hold.
	 *
	 * A future anchor is kept; a past-due one is rolled forward by whole plan cadences until
	 * it is in the future ({@see BillingTerms::get_next_due_after()}), or floored at `$now`
	 * when there is no cadence; no anchor leaves the contract unscheduled.
	 *
	 * @param ContractView      $contract The contract being reactivated.
	 * @param string|null       $anchor   GMT moment to recompute from, or null.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 * @return string|null The next-payment GMT string, or null when unscheduled.
	 */
	private function recompute_next_payment( ContractView $contract, ?string $anchor, DateTimeImmutable $now ): ?string {
		if ( null === $anchor ) {
			return null;
		}

		$next_payment = new DateTimeImmutable( $anchor, new DateTimeZone( 'UTC' ) );
		if ( $next_payment > $now ) {
			return $anchor;
		}

		$billing_policy = ContractPlans::get_billing_policy_for( $contract );
		if ( null === $billing_policy ) {
			return $now->format( 'Y-m-d H:i:s' );
		}

		return BillingTerms::get_next_due_after( $next_payment, $now, $billing_policy )->format( 'Y-m-d H:i:s' );
	}
}
