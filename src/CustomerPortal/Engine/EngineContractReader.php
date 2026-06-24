<?php
/**
 * EngineContractReader - the production {@see ContractReader}, over the engine repository.
 *
 * Delegates each read to the engine's
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository}.
 * This is the one place Lite touches that final repository for the portal reads;
 * keeping it behind the {@see ContractReader} port lets {@see EngineDataProvider}
 * be unit-tested with a double in place of the (un-mockable) final repository.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Engine-repository-backed {@see ContractReader}.
 */
final class EngineContractReader implements ContractReader {

	/**
	 * The engine contract repository.
	 *
	 * @var ContractRepository
	 */
	private $contracts;

	/**
	 * Build the reader over the engine contract repository.
	 *
	 * @param ContractRepository|null $contracts Engine repository; a default instance when omitted.
	 */
	public function __construct( ?ContractRepository $contracts = null ) {
		$this->contracts = $contracts ?? new ContractRepository();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $customer_id Owning customer id.
	 * @return array<int, Contract>
	 */
	public function find_by_customer_id( int $customer_id ): array {
		return $this->contracts->find_by_customer_id( $customer_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $contract_id Contract id.
	 * @param int $customer_id Customer to check ownership against.
	 */
	public function is_owned_by( int $contract_id, int $customer_id ): bool {
		return $this->contracts->is_owned_by( $contract_id, $customer_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $contract_id Contract id.
	 * @return Contract|null
	 */
	public function find( int $contract_id ): ?Contract {
		return $this->contracts->find( $contract_id );
	}
}
