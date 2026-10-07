<?php
/**
 * CustomerVisibility - which contracts a customer sees.
 *
 * A draft is a contract still being built (or stuck mid-creation): the merchant sees it,
 * the customer does not. Every customer-facing read uses this one rule.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Contracts
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Contracts;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The customer-visible contract rule.
 */
final class CustomerVisibility {

	/**
	 * Whether the customer may see `$contract`.
	 *
	 * @param ContractView $contract The contract.
	 */
	public static function is_visible( ContractView $contract ): bool {
		return ContractStatus::DRAFT !== $contract->get_status();
	}

	/**
	 * Every registered status a customer sees, for status-filtered list reads.
	 *
	 * @return array<int, string>
	 */
	public static function visible_statuses(): array {
		return array_values( array_diff( ContractStatus::get_all(), [ ContractStatus::DRAFT ] ) );
	}
}
