<?php
/**
 * SubscriptionsReader - the single engine read seam behind the EngineDataProvider.
 *
 * A Lite-internal port over the slice of the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade the
 * customer portal reads: the customer-scoped list, the ownership-checked fetch, and a
 * contract's related orders. The default adapter ({@see ApiSubscriptionsReader})
 * delegates each call straight to that facade.
 *
 * The seam exists for ONE reason: the facade is static and database-bound, so the
 * {@see \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider}'s
 * contract-to-array mapping cannot be unit-tested against it directly. This narrow
 * interface lets a test inject a double that returns engine value objects without a
 * booted database, while production wires the real facade adapter.
 *
 * It returns the engine's interim types verbatim ({@see Contract} and `WC_Order`);
 * all presentation reduction is the provider's job, so this stays a thin pass-through.
 *
 * This is an INTERNAL Lite seam, not a consumer-implementable public interface - Lite
 * owns both the interface and its sole production implementation.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine;

use WC_Order;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Reads subscriptions from the engine facade for the customer portal.
 */
interface SubscriptionsReader {

	/**
	 * The customer's contracts, newest first, each with its plan snapshot hydrated.
	 *
	 * Owner-scoped by construction: the customer id is supplied by the caller, never
	 * inferred, so the read never returns another customer's contracts.
	 *
	 * @param int $customer_id Owning customer id.
	 * @return array<int, Contract> The customer's contracts, newest first.
	 */
	public function list_for_customer( int $customer_id ): array;

	/**
	 * Fetch a contract the customer owns, or null - the ownership-checked read.
	 *
	 * Returns null for BOTH an unknown id AND a contract owned by another customer (the
	 * asymmetric not-found rule), so the caller cannot distinguish "not yours" from
	 * "does not exist". The returned contract carries its plan snapshot.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $customer_id Customer that must own the contract.
	 * @return Contract|null The contract when owned by `$customer_id`, else null.
	 */
	public function get_for_customer( int $contract_id, int $customer_id ): ?Contract;

	/**
	 * The orders related to a contract (origin, renewals, switches, resubscribes),
	 * newest first, as live `WC_Order` objects.
	 *
	 * @param int $contract_id Contract id.
	 * @return array<int, WC_Order> Related orders, newest first.
	 */
	public function get_related_orders( int $contract_id ): array;
}
