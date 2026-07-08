<?php
/**
 * ContractCreationHandler - turns a paid checkout order into a subscription contract.
 *
 * Fires when an order reaches a paid status (`woocommerce_order_status_changed`,
 * gated by `is_paid()`) - covering immediate, $0, and offline (BACS/cheque)
 * payments alike - and groups the order's plan-carrying
 * line items by selling plan. A single clean group becomes one contract via the
 * engine's {@see ContractFactory::create_from_order()}, then schedules its first
 * renewal. Anything that cannot be one contract - two different plans (Premium's
 * multiple-contracts case) or a mix of plan and one-time lines (WOOSUBS-1775) - is
 * left uncreated with an order note, a log line, and a flag the order-received page
 * reads. The handler never throws out of the hook and never blocks checkout.
 *
 * The engine owns contract-building and the order <-> contract linkage; Lite owns
 * the driver: the paid-order hook, applicability re-validation, and the grouping.
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
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring;

defined( 'ABSPATH' ) || exit;

/**
 * Create one contract per paid subscription order.
 *
 * Construct via the no-arg constructor in production (engine defaults); tests
 * inject fake factory / plan-finder / applicability / scheduler / finder seams to
 * exercise the grouping, idempotency, and error paths.
 */
final class ContractCreationHandler {

	/**
	 * Order line-item meta key holding the selected plan id. The cart writer lays
	 * this down on the order line item at checkout.
	 */
	private const SELLING_PLAN_META = '_wcsl_selling_plan_id';

	/**
	 * Order meta flag recording that a subscription was intended but not created,
	 * holding the reason. Read by the order-received page to warn the shopper.
	 */
	public const CREATION_DEFERRED_META = '_wcsl_subscription_creation_deferred';

	/**
	 * Deferral reason: the order carries two or more distinct plans (Lite is one
	 * contract per checkout; multiple contracts are Premium).
	 */
	public const REASON_DIVERGENT_PLANS = 'divergent_plans';

	/**
	 * Deferral reason: the order mixes a plan line with a one-time (or now
	 * unresolvable) line. Mixed carts are WOOSUBS-1775.
	 */
	public const REASON_MIXED_CART = 'mixed_cart';

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
	 * Applicability gate. Production: `ProductPlanResolver::is_plan_applicable_to_product()`.
	 *
	 * @var callable(int, int): bool
	 */
	private $applicability;

	/**
	 * First-renewal scheduler. Production: `RenewalWiring::schedule_first_renewal()`.
	 *
	 * @var callable(Contract): bool
	 */
	private $scheduler;

	/**
	 * Existing-contract finder for the idempotency guard. Production reads the
	 * order-linkage meta and loads the contract by id.
	 *
	 * @var callable(WC_Order): ?Contract
	 */
	private $contract_finder;

	/**
	 * Construct the handler.
	 *
	 * @param (callable(WC_Order, Plan): Contract)|null $contract_factory Factory; defaults to the engine `ContractFactory`.
	 * @param (callable(int): ?Plan)|null               $plan_finder      Plan finder; defaults to `PlanRepository::find()`.
	 * @param (callable(int, int): bool)|null           $applicability    Applicability gate; defaults to `ProductPlanResolver`.
	 * @param (callable(Contract): bool)|null           $scheduler        First-renewal scheduler; defaults to the renewal wiring.
	 * @param (callable(WC_Order): ?Contract)|null      $contract_finder  Existing-contract finder; defaults to the order-linkage lookup.
	 */
	public function __construct(
		?callable $contract_factory = null,
		?callable $plan_finder = null,
		?callable $applicability = null,
		?callable $scheduler = null,
		?callable $contract_finder = null
	) {
		$this->contract_factory = $contract_factory ?? static function ( WC_Order $order, Plan $plan ): Contract {
			return ( new ContractFactory() )->create_from_order( $order, $plan );
		};
		$this->plan_finder      = $plan_finder ?? static function ( int $plan_id ): ?Plan {
			return ( new PlanRepository() )->find( $plan_id );
		};
		$this->applicability    = $applicability ?? static function ( int $plan_id, int $product_id ): bool {
			return ( new ProductPlanResolver() )->is_plan_applicable_to_product( $plan_id, $product_id );
		};
		$this->scheduler        = $scheduler ?? static function ( Contract $contract ): bool {
			return ( new RenewalWiring() )->schedule_first_renewal( $contract );
		};
		$this->contract_finder  = $contract_finder ?? static function ( WC_Order $order ): ?Contract {
			$contract_id = (int) $order->get_meta( OrderLinkage::META_CONTRACT_ID );
			return $contract_id > 0 ? ( new ContractRepository() )->find( $contract_id ) : null;
		};
	}

