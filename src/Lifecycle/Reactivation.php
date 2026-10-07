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
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Reactivate a held contract.
 */
final class Reactivation {

	/**
	 * Bound on the forward roll, so a fine-grained cadence after a very long hold cannot loop unboundedly.
	 */
	private const MAX_FORWARD_ROLLS = 1000;

	/**
	 * Reactivate an on-hold contract. The anchor is the next payment when one was set
	 * while held (hold clears it, so it was set deliberately), else the hold anchor.
	 *
	 * @param int                    $contract_id Contract id.
	 * @param DateTimeImmutable|null $now         The current moment; the UTC wall clock when omitted.
	 * @return ContractView|null The reactivated contract, or null when it does not exist.
	 * @throws LifecycleNotAllowed If the contract is not on hold.
	 */
	public function reactivate( int $contract_id, ?DateTimeImmutable $now = null ): ?ContractView {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract ) {
			return null;
		}

		// An active contract must never reach the forward roll: it would skip an owed charge.
		if ( ContractStatus::ON_HOLD !== $contract->get_status() ) {
			throw new LifecycleNotAllowed( 'Only an on-hold contract can be reactivated.' );
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
			]
		);
		if ( null === $reactivated_contract ) {
			return null;
		}

		HoldAnchor::clear( $contract_id );

		/**
		 * Fires after a held contract is reactivated (re-armed, or unscheduled when it had no anchor).
		 *
		 * @param ContractView $reactivated_contract The reactivated contract.
		 */
		do_action( 'woocommerce_subscriptions_lite_contract_reactivated', $reactivated_contract );

		return $reactivated_contract;
	}

	/**
	 * The next payment after a hold: "Model 1", a pending product decision kept to this method.
	 *
	 * A future anchor is kept; a past-due one is rolled forward by whole plan cadences until
	 * it is in the future, floored at `$now` when there is no cadence or the roll cap runs
	 * out (logged); no anchor leaves the contract unscheduled.
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

		$billing_policy = $this->get_billing_policy( $contract );
		if ( null === $billing_policy ) {
			return $now->format( 'Y-m-d H:i:s' );
		}

		$rolls_left = self::MAX_FORWARD_ROLLS;
		while ( $next_payment <= $now && $rolls_left-- > 0 ) {
			$next_payment = $billing_policy->compute_next_renewal_from( $next_payment );
		}

		if ( $next_payment <= $now ) {
			wc_get_logger()->warning(
				sprintf( 'Reactivation: contract %d exhausted the forward-roll cap; next payment floored at now.', $contract->get_id() ),
				[
					'source'      => 'woocommerce-subscriptions-lite',
					'contract_id' => $contract->get_id(),
				]
			);

			return $now->format( 'Y-m-d H:i:s' );
		}

		return $next_payment->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The billing policy of the contract's live plan, or null when it has none (no plan,
	 * or the plan is archived or deleted).
	 *
	 * @param ContractView $contract The contract.
	 */
	private function get_billing_policy( ContractView $contract ): ?BillingPolicy {
		$plan = ContractPlans::for_contract( $contract );

		return null === $plan ? null : $plan->get_billing_policy();
	}
}
