<?php
/**
 * ContractCreationHandler - turns a paid checkout order into a subscription contract.
 *
 * Fires when an order reaches a paid status (`woocommerce_order_status_changed`,
 * gated by `is_paid()`) - covering immediate, $0, and offline (BACS/cheque)
 * payments alike - and groups the order's plan-carrying line items by selling
 * plan. A single clean group becomes one contract, then its first renewal is
 * armed. Anything that cannot be one contract - two different plans (Premium's
 * multiple-contracts case) or a mix of plan and one-time lines (WOOSUBS-1775) - is
 * left uncreated with an order note, a log line, and a flag the order-received page
 * reads. The handler never throws out of the hook and never blocks checkout.
 *
 * Lite maps the order to explicit contract fields (customer, payment, addresses,
 * plan lines, recurring totals) and writes them through the engine's
 * contracts facade; the engine records what it is given and never reads the order.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\AddressFields;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring;

defined( 'ABSPATH' ) || exit;

/**
 * Create one contract per paid subscription order.
 *
 * Collaborators (the engine facades, applicability resolver, and renewal wiring)
 * are called directly; the grouping, mapping, idempotency, and error paths are
 * covered by integration tests against real WooCommerce.
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
	 * Deferral reason: the order mixes a plan line with a one-time line. Mixed
	 * carts are WOOSUBS-1775.
	 */
	public const REASON_MIXED_CART = 'mixed_cart';

	/**
	 * Deferral reason: a line carries a plan that no longer resolves as a billable
	 * Lite plan for its product (deleted, not billable, or no longer applicable).
	 */
	public const REASON_PLAN_UNAVAILABLE = 'plan_unavailable';

	/**
	 * Deferral reason: contract creation failed before any contract was written.
	 */
	public const REASON_CREATION_FAILED = 'creation_failed';

	/**
	 * Logger source tag.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

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
		add_action( 'woocommerce_order_status_changed', [ new self(), 'create_contracts_for_order' ], 10, 1 );
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

		$outcome = $this->classify_order( $order );
		if ( null === $outcome['reason'] && ! $outcome['plan'] instanceof PlanView ) {
			return; // No subscription line on the order.
		}

		// Idempotency: a repeat paid-status transition (e.g. processing -> completed)
		// must neither double-create a contract nor duplicate a deferral note.
		if ( '' !== (string) $order->get_meta( self::CREATION_DEFERRED_META )
			|| [] !== Contracts::find_by_origin_order( $order_id ) ) {
			return;
		}

		if ( null !== $outcome['reason'] ) {
			$this->record_deferral( $order, $outcome['reason'] );
			return;
		}

		try {
			$contract = $this->create_contract( $order, $outcome['plan'] );
		} catch ( Throwable $e ) {
			$this->log_error( sprintf( 'failed to create a contract for order %d: %s', $order_id, $e->getMessage() ) );
			try {
				// A draft blocks retries through the idempotency check; without one, the
				// deferral flag does, so a later paid transition does not fail the same way.
				if ( ! $this->note_stuck_draft( $order ) ) {
					$this->record_deferral( $order, self::REASON_CREATION_FAILED );
				}
			} catch ( Throwable $note_error ) {
				$this->log_error( sprintf( 'failed to record the failed creation on order %d: %s', $order_id, $note_error->getMessage() ) );
			}
			return;
		}

		if ( null === $contract ) {
			wc_get_logger()->warning(
				sprintf( 'ContractCreationHandler: the contract for order %d no longer exists at activation; its first renewal was not scheduled.', $order_id ),
				[ 'source' => self::LOG_SOURCE ]
			);
			return;
		}

		try {
			( new RenewalWiring() )->schedule_first_renewal( $contract );
		} catch ( Throwable $e ) {
			$this->log_error( sprintf( 'failed to schedule the first renewal of contract %d: %s', $contract->get_id(), $e->getMessage() ) );
		}
	}

	/**
	 * Log an error line under the Lite source.
	 *
	 * @param string $message Message, without the class prefix.
	 */
	private function log_error( string $message ): void {
		wc_get_logger()->error( 'ContractCreationHandler: ' . $message, [ 'source' => self::LOG_SOURCE ] );
	}

	/**
	 * When creation failed after the draft was written (cycle write or activation),
	 * leave a merchant-visible order note naming the draft.
	 *
	 * @param WC_Order $order The order.
	 * @return bool Whether the order has a contract (a draft was noted, or another exists).
	 */
	private function note_stuck_draft( WC_Order $order ): bool {
		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		foreach ( $contracts as $contract ) {
			if ( ContractStatus::DRAFT !== $contract->get_status() ) {
				continue;
			}
			$order->add_order_note(
				sprintf(
					/* translators: %d: subscription (contract) id. */
					__( 'Subscription #%d was created as a draft but could not be activated. This order needs manual review.', 'woocommerce-subscriptions-lite' ),
					$contract->get_id()
				)
			);
		}

		return [] !== $contracts;
	}

	/**
	 * Create the contract for a paid order on `$plan`: a draft with the mapped fields,
	 * then cycle 1 (billed by the order), then activation with the first renewal date.
	 * Activation comes last, so a failure part-way leaves a draft that is never due.
	 *
	 * The first renewal date comes from {@see BillingTerms::first_renewal_from()}, the
	 * same parse that decides whether a plan is billable.
	 *
	 * @param WC_Order $order The paid order.
	 * @param PlanView $plan  The order's selling plan.
	 * @return ContractView|null The active contract; null when it was deleted before activation.
	 * @throws Throwable When the billing payload does not parse or an engine write fails.
	 */
	public function create_contract( WC_Order $order, PlanView $plan ): ?ContractView {
		$plan_id    = $plan->get_id();
		$plan_lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $plan_id === (int) $item->get_meta( self::SELLING_PLAN_META ) ) {
				$plan_lines[] = $item;
			}
		}

		$paid  = $order->get_date_paid();
		$start = null !== $paid
			? new DateTimeImmutable( '@' . $paid->getTimestamp() )
			: new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$next  = BillingTerms::first_renewal_from( $plan, $start );

		$totals = $this->get_recurring_totals( $order, $plan_lines );

		$contract = Contracts::create(
			[
				'extension_slug'       => Package::EXTENSION_SLUG,
				'status'               => ContractStatus::DRAFT,
				'customer_id'          => $order->get_customer_id() > 0 ? $order->get_customer_id() : null,
				'currency'             => $order->get_currency(),
				'selling_plan_id'      => $plan_id,
				'origin_order_id'      => $order->get_id(),
				'payment_method'       => '' !== $order->get_payment_method() ? $order->get_payment_method() : null,
				'payment_method_title' => '' !== $order->get_payment_method_title() ? $order->get_payment_method_title() : null,
				'payment_token_id'     => $this->get_payment_token_id( $order ),
				'start_gmt'            => $start,
				'billing_total'        => $totals['billing_total'],
				'discount_total'       => $totals['discount_total'],
				'shipping_total'       => $totals['shipping_total'],
				'tax_total'            => $totals['tax_total'],
				'items'                => $this->map_items( $plan_lines ),
				'addresses'            => [
					'billing'  => $this->map_address( $order, 'billing' ),
					'shipping' => $this->map_address( $order, 'shipping' ),
				],
			]
		);

		Contracts::add_cycle(
			$contract->get_id(),
			[
				'status'         => CycleStatus::BILLED,
				'count'          => 1,
				'order_id'       => $order->get_id(),
				'starts_at_gmt'  => $start,
				'ends_at_gmt'    => $next,
				'expected_total' => $totals['billing_total'],
				'currency'       => $order->get_currency(),
			]
		);

		return Contracts::update(
			$contract->get_id(),
			[
				'status'           => ContractStatus::ACTIVE,
				'next_payment_gmt' => $next,
			]
		);
	}

	/**
	 * The recurring money facts from the plan lines plus shipping. Fees and other
	 * lines never enter: they are one-time charges of the checkout order.
	 *
	 * @param WC_Order                          $order      The order.
	 * @param array<int, WC_Order_Item_Product> $plan_lines The order's plan lines.
	 * @return array{billing_total: string, discount_total: string, shipping_total: string, tax_total: string}
	 */
	private function get_recurring_totals( WC_Order $order, array $plan_lines ): array {
		$lines    = 0.0;
		$line_tax = 0.0;
		$discount = 0.0;
		foreach ( $plan_lines as $item ) {
			$lines    += (float) $item->get_total();
			$line_tax += (float) $item->get_total_tax();
			$discount += (float) $item->get_subtotal() - (float) $item->get_total();
		}

		$shipping     = (float) $order->get_shipping_total();
		$shipping_tax = (float) $order->get_shipping_tax();
		$precision    = wc_get_rounding_precision();

		return [
			'billing_total'  => wc_format_decimal( $lines + $line_tax + $shipping + $shipping_tax, $precision ),
			'discount_total' => wc_format_decimal( $discount, $precision ),
			'shipping_total' => wc_format_decimal( $shipping, $precision ),
			'tax_total'      => wc_format_decimal( $line_tax + $shipping_tax, $precision ),
		];
	}

	/**
	 * Map plan lines to contract item rows.
	 *
	 * @param array<int, WC_Order_Item_Product> $plan_lines The order's plan lines.
	 * @return array<int, array<string, mixed>>
	 */
	private function map_items( array $plan_lines ): array {
		$items = [];
		foreach ( $plan_lines as $item ) {
			$items[] = [
				'item_name'    => $item->get_name(),
				'item_type'    => 'line_item',
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => (string) $item->get_quantity(),
				'subtotal'     => (string) $item->get_subtotal(),
				'total'        => (string) $item->get_total(),
				'taxes'        => $item->get_taxes(),
			];
		}

		return $items;
	}

	/**
	 * One of the order's addresses, limited to the contract address fields.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $type  `billing` or `shipping`.
	 * @return array<string, mixed>
	 */
	private function map_address( WC_Order $order, string $type ): array {
		return array_intersect_key( (array) $order->get_address( $type ), array_flip( AddressFields::FIELDS ) );
	}

	/**
	 * The payment token the order was charged with (the last one recorded), or null.
	 *
	 * @param WC_Order $order The order.
	 */
	private function get_payment_token_id( WC_Order $order ): ?int {
		$tokens = $order->get_payment_tokens();
		$token  = [] !== $tokens ? (int) end( $tokens ) : 0;

		return $token > 0 ? $token : null;
	}

	/**
	 * Inspect the order's product lines and decide the outcome.
	 *
	 * A line's plan resolves with the rule cart pricing used
	 * ({@see ProductPlanResolver::get_line_plan()}: any status, billable) and must still
	 * be selected by the product's applicability, so a plan archived after add-to-cart,
	 * or before an offline payment is confirmed, still becomes a contract. Returns the single plan when it covers every plan line and
	 * there is no other product line; a `reason` when a subscription was intended but
	 * the order cannot be one contract (including a line whose plan no longer
	 * resolves); or neither when there is no subscription line at all.
	 *
	 * @param WC_Order $order The paid order.
	 * @return array{plan: PlanView|null, reason: string|null}
	 */
	private function classify_order( WC_Order $order ): array {
		$resolver    = new ProductPlanResolver();
		$plans       = [];
		$has_plain   = false; // A product line with no plan.
		$unavailable = false; // A line whose stamped plan no longer resolves.

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$plan_id = (int) $item->get_meta( self::SELLING_PLAN_META );
			if ( $plan_id <= 0 ) {
				$has_plain = true;
				continue;
			}

			$plan = $resolver->get_line_plan( $plan_id );
			if ( null === $plan || ! $resolver->applies_to_product( $plan, $item->get_product_id() ) ) {
				$unavailable = true;
				continue;
			}

			$plans[ $plan_id ] = $plan;
		}

		$reason = null;
		if ( $unavailable ) {
			$reason = self::REASON_PLAN_UNAVAILABLE;
		} elseif ( count( $plans ) > 1 ) {
			$reason = self::REASON_DIVERGENT_PLANS;
		} elseif ( [] !== $plans && $has_plain ) {
			$reason = self::REASON_MIXED_CART;
		}

		return [
			'plan'   => null === $reason && [] !== $plans ? reset( $plans ) : null,
			'reason' => $reason,
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
