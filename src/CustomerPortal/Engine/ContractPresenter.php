<?php
/**
 * ContractPresenter - the narrow engine read-model seam the EngineDataProvider uses.
 *
 * A Lite-internal port over the engine's {@see \Automattic\WooCommerce\SubscriptionsEngine\Integration\Read\ContractReadModel}:
 * it reduces a hydrated {@see Contract} to the portal's domain-ish list-row and
 * detail arrays, and reads a contract's related orders as domain-ish arrays. The
 * default adapter ({@see EngineContractPresenter}) delegates to that read model;
 * the seam exists so the provider can be unit-tested with a double, because the
 * engine read model is a final class that cannot be mocked directly.
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
 * Reduces engine contracts + orders to the portal's domain-ish arrays.
 */
interface ContractPresenter {

	/**
	 * Reduce a contract to the domain-ish list-row array.
	 *
	 * @param Contract $contract Hydrated contract.
	 * @return array<string, mixed>
	 */
	public function contract_to_row( Contract $contract ): array;

	/**
	 * Reduce a contract to the domain-ish detail array.
	 *
	 * @param Contract $contract Hydrated contract.
	 * @return array<string, mixed>
	 */
	public function contract_to_detail( Contract $contract ): array;

	/**
	 * The contract's related orders, as domain-ish arrays, newest first.
	 *
	 * @param int $contract_id Contract id.
	 * @return array<int, array<string, mixed>>
	 */
	public function related_orders( int $contract_id ): array;
}
