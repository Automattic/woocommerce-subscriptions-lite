<?php
/**
 * RenewalWiring - schedules a contract's first renewal through the engine.
 *
 * After the checkout handler creates a contract, the first renewal has to be
 * armed. The engine's {@see RenewalEngine::schedule()} owns that: it reads the
 * contract's `next_payment_gmt` (set by the engine factory) and enqueues one
 * Action Scheduler row - but only when the contract's gateway declares the
 * `recurring` capability. This wiring is the thin Lite-side delegate: it hands
 * the contract to the engine and logs when scheduling is skipped, so a merchant
 * whose gateway has not declared `recurring` gets a signal rather than a silent
 * no-renewal.
 *
 * Lite owns the driver - the call site after contract creation; the engine owns
 * the capability gate and the Action Scheduler coupling.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Renewal\RenewalEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Schedule the first renewal for a freshly-created contract.
 *
 * Construct via the no-arg constructor in production (delegates to the engine's
 * `RenewalEngine`); tests inject a fake scheduler seam to drive the
 * scheduled / gated-off outcomes without Action Scheduler.
 */
final class RenewalWiring {

	/**
	 * Logger source tag.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * Engine scheduler. Production: `RenewalEngine::schedule()`.
	 *
	 * @var callable(Contract): bool
	 */
	private $scheduler;

	/**
	 * Construct the wiring.
	 *
	 * @param (callable(Contract): bool)|null $scheduler Scheduler; defaults to the engine `RenewalEngine`.
	 */
	public function __construct( ?callable $scheduler = null ) {
		$this->scheduler = $scheduler ?? static function ( Contract $contract ): bool {
			return ( new RenewalEngine() )->schedule( $contract );
		};
	}

	/**
	 * Schedule `$contract`'s first renewal through the engine.
	 *
	 * Returns the engine's result: true when a renewal row was enqueued, false
	 * when the engine skipped it (the gateway does not declare `recurring`, the
	 * contract is gateway-scheduled, or it has no next-payment date). A skip is
	 * logged - the contract is created and active, but nothing will charge it
	 * until the gateway declares the capability.
	 *
	 * @param Contract $contract The contract to schedule. Must have an id and a next-payment date.
	 * @return bool True when the engine enqueued a renewal; false when it was skipped.
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
