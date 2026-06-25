<?php
/**
 * ApiSubscriptionsReader - the production {@see SubscriptionsReader}, over the engine facade.
 *
 * Delegates each read to the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade - the one
 * surface Lite consumes engine functionality through. It performs no mapping: it hands the
 * facade's interim return types ({@see Contract}, `WC_Order`) back to the
 * {@see \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider}, which
 * reduces them to the portal's domain-ish arrays.
 *
 * Keeping the static, database-bound facade behind the {@see SubscriptionsReader} port is
 * what lets the provider's mapping be unit-tested with a double in place of the facade.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine;

use WC_Order;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * Facade-backed {@see SubscriptionsReader}.
 */
final class ApiSubscriptionsReader implements SubscriptionsReader {

	/**
	 * {@inheritDoc}
	 *
	 * @param int $customer_id Owning customer id.
	 * @return array<int, Contract>
	 */
	public function list_for_customer( int $customer_id ): array {
		return Subscriptions::list_for_customer( $customer_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $contract_id Contract id.
	 * @param int $customer_id Customer that must own the contract.
	 * @return Contract|null
	 */
	public function get_for_customer( int $contract_id, int $customer_id ): ?Contract {
		return Subscriptions::get_for_customer( $contract_id, $customer_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $contract_id Contract id.
	 * @return array<int, WC_Order>
	 */
	public function get_related_orders( int $contract_id ): array {
		return Subscriptions::get_related_orders( $contract_id );
	}
}
