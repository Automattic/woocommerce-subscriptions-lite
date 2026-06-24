<?php
/**
 * ContractReader - the narrow engine read seam the EngineDataProvider depends on.
 *
 * A Lite-internal port over the slice of the engine's contract storage the portal
 * needs: the customer-scoped list, the ownership guard, and the by-id fetch. The
 * default adapter ({@see EngineContractReader}) delegates to the engine's
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository};
 * the seam exists so the provider can be unit-tested with a double, because the
 * engine repository is a final class that cannot be mocked directly.
 *
 * This is an INTERNAL Lite seam, not a consumer-implementable public interface -
 * Lite owns both the interface and its sole production implementation.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Reads contracts from the engine for the customer portal.
 */
interface ContractReader {

	/**
	 * The customer's contracts, newest first.
	 *
	 * @param int $customer_id Owning customer id.
	 * @return array<int, Contract> Hydrated contracts the customer owns.
	 */
	public function find_by_customer_id( int $customer_id ): array;

	/**
	 * Whether `$contract_id` is owned by `$customer_id` - the ownership guard.
	 *
	 * Returns false for BOTH an unknown contract and a contract owned by someone
	 * else, so the caller cannot distinguish "not yours" from "does not exist".
	 *
	 * @param int $contract_id Contract id.
	 * @param int $customer_id Customer to check ownership against.
	 */
	public function is_owned_by( int $contract_id, int $customer_id ): bool;

	/**
	 * Fetch a single hydrated contract by id, or null when the row is gone.
	 *
	 * @param int $contract_id Contract id.
	 * @return Contract|null
	 */
	public function find( int $contract_id ): ?Contract;
}
