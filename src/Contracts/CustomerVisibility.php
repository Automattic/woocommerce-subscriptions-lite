<?php
/**
 * CustomerVisibility - which contracts a customer sees.
 *
 * A customer sees Lite's own contracts, drafts excepted; every customer-facing read uses
 * this rule. A contract of another extension is not Lite's to show, and a draft is a
 * contract still being built (or stuck mid-creation): the merchant sees it, the customer
 * does not.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Contracts
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Contracts;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;

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
		return Package::EXTENSION_SLUG === $contract->get_extension_slug()
			&& ContractStatus::DRAFT !== $contract->get_status();
	}

	/**
	 * The `$args` of a customer list read that applies this rule in the query: Lite's
	 * contracts in every registered status but draft.
	 *
	 * @return array{status: array<int, string>, extension_slug: string}
	 */
	public static function get_list_args(): array {
		return [
			'status'         => array_values( array_diff( ContractStatus::get_all(), [ ContractStatus::DRAFT ] ) ),
			'extension_slug' => Package::EXTENSION_SLUG,
		];
	}
}
