<?php
/**
 * EngineDataProvider - reads customer-portal data from the subscriptions engine.
 *
 * The engine-backed implementation of {@see DataProvider}. It is the swap
 * target for {@see FixtureDataProvider}: once the engine exposes a
 * customer-scoped contract read and a detail read model, this class calls them
 * (and reads related orders off the order/contract linkage) and the provider
 * resolver's default flips here.
 *
 * Until those reads land, this implementation degrades to empty results rather
 * than reaching into engine internals, so wiring it in early cannot fatal.
 * The methods carry the field-mapping contract {@see ViewModel} expects, so the
 * fill-in is a localized change with no template or store churn.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

defined( 'ABSPATH' ) || exit;

/**
 * Engine-backed implementation of {@see DataProvider}.
 */
final class EngineDataProvider implements DataProvider {

	/**
	 * Return the customer's contracts from the engine's customer-scoped read.
	 *
	 * Maps each contract to the domain-ish array {@see ViewModel} consumes:
	 * `id`, `status`, `billing_total`, `currency`, `billing_period`,
	 * `billing_interval`, `next_payment_gmt`, and a `payment_method` array.
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_contracts_for_customer( int $customer_id ): array {
		// Filled in when the engine's customer-scoped contract read is wired.
		return [];
	}

	/**
	 * Return one contract's detail from the engine, ownership-checked.
	 *
	 * Enforces the asymmetric not-found rule: an unknown id and a contract
	 * owned by another customer both return null. Maps to the detail array
	 * {@see ViewModel} consumes (adds `start_gmt`, `end_gmt`,
	 * `last_payment_gmt`, `last_updated_gmt`, `items`).
	 *
	 * @param int $contract_id The contract id from the URL.
	 * @param int $customer_id The logged-in customer id.
	 * @return array<string, mixed>|null
	 */
	public function get_contract( int $contract_id, int $customer_id ): ?array {
		// Filled in when the engine's detail read model is wired.
		return null;
	}

	/**
	 * Return the related orders for a contract from the order/contract linkage.
	 *
	 * @param int $contract_id The contract id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_related_orders( int $contract_id ): array {
		// Filled in when the engine's related-orders read is wired.
		return [];
	}
}
