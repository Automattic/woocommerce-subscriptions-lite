<?php
/**
 * UnpaidRenewal - the renewal a contract still waits to be paid for.
 *
 * A failed charge and a manual renewal order both leave the contract on hold with no due
 * moment, which a customer hold also does; what tells them apart is the fact on the chain:
 * the newest cycle is unpaid (`failed`, or `pending` / `processing` with an order).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Unpaid renewal lookup.
 */
final class UnpaidRenewal {

	/**
	 * The contract's newest cycle when it is unpaid, else null.
	 *
	 * @param ContractView $contract The contract.
	 */
	public static function find( ContractView $contract ): ?CycleView {
		$head = Contracts::get_cycles( $contract->get_id(), 1 )[0] ?? null;
		if ( null === $head ) {
			return null;
		}

		$status = $head->get_status();
		if ( CycleStatus::FAILED === $status ) {
			return $head;
		}

		if ( in_array( $status, [ CycleStatus::PENDING, CycleStatus::PROCESSING ], true ) && null !== $head->get_order_id() ) {
			return $head;
		}

		return null;
	}
}
