<?php
/**
 * ContractDueHandler - Lite's answer when the engine reports one of its contracts due.
 *
 * Registered as Lite's `contract_due_handler` on the engine's consumer registry, it picks the
 * lifecycle flow a due contract needs. It runs under the dispatcher's contract lock (the
 * view was read under it), registered in {@see HeldLocks} for the flows it reaches. Only an
 * active contract renews; any other status with a due moment has it cleared, since Lite's
 * hold and cancel flows never leave one behind. A failure is logged and rethrown: the
 * dispatcher then keeps the lock until it expires, so the contract is not notified again
 * while its state is unknown, and the kept due moment redispatches it after that.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Contract due handler.
 */
final class ContractDueHandler {

	/**
	 * Handle a due contract.
	 *
	 * @param mixed $contract The due contract (a {@see ContractView}).
	 * @param mixed $context  Dispatch context: `due_at` (DateTimeImmutable, UTC) and `lock`
	 *                        (the dispatcher's {@see ContractLock} on the contract).
	 * @throws Throwable When the flow fails (after logging it).
	 */
	public function handle( $contract, $context ): void {
		$due_at = is_array( $context ) ? ( $context['due_at'] ?? null ) : null;
		$lock   = is_array( $context ) ? ( $context['lock'] ?? null ) : null;
		if ( ! $contract instanceof ContractView || ! $due_at instanceof DateTimeImmutable
			|| ! $lock instanceof ContractLock || $lock->get_contract_id() !== $contract->get_id() ) {
			wc_get_logger()->warning( 'ContractDueHandler: ignored a due notification without a contract view, a due_at moment and the contract\'s lock.', [ 'source' => Package::EXTENSION_SLUG ] );
			return;
		}

		HeldLocks::with(
			$lock,
			function ( ContractLock $lock ) use ( $contract, $due_at ): void {
				$this->handle_locked( $contract, $due_at, $lock );
			}
		);
	}

	/**
	 * The locked section of {@see self::handle()}.
	 *
	 * @param ContractView      $contract The due contract, read under the lock.
	 * @param DateTimeImmutable $due_at   The due moment.
	 * @param ContractLock      $lock     The dispatcher's lock.
	 * @throws Throwable When the flow fails (after logging it).
	 */
	private function handle_locked( ContractView $contract, DateTimeImmutable $due_at, ContractLock $lock ): void {
		$contract_id = $contract->get_id();

		try {
			if ( ContractStatus::ACTIVE === $contract->get_status() ) {
				( new RenewalProcessor() )->renew( $contract, $due_at, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), false );
				return;
			}

			wc_get_logger()->warning(
				sprintf( 'ContractDueHandler: contract %d is due while %s; only active contracts renew, so its due moment is cleared.', $contract_id, $contract->get_status() ),
				[
					'source'      => Package::EXTENSION_SLUG,
					'contract_id' => $contract_id,
				]
			);
			Contracts::update( $contract_id, [ 'next_payment_gmt' => null ], $lock );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'ContractDueHandler: renewing contract %d failed: %s', $contract_id, $e->getMessage() ),
				[
					'source'      => Package::EXTENSION_SLUG,
					'contract_id' => $contract_id,
				]
			);
			throw $e;
		}
	}
}
