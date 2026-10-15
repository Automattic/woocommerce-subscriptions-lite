<?php
/**
 * CustomerActionRules - which lifecycle actions a customer may take in each status.
 *
 * The one rule set behind the portal's action buttons and the registered customer actions.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Customer action availability by status and unpaid renewals.
 */
final class CustomerActionRules {

	/**
	 * Statuses a customer may cancel from. Re-cancelling a winding-down contract is left to
	 * the merchant.
	 *
	 * @var array<int, string>
	 */
	private const CANCELLABLE_STATUSES = [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ];

	/**
	 * Whether the customer may put the contract on hold.
	 *
	 * @param string $status Contract status.
	 */
	public static function can_hold( string $status ): bool {
		return ContractStatus::ACTIVE === $status;
	}

	/**
	 * Whether the customer may resume the contract: on hold, and no renewal waits on a payment.
	 *
	 * @param string $status             Contract status.
	 * @param bool   $has_unpaid_renewal Whether the newest renewal is unpaid.
	 */
	public static function can_reactivate( string $status, bool $has_unpaid_renewal ): bool {
		return ContractStatus::ON_HOLD === $status && ! $has_unpaid_renewal;
	}

	/**
	 * Whether the customer may cancel the contract.
	 *
	 * @param string $status Contract status.
	 */
	public static function can_cancel( string $status ): bool {
		return in_array( $status, self::CANCELLABLE_STATUSES, true );
	}

	/**
	 * Whether the contract waits on a payment: on hold with an unpaid renewal (a failed
	 * charge or a manual renewal order), as opposed to a customer or merchant hold.
	 *
	 * @param string $status             Contract status.
	 * @param bool   $has_unpaid_renewal Whether the newest renewal is unpaid.
	 */
	public static function needs_payment( string $status, bool $has_unpaid_renewal ): bool {
		return ContractStatus::ON_HOLD === $status && $has_unpaid_renewal;
	}
}
