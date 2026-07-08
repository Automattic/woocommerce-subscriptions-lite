<?php
/**
 * ContractCreationHandler - turns a processed checkout order into contracts.
 *
 * The checkout seam: the first moment a real customer's checkout fills the
 * engine's contract table. For each order line item carrying a `_wcsl_selling_plan_id`
 * the handler resolves the chosen {@see Plan} and hands the order + plan to the
 * engine's {@see ContractFactory::create_from_order()}, then schedules the first
 * renewal through {@see RenewalWiring}. The handler is intentionally thin - find
 * qualifying line items, call the engine, log on failure, never block checkout.
 *
 * The engine owns the contract-building and the order <-> contract linkage; Lite
 * owns only the driver - the checkout-hook attachment and the per-item iteration.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\ContractFactory;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\OrderLinkage;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring;

defined( 'ABSPATH' ) || exit;

/**
 * Create one contract per subscription line item on checkout completion.
 *
 * Construct via the no-arg constructor in production (engine defaults); tests
 * inject fake factory / plan-finder / scheduler / finder seams to exercise the
 * iteration, idempotency, and error paths without a database.
 */
final class ContractCreationHandler {

	/**
	 * Order line-item meta key holding the selected plan id. The cart writer
	 * lays this down on the order line item at checkout; the engine's contract
	 * data model is keyed off it.
	 */
	private const SELLING_PLAN_META = '_wcsl_selling_plan_id';

	/**
	 * Logger source tag.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * Contract factory. Production: the engine's `ContractFactory`.
	 *
	 * @var callable(WC_Order, Plan): Contract
	 */
	private $contract_factory;

	/**
	 * Plan finder. Production: `PlanRepository::find()`.
	 *
	 * @var callable(int): ?Plan
	 */
	private $plan_finder;

	/**
	 * First-renewal scheduler. Production: `RenewalWiring::schedule_first_renewal()`.
	 *
	 * @var callable(Contract): bool
	 */
	private $scheduler;

	/**
	 * Existing-contract finder for the idempotency guard. Production reads the
	 * engine's order-linkage meta and loads the contract by id.
	 *
	 * @var callable(WC_Order): ?Contract
	 */
	private $contract_finder;

	/**
	 * Construct the handler.
	 *
	 * @param (callable(WC_Order, Plan): Contract)|null $contract_factory Factory; defaults to the engine `ContractFactory`.
	 * @param (callable(int): ?Plan)|null               $plan_finder      Plan finder; defaults to `PlanRepository::find()`.
	 * @param (callable(Contract): bool)|null           $scheduler        First-renewal scheduler; defaults to the renewal wiring.
	 * @param (callable(WC_Order): ?Contract)|null      $contract_finder  Existing-contract finder; defaults to the order-linkage lookup.
	 */
	public function __construct(
		?callable $contract_factory = null,
		?callable $plan_finder = null,
		?callable $scheduler = null,
		?callable $contract_finder = null
	) {
		$this->contract_factory = $contract_factory ?? static function ( WC_Order $order, Plan $plan ): Contract {
			return ( new ContractFactory() )->create_from_order( $order, $plan );
		};
		$this->plan_finder      = $plan_finder ?? static function ( int $plan_id ): ?Plan {
			return ( new PlanRepository() )->find( $plan_id );
		};
		$this->scheduler        = $scheduler ?? static function ( Contract $contract ): bool {
			return ( new RenewalWiring() )->schedule_first_renewal( $contract );
		};
		$this->contract_finder  = $contract_finder ?? static function ( WC_Order $order ): ?Contract {
			$contract_id = (int) $order->get_meta( OrderLinkage::META_CONTRACT_ID );
			if ( $contract_id <= 0 ) {
				return null;
			}
			return ( new ContractRepository() )->find( $contract_id );
		};
	}

	/**
	 * Wire the handler on the classic checkout-processed hook.
	 *
	 * Slice 0 targets the classic shortcode checkout
	 * (`woocommerce_checkout_order_processed`); the Blocks / Store API seam is a
	 * later widening step. Called once from the package bootstrap.
	 */
	public static function register(): void {
		$instance = new self();
		add_action(
			'woocommerce_checkout_order_processed',
			[ $instance, 'create_contracts_for_order' ],
			10,
			3
		);
	}

	/**
	 * For each subscription line item on `$order`, create a contract and
	 * schedule its first renewal. No-op when the order already has a contract.
	 *
	 * Idempotency is all-or-nothing: any existing contract for this order skips
	 * the whole handler, so a double-fired checkout hook cannot create duplicate
	 * contracts. A throwing factory is logged and the loop continues, so one bad
	 * line item does not strand contracts for legitimate siblings, and checkout
	 * completion is never blocked.
	 *
	 * @param int                  $order_id    The processed order id.
	 * @param array<string, mixed> $posted_data Checkout payload (unused).
	 * @param WC_Order|null        $order       The order WooCommerce just persisted.
	 */
	public function create_contracts_for_order( int $order_id, array $posted_data, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( null !== ( $this->contract_finder )( $order ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$plan_id = (int) $item->get_meta( self::SELLING_PLAN_META );
			if ( $plan_id <= 0 ) {
				continue;
			}

			$plan = ( $this->plan_finder )( $plan_id );
			if ( ! $plan instanceof Plan ) {
				wc_get_logger()->error(
					sprintf(
						'ContractCreationHandler: plan %d not found at checkout completion for order %d line item %d - skipping.',
						$plan_id,
						$order_id,
						$item->get_id()
					),
					[ 'source' => self::LOG_SOURCE ]
				);
				continue;
			}

			try {
				$contract = ( $this->contract_factory )( $order, $plan );
			} catch ( Throwable $e ) {
				wc_get_logger()->error(
					sprintf(
						'ContractCreationHandler: failed to create contract for order %d line item %d plan %d: %s',
						$order_id,
						$item->get_id(),
						$plan_id,
						$e->getMessage()
					),
					[ 'source' => self::LOG_SOURCE ]
				);
				continue;
			}

			( $this->scheduler )( $contract );
		}
	}
}
