<?php
/**
 * RenewalWiring - arms the engine's renewal machinery for a new contract.
 *
 * The engine schedules renewals through a batch dispatcher: a recurring scan
 * over the due index, not one Action Scheduler row per contract. After the
 * checkout handler creates a contract, this wiring makes sure that scan is
 * armed ({@see RenewalDispatcher::ensure_scheduled()} - idempotent, and a
 * no-op when it already runs) so the new contract's `next_payment_gmt` is
 * picked up when due. The gateway `recurring` capability gate applies inside
 * the engine at charge time.
 *
 * Lite owns the driver - the call site after contract creation; the engine
 * owns the dispatcher and the Action Scheduler coupling.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Renewal\RenewalDispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Arm the renewal machinery for a freshly-created contract.
 *
 * Construct via the no-arg constructor in production (ensures the engine's
 * dispatcher scan is scheduled); tests inject a fake scheduler seam to drive
 * the armed / gated-off outcomes without Action Scheduler.
 */
final class RenewalWiring {

	/**
	 * Logger source tag.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * Engine scheduler. Production: `RenewalDispatcher::ensure_scheduled()`.
	 *
	 * @var callable(Contract): bool
	 */
	private $scheduler;

	/**
	 * Construct the wiring.
	 *
	 * @param (callable(Contract): bool)|null $scheduler Scheduler; defaults to arming the engine dispatcher's recurring scan.
	 */
	public function __construct( ?callable $scheduler = null ) {
		$this->scheduler = $scheduler ?? static function ( Contract $contract ): bool {
			unset( $contract ); // The batch scan covers all due contracts; nothing per-contract to enqueue.
			RenewalDispatcher::ensure_scheduled();
			return true;
		};
	}

	/**
	 * Arm `$contract`'s renewals through the engine.
	 *
	 * Returns the seam's result: true when the renewal machinery is armed for
	 * the contract, false when it was turned down. A turn-down is logged - the
	 * contract is created and active, but nothing will charge it until the
	 * cause (typically a gateway without the `recurring` capability) is fixed.
	 *
	 * @param Contract $contract The contract to arm. Must have an id and a next-payment date.
	 * @return bool True when renewals are armed; false when turned down.
	 */
	public function schedule_first_renewal( Contract $contract ): bool {
		$scheduled = ( $this->scheduler )( $contract );

		if ( ! $scheduled ) {
			$gateway = $contract->get_payment_instrument()->get_gateway();
			wc_get_logger()->warning(
				sprintf(
					'RenewalWiring: the engine did not schedule a first renewal for contract %d (gateway "%s"). The contract is active but no renewal is armed - the gateway likely does not declare the "recurring" capability.',
					(int) $contract->get_id(),
					(string) ( $gateway ?? '' )
				),
				[
					'source'      => self::LOG_SOURCE,
					'contract_id' => (int) $contract->get_id(),
				]
			);
		}

		return $scheduled;
	}
}
