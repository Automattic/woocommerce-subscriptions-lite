<?php
/**
 * ContractCreationHandler - turns a paid checkout order into a subscription contract.
 *
 * On a paid status change (`is_paid()`, so offline payments count too), one clean plan group
 * becomes one active contract written through the engine facade; divergent plans or a mixed
 * cart are left uncreated with a note and a flag, and checkout is never blocked. A creation cut
 * short leaves a draft that the next paid change or the resume action finishes.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use LogicException;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\AddressFields;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\ScheduledActions;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Create one contract per paid subscription order.
 *
 * Collaborators (the engine facades and the plan resolver) are called directly; the grouping, mapping, idempotency, and error paths are
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
	 * Deferral reason: the order mixes a plan line with a one-time line; mixed
	 * carts are not supported yet.
	 */
	public const REASON_MIXED_CART = 'mixed_cart';

	/**
	 * Deferral reason: a line carries a plan that no longer resolves as a billable
	 * Lite plan (deleted or not billable).
	 */
	public const REASON_PLAN_UNAVAILABLE = 'plan_unavailable';

	/**
	 * Deferral reason: contract creation was refused before any contract was written,
	 * for a reason that would repeat on every retry (an invalid field or billing).
	 */
	public const REASON_CREATION_FAILED = 'creation_failed';

	/**
	 * Operation label of the new contract's lock while checkout builds it.
	 */
	public const OPERATION = 'checkout';

	/**
	 * Order meta counting failed runs of the resume action; cleared on activation.
	 */
	private const RESUME_ATTEMPTS_META = '_wcsl_resume_attempts';

	/**
	 * Failed resume runs after which the resume action is no longer rescheduled.
	 */
	private const MAX_RESUME_ATTEMPTS = 3;

	/**
	 * Logger source tag.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * Create the contract on every order status change (`is_paid()` filters it down to paid
	 * ones; `woocommerce_payment_complete` never fires for offline gateways), and resume a
	 * creation cut short on the resume action, whose argument is the order id.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'woocommerce_order_status_changed', [ $instance, 'create_contracts_for_order' ], 10, 1 );
		add_action( 'woocommerce_subscriptions_lite_resume_checkout', [ $instance, 'resume_checkout' ] );
	}

	/**
	 * Action Scheduler entry point: run the order's creation again, which resumes a Lite draft
	 * left by a creation cut short, creates a missing contract for a paid order, and does
	 * nothing for an active contract or an unpaid order. A failed run counts against
	 * {@see self::MAX_RESUME_ATTEMPTS}; at the cap the resume is dropped and the order noted.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function resume_checkout( $order_id ): void {
		if ( ! is_numeric( $order_id ) ) {
			return;
		}
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$succeeded = $this->create_for_order( $order );
		if ( ! $succeeded ) {
			$this->record_failed_resume( $order );
		}
	}

	/**
	 * Count a failed resume run on the order; at {@see self::MAX_RESUME_ATTEMPTS} drop the
	 * scheduled resume and note the order once. A failure to record it is logged.
	 *
	 * @param WC_Order $order The order.
	 */
	private function record_failed_resume( WC_Order $order ): void {
		$order_id = $order->get_id();
		try {
			// The count is read from the database: a parallel resume run may have counted since.
			$order->read_meta_data( true );
			$attempts = (int) $order->get_meta( self::RESUME_ATTEMPTS_META ) + 1;
			$order->update_meta_data( self::RESUME_ATTEMPTS_META, (string) $attempts );
			$order->save();
			if ( $attempts < self::MAX_RESUME_ATTEMPTS ) {
				return;
			}

			self::unschedule_resume( $order_id );
			if ( self::MAX_RESUME_ATTEMPTS === $attempts ) {
				$this->log_error( sprintf( 'gave up resuming the subscription of order %d after %d failed attempts.', $order_id, $attempts ) );
				$order->add_order_note( __( 'The subscription for this order still could not be created after several automatic attempts, so they have stopped. Please check this order.', 'woocommerce-subscriptions-lite' ) );
			}
		} catch ( Throwable $e ) {
			$this->log_error( sprintf( 'failed to record a failed resume on order %d: %s', $order_id, $e->getMessage() ) );
		}
	}

	/**
	 * Hook entry point: create the contract for a paid order, or record a deferral when the
	 * order cannot be represented as a single contract. Never throws out of the hook.
	 *
	 * @param int $order_id The id of the order whose status changed.
	 */
	public function create_contracts_for_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->create_for_order( $order );
		}
	}

	/**
	 * Create the order's contract, or resume a Lite draft of it; a no-op unless the order is paid.
	 *
	 * @param WC_Order $order The order.
	 * @return bool False when a creation or resume failed (a lock refusal is not a failure).
	 */
	private function create_for_order( WC_Order $order ): bool {
		if ( ! $order->is_paid() || RenewalOrderBuilder::CREATED_VIA === $order->get_created_via() ) {
			return true;
		}

		$order_id = $order->get_id();

		$outcome = $this->classify_order( $order );
		if ( null === $outcome['reason'] && ! $outcome['plan'] instanceof PlanView ) {
			return true; // No subscription line on the order.
		}

		// Idempotency fast path: a repeat paid-status transition (e.g. processing -> completed)
		// must neither double-create a contract nor duplicate a deferral note; it resumes a draft
		// left by a creation cut short. Two signals racing past it are told apart by the order's
		// creation key.
		if ( '' !== (string) $order->get_meta( self::CREATION_DEFERRED_META ) ) {
			return true;
		}
		$existing = Contracts::find_by_origin_order( $order_id );
		if ( [] !== $existing ) {
			return $this->resume_drafts( $order, $existing );
		}

		if ( null !== $outcome['reason'] ) {
			$this->record_deferral( $order, $outcome['reason'] );
			return true;
		}

		try {
			$contract = $this->create_contract( $order, $outcome['plan'] );
		} catch ( Throwable $e ) {
			$this->log_error( sprintf( 'failed to create a contract for order %d: %s', $order_id, $e->getMessage() ) );
			try {
				// A draft blocks retries through the idempotency check. Without one, a refused
				// input (a LogicException: the engine rejected a field, or the billing does not
				// parse) fails the same way every time, so the deferral flag stops the retries;
				// any other failure (a database error) may be transient: it gets a note but no
				// flag, so a later paid transition still retries.
				$has_contract = $this->note_stuck_draft( $order );
				if ( ! $has_contract ) {
					if ( $e instanceof LogicException ) {
						$this->record_deferral( $order, self::REASON_CREATION_FAILED );
					} else {
						$order->add_order_note( __( 'The subscription for this order could not be created. It will be retried on the next paid status change. If it keeps failing, this order needs manual review.', 'woocommerce-subscriptions-lite' ) );
					}
				}
			} catch ( Throwable $note_error ) {
				$this->log_error( sprintf( 'failed to record the failed creation on order %d: %s', $order_id, $note_error->getMessage() ) );
			}
			return false;
		}

		if ( null === $contract ) {
			// The creation key is taken: a signal racing past the fast path created it first.
			wc_get_logger()->info(
				sprintf( 'ContractCreationHandler: the contract for order %d was already created.', $order_id ),
				[ 'source' => self::LOG_SOURCE ]
			);
			return $this->resume_drafts( $order, Contracts::find_by_origin_order( $order_id ) );
		}

		return true;
	}

	/**
	 * Resume each Lite draft of the order, with the draft's own plan: a creation cut short. A
	 * draft whose lock is live (its creation may still be running) is resumed later; a failed
	 * resume, a draft whose plan no longer resolves included, is resumed again past the lock's
	 * expiry, within the attempt cap.
	 *
	 * @param WC_Order                 $order     The paid order.
	 * @param array<int, ContractView> $contracts The order's contracts.
	 * @return bool False when a resume failed.
	 */
	private function resume_drafts( WC_Order $order, array $contracts ): bool {
		$resumed  = true;
		$resolver = new ProductPlanResolver();
		foreach ( $contracts as $contract ) {
			if ( Package::EXTENSION_SLUG !== $contract->get_extension_slug() || ContractStatus::DRAFT !== $contract->get_status() ) {
				continue;
			}
			$plan = $resolver->get_line_plan( (int) $contract->get_selling_plan_id() );
			if ( null === $plan ) {
				$resumed = false;
				$this->log_error( sprintf( 'draft contract %d of order %d cannot be resumed: its plan no longer resolves.', $contract->get_id(), $order->get_id() ) );
				self::schedule_resume( $order->get_id(), ContractLock::TTL_SECONDS + ScheduledActions::LOCK_RETRY_DELAY_SECONDS );
				continue;
			}

			try {
				$locked = ! $this->resume_draft( $order, $contract->get_id(), $plan );
			} catch ( ContractLockedException $e ) {
				// Refused mid-flow: not a failed run; it is resumed once the holder is done.
				HeldLocks::log_refused_write( $e );
				self::schedule_resume( $order->get_id(), ScheduledActions::LOCK_RETRY_DELAY_SECONDS );
				continue;
			} catch ( Throwable $e ) {
				$resumed = false;
				$this->log_error( sprintf( 'failed to resume draft contract %d of order %d: %s', $contract->get_id(), $order->get_id(), $e->getMessage() ) );
				self::schedule_resume( $order->get_id(), ContractLock::TTL_SECONDS + ScheduledActions::LOCK_RETRY_DELAY_SECONDS );
				continue;
			}

			if ( $locked ) {
				wc_get_logger()->info(
					sprintf( 'ContractCreationHandler: draft contract %d of order %d is locked by another operation; its creation is resumed later.', $contract->get_id(), $order->get_id() ),
					[ 'source' => self::LOG_SOURCE ]
				);
				self::schedule_resume( $order->get_id(), ScheduledActions::LOCK_RETRY_DELAY_SECONDS );
			}
		}

		return $resumed;
	}

	/**
	 * Resume a draft under its lock: re-read it, and when it is still a draft add cycle 1 if
	 * missing and activate it, as {@see self::create_contract()} would have. Notes the order.
	 *
	 * @param WC_Order $order       The paid order.
	 * @param int      $contract_id The draft.
	 * @param PlanView $plan        The draft's selling plan.
	 * @return bool False when its lock is not acquired (held by another operation, or the draft
	 *              was deleted): resume it later. True once the draft is resumed or is no draft.
	 */
	private function resume_draft( WC_Order $order, int $contract_id, PlanView $plan ): bool {
		[ $start, $next ] = self::get_first_period( $order, $plan );

		$lock     = HeldLocks::get( $contract_id );
		$acquired = null === $lock;
		if ( $acquired ) {
			$lock = HeldLocks::acquire( $contract_id, self::OPERATION );
			if ( null === $lock ) {
				return false;
			}
		}

		try {
			$draft = Contracts::get( $contract_id );
			if ( null === $draft || Package::EXTENSION_SLUG !== $draft->get_extension_slug() || ContractStatus::DRAFT !== $draft->get_status() ) {
				return true;
			}

			$activated = self::activate( $order, $start, $next, (string) $draft->get_billing_total(), $lock );
		} finally {
			if ( $acquired ) {
				HeldLocks::release( $lock );
			}
		}
		if ( null === $activated ) {
			return true;
		}

		self::finish_resume( $order );
		$order->add_order_note(
			sprintf(
				/* translators: %d: subscription (contract) id. */
				__( 'Subscription #%d was activated after its creation was interrupted.', 'woocommerce-subscriptions-lite' ),
				$contract_id
			)
		);

		return true;
	}

	/**
	 * Schedule the order's resume action unless one is pending to run by then.
	 *
	 * @param int $order_id      Order id.
	 * @param int $delay_seconds Seconds from now.
	 */
	private static function schedule_resume( int $order_id, int $delay_seconds ): void {
		ScheduledActions::schedule_once( 'woocommerce_subscriptions_lite_resume_checkout', [ 'order_id' => $order_id ], $delay_seconds );
	}

	/**
	 * Drop the order's pending resume actions once its contract is active.
	 *
	 * @param int $order_id Order id.
	 */
	private static function unschedule_resume( int $order_id ): void {
		ScheduledActions::unschedule( 'woocommerce_subscriptions_lite_resume_checkout', [ 'order_id' => $order_id ] );
	}

	/**
	 * Once the order's contract is active: drop its resume actions and its failed-attempt count.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function finish_resume( WC_Order $order ): void {
		self::unschedule_resume( $order->get_id() );
		if ( '' !== (string) $order->get_meta( self::RESUME_ATTEMPTS_META ) ) {
			$order->delete_meta_data( self::RESUME_ATTEMPTS_META );
			$order->save();
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
	 * Create the contract for a paid order: a draft locked from birth under the order's
	 * creation key, then cycle 1 and activation (last, so a failure leaves a draft that is never
	 * due) with that lock, released on every path. A resume action scheduled before the draft
	 * past the lock's expiry, and dropped on success, resumes a draft the request leaves behind.
	 *
	 * @param WC_Order $order The paid order.
	 * @param PlanView $plan  The order's selling plan.
	 * @return ContractView|null The active contract as written under the checkout lock (its lock
	 *                           state still shows it); null when the order's contract was already
	 *                           created (its creation key is taken) or deleted before activation.
	 * @throws LogicException When the billing payload does not parse; a failed engine write
	 *                        propagates as thrown too.
	 */
	public function create_contract( WC_Order $order, PlanView $plan ): ?ContractView {
		$plan_id    = $plan->get_id();
		$plan_lines = [];
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $plan_id === (int) $item->get_meta( self::SELLING_PLAN_META ) ) {
				$plan_lines[] = $item;
			}
		}

		[ $start, $next ] = self::get_first_period( $order, $plan );

		$totals = $this->get_recurring_totals( $order, $plan_lines );

		self::schedule_resume( $order->get_id(), ContractLock::TTL_SECONDS + ScheduledActions::LOCK_RETRY_DELAY_SECONDS );
		$created = Contracts::create(
			[
				'extension_slug'       => Package::EXTENSION_SLUG,
				'creation_key'         => self::get_creation_key( $order ),
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
			],
			[ 'operation' => self::OPERATION ]
		);

		if ( null === $created ) {
			return null;
		}

		$lock = $created['lock'];
		HeldLocks::register( $lock );
		try {
			$activated = self::activate( $order, $start, $next, $totals['billing_total'], $lock );
		} finally {
			HeldLocks::release( $lock );
		}

		if ( null !== $activated ) {
			self::finish_resume( $order );
		}

		return $activated;
	}

	/**
	 * The contract start (the order's payment, else now) and the first renewal date.
	 *
	 * @param WC_Order $order The paid order.
	 * @param PlanView $plan  The order's selling plan.
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 * @throws DomainException When the plan has no billable billing terms.
	 */
	private static function get_first_period( WC_Order $order, PlanView $plan ): array {
		$paid  = $order->get_date_paid();
		$start = null !== $paid
			? new DateTimeImmutable( '@' . $paid->getTimestamp() )
			: new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$terms = BillingTerms::from_plan( $plan );
		if ( null === $terms ) {
			throw new DomainException( sprintf( 'Selling plan %d has no billable billing terms.', (int) $plan->get_id() ) );
		}

		return [ $start, $terms->get_first_renewal_from( $start ) ];
	}

	/**
	 * The creation key of an order's contract: one contract per paid order.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function get_creation_key( WC_Order $order ): string {
		return 'order:' . $order->get_id();
	}

	/**
	 * Cycle 1 (unless the draft already has it) and activation of a draft, under its lock.
	 *
	 * @param WC_Order          $order         The paid order.
	 * @param DateTimeImmutable $start         Contract start.
	 * @param DateTimeImmutable $next          First renewal date.
	 * @param string            $billing_total Recurring total.
	 * @param ContractLock      $lock          The new contract's lock.
	 * @return ContractView|null The active contract, as written under the lock; null when it is gone.
	 */
	private static function activate( WC_Order $order, DateTimeImmutable $start, DateTimeImmutable $next, string $billing_total, ContractLock $lock ): ?ContractView {
		$contract_id = $lock->get_contract_id();
		// Null when cycle 1 exists: a resumed draft whose creation was cut short after it.
		Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::BILLED,
				'count'          => 1,
				'order_id'       => $order->get_id(),
				'starts_at_gmt'  => $start,
				'ends_at_gmt'    => $next,
				'expected_total' => $billing_total,
				'currency'       => $order->get_currency(),
			],
			$lock
		);

		return Contracts::update(
			$contract_id,
			[
				'status'           => ContractStatus::ACTIVE,
				'next_payment_gmt' => $next,
			],
			$lock
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
	 * A line's plan resolves with the rule cart pricing and the Store API use
	 * ({@see ProductPlanResolver::get_line_plan()}: any status, billable). The line's
	 * plan stamp is trusted as the plan the shopper was charged for: applicability is
	 * not re-checked, so a plan archived or detached from the product after
	 * add-to-cart, or before an offline payment is confirmed, still becomes a
	 * contract. Lite writes the stamp only at add-to-cart, from a plan that passed
	 * the applicability check, but a stamp from any writer is trusted the same way
	 * (privileged edits such as REST line meta or import tools); the plan must
	 * still be a billable Lite plan. Returns the single plan when it covers every plan line and
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
			if ( null === $plan ) {
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
