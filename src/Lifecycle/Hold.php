<?php
/**
 * Hold - put an active subscription on hold (suspend billing).
 *
 * Moves the contract active -> on-hold and clears its next-due moment, which is what
 * stops renewals; the cleared moment is kept as the {@see HoldAnchor} so
 * {@see Reactivation} can resume from it. A contract whose renewal charge is still in flight
 * has no due moment; its anchor is the end of that renewal's period, where billing resumes
 * once it is paid (a payment that lands during the hold keeps the contract on hold).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Put a contract on hold.
 */
final class Hold {

	/**
	 * Hold an active contract. Holding an on-hold contract writes nothing and still
	 * succeeds; the current cycle is never touched. A failed anchor write throws a
	 * `RuntimeException` before anything is disarmed.
	 *
	 * @param int $contract_id Contract id.
	 * @return ContractView|null The held contract, or null when it does not exist or is not Lite's.
	 * @throws LifecycleNotAllowedException If the contract is neither active nor on hold.
	 */
	public function hold( int $contract_id ): ?ContractView {
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
			HoldAnchor::store( $contract_id, $contract->get_next_payment_gmt() ?? self::in_flight_period_end( $contract_id ) );

			$held_contract = Contracts::update(
				$contract_id,
				[
					'status'           => ContractStatus::ON_HOLD,
					'next_payment_gmt' => null,
				]
			);
			if ( null === $held_contract ) {
				return null;
			}
		}

		/**
		 * Fires after a contract is put on hold, also for a repeated hold.
		 *
		 * @param ContractView $held_contract The held contract.
		 */
		do_action( 'woocommerce_subscriptions_lite_contract_held', $held_contract );

		return $held_contract;
	}

	/**
	 * The period end of the contract's newest cycle while its renewal is still in flight
	 * (pending or processing), else null.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function in_flight_period_end( int $contract_id ): ?string {
		$head = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;

		return null !== $head && in_array( $head->get_status(), [ CycleStatus::PENDING, CycleStatus::PROCESSING ], true )
			? $head->get_ends_at_gmt()
			: null;
	}
}