	/**
	 * Wire the handler on the order reaching a paid status.
	 *
	 * `woocommerce_order_status_changed` fires for every checkout surface (classic
	 * and Blocks/Store API) and whenever an order *becomes* paid by any route: an
	 * immediate gateway landing on processing/completed, a $0 order, or an offline
	 * gateway (BACS, cheque) whose order sits on-hold until a merchant confirms the
	 * payment later. `woocommerce_payment_complete` never fires for that offline
	 * case, so it would silently drop those subscriptions. The `is_paid()` guard in
	 * the callback filters this generic signal down to paid transitions (honouring a
	 * store's custom paid statuses), and the idempotency guard makes the
	 * processing -> completed double-fire safe.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'woocommerce_order_status_changed', [ $instance, 'create_contracts_for_order' ], 10, 1 );
	}

	/**
	 * Create the contract for a paid order, or record a deferral when the order
	 * cannot be represented as a single contract. A no-op unless the order is paid.
	 * Never throws out of the hook.
	 *
	 * @param int $order_id The id of the order whose status changed.
	 */
	public function create_contracts_for_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->is_paid() ) {
			return;
		}

		// Idempotency: a repeat paid-status transition (e.g. processing -> completed)
		// must neither double-create a contract nor duplicate a deferral note.
		if ( null !== ( $this->contract_finder )( $order )
			|| '' !== (string) $order->get_meta( self::CREATION_DEFERRED_META ) ) {
			return;
		}

		$outcome = $this->classify_order( $order );

		if ( null !== $outcome['reason'] ) {
			$this->record_deferral( $order, $outcome['reason'] );
			return;
		}

		if ( ! $outcome['plan'] instanceof Plan ) {
			return; // No subscription line on the order.
		}

		try {
			$contract = ( $this->contract_factory )( $order, $outcome['plan'] );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'ContractCreationHandler: failed to create a contract for order %d: %s', $order_id, $e->getMessage() ),
				[ 'source' => self::LOG_SOURCE ]
			);
			return;
		}

		( $this->scheduler )( $contract );
	}

	/**
	 * Inspect the order's product lines and decide the outcome.
	 *
	 * Returns the single `Plan` when one re-validated plan covers every plan line
	 * and there is no other product line; a `reason` when a subscription was
	 * intended but the order cannot be one contract; or neither when there is no
	 * subscription line at all.
	 *
	 * @param WC_Order $order The paid order.
	 * @return array{plan: Plan|null, reason: string|null}
	 */
	private function classify_order( WC_Order $order ): array {
		$plan_ids  = [];
		$has_plain = false; // A product line with no plan, or one whose plan no longer applies.

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$plan_id = (int) $item->get_meta( self::SELLING_PLAN_META );
			if ( $plan_id <= 0 || ! ( $this->applicability )( $plan_id, $item->get_product_id() ) ) {
				$has_plain = true;
				continue;
			}

			$plan_ids[ $plan_id ] = true;
		}

		if ( empty( $plan_ids ) ) {
			return [
				'plan'   => null,
				'reason' => null,
			];
		}

		if ( count( $plan_ids ) > 1 ) {
			return [
				'plan'   => null,
				'reason' => self::REASON_DIVERGENT_PLANS,
			];
		}

		if ( $has_plain ) {
			return [
				'plan'   => null,
				'reason' => self::REASON_MIXED_CART,
			];
		}

		$plan = ( $this->plan_finder )( (int) array_key_first( $plan_ids ) );

		return [
			'plan'   => $plan instanceof Plan ? $plan : null,
			'reason' => null,
		];
	}

	/**
	 * Record that a subscription was intended but not created: an order note, a
	 * log line, and the meta flag the order-received page reads.
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $reason One of the REASON_* constants.
	 */
	private function record_deferral( WC_Order $order, string $reason ): void {
		$order->add_order_note(
			sprintf(
				/* translators: %s: machine-readable reason code (e.g. "divergent_plans"). */
				__( 'No subscription was created automatically for this order (reason: %s). This order needs manual review.', 'woocommerce-subscriptions-lite' ),
				$reason
			)
		);
		wc_get_logger()->warning(
			sprintf( 'ContractCreationHandler: deferred contract creation for order %d (%s).', $order->get_id(), $reason ),
			[ 'source' => self::LOG_SOURCE ]
		);
		$order->update_meta_data( self::CREATION_DEFERRED_META, $reason );
		$order->save();
	}
}
