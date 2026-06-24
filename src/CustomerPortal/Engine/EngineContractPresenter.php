<?php
/**
 * EngineContractPresenter - the production {@see ContractPresenter}, over the read model.
 *
 * Delegates each reduction to the engine's
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Integration\Read\ContractReadModel}.
 * This is the one place Lite touches that final read model for the portal reads;
 * keeping it behind the {@see ContractPresenter} port lets {@see EngineDataProvider}
 * be unit-tested with a double in place of the (un-mockable) final read model.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Read\ContractReadModel;

defined( 'ABSPATH' ) || exit;

/**
 * Read-model-backed {@see ContractPresenter}.
 */
final class EngineContractPresenter implements ContractPresenter {

	/**
	 * The engine read model.
	 *
	 * @var ContractReadModel
	 */
	private $read_model;

	/**
	 * Build the presenter over the engine read model.
	 *
	 * @param ContractReadModel|null $read_model Engine read model; a default instance when omitted.
	 */
	public function __construct( ?ContractReadModel $read_model = null ) {
		$this->read_model = $read_model ?? new ContractReadModel();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Contract $contract Hydrated contract.
	 * @return array<string, mixed>
	 */
	public function contract_to_row( Contract $contract ): array {
		return $this->read_model->contract_to_row( $contract );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Contract $contract Hydrated contract.
	 * @return array<string, mixed>
	 */
	public function contract_to_detail( Contract $contract ): array {
		return $this->read_model->contract_to_detail( $contract );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $contract_id Contract id.
	 * @return array<int, array<string, mixed>>
	 */
	public function related_orders( int $contract_id ): array {
		return $this->read_model->related_orders( $contract_id );
	}
}
