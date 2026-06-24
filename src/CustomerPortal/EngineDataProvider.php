<?php
/**
 * EngineDataProvider - reads customer-portal data from the subscriptions engine.
 *
 * The engine-backed implementation of {@see DataProvider} and the production
 * default (see the provider resolver). It reads through two narrow Lite-internal
 * ports - {@see ContractReader} (the engine's contract storage) and
 * {@see ContractPresenter} (the engine's read model) - and returns the same
 * domain-ish arrays {@see FixtureDataProvider} returns, so {@see ViewModel} and
 * the templates render identically whichever provider is active.
 *
 * Ownership / not-found: {@see self::get_contract()} enforces the asymmetric
 * not-found rule - an unknown id and a contract owned by another customer both
 * return null - via the engine's ownership guard, so the portal never confirms a
 * contract the requester does not own. {@see self::get_related_orders()} is gated
 * by call order: the endpoints resolve + ownership-check the contract via
 * {@see self::get_contract()} before reading its orders, so a foreign or unknown
 * contract is already turned away (returns null) before any order read runs and
 * no orders can leak across customers.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\ContractPresenter;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\ContractReader;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\EngineContractPresenter;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\EngineContractReader;

defined( 'ABSPATH' ) || exit;

/**
 * Engine-backed implementation of {@see DataProvider}.
 */
final class EngineDataProvider implements DataProvider {

	/**
	 * The engine contract read port.
	 *
	 * @var ContractReader
	 */
	private $reader;

	/**
	 * The engine read-model port.
	 *
	 * @var ContractPresenter
	 */
	private $presenter;

	/**
	 * Build the provider over the engine read ports.
	 *
	 * Both default to the production adapters that delegate to the engine's
	 * contract repository and read model; tests pass doubles in their place.
	 *
	 * @param ContractReader|null    $reader    Contract read port; default engine adapter when omitted.
	 * @param ContractPresenter|null $presenter Read-model port; default engine adapter when omitted.
	 */
	public function __construct( ?ContractReader $reader = null, ?ContractPresenter $presenter = null ) {
		$this->reader    = $reader ?? new EngineContractReader();
		$this->presenter = $presenter ?? new EngineContractPresenter();
	}

	/**
	 * Return the customer's contracts as domain-ish list-row arrays.
	 *
	 * Reads the customer-scoped contract list and reduces each contract to the
	 * row shape {@see ViewModel} consumes. The empty array means "no
	 * subscriptions".
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_contracts_for_customer( int $customer_id ): array {
		$rows = [];
		foreach ( $this->reader->find_by_customer_id( $customer_id ) as $contract ) {
			$rows[] = $this->presenter->contract_to_row( $contract );
		}
		return $rows;
	}

	/**
	 * Return one contract's detail as a domain-ish array, ownership-checked.
	 *
	 * Enforces the asymmetric not-found rule first: a contract the customer does
	 * not own - whether it does not exist or belongs to someone else - returns
	 * null before any detail read. An owned contract whose row then turns up
	 * missing (a delete racing the guard) also returns null.
	 *
	 * @param int $contract_id The contract id from the URL.
	 * @param int $customer_id The logged-in customer id (ownership check).
	 * @return array<string, mixed>|null
	 */
	public function get_contract( int $contract_id, int $customer_id ): ?array {
		if ( ! $this->reader->is_owned_by( $contract_id, $customer_id ) ) {
			return null;
		}

		$contract = $this->reader->find( $contract_id );
		if ( null === $contract ) {
			return null;
		}

		return $this->presenter->contract_to_detail( $contract );
	}

	/**
	 * Return the related orders for a contract as domain-ish arrays.
	 *
	 * Ownership is enforced by the caller: the endpoints resolve + ownership-check
	 * the contract via {@see self::get_contract()} (which returns null and short-
	 * circuits the render for a foreign or unknown contract) before this is
	 * reached, so this read only ever runs for a contract the customer owns and
	 * orders cannot leak across customers.
	 *
	 * @param int $contract_id The contract id.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_related_orders( int $contract_id ): array {
		return $this->presenter->related_orders( $contract_id );
	}
}
