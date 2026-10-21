<?php
/**
 * Settlement - records the outcome of Lite renewal orders on their cycles and contracts.
 *
 * Moves the cycle of a Lite renewal order by compare-and-set under the contract lock when the
 * order's status changes: paid bills it, failed or dead (cancelled, trashed or deleted unpaid)
 * fails it and puts the contract on the system hold, on-hold awaits the charge. A settlement
 * refused by another lock is retried (`woocommerce_subscriptions_lite_settle_order`).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\ScheduledActions;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal order settlement.
 */
final class Settlement {

	/**
	 * Operation label of a settlement's own contract lock.
	 */
	public const OPERATION = 'settlement';

	/**
	 * Failure reason recorded on a cycle whose renewal order failed.
	 */
	public const REASON_PAYMENT_FAILED = 'payment-failed';

	/**
	 * Failure reason recorded on an unpaid cycle whose renewal order was cancelled or trashed.
	 */
	public const REASON_ORDER_CANCELLED = 'order-cancelled';

	/**
	 * Order meta on a dead renewal order that Renew now replaced: the cycle it billed.
	 */
	public const REPLACED_CYCLE_META = '_wcsl_replaced_cycle_id';

	/**
	 * Order meta on a dead renewal order that Renew now replaced: the contract of that cycle.
	 */
	public const REPLACED_CONTRACT_META = '_wcsl_replaced_contract_id';

	/**
	 * Order meta set once a paid order that bills no cycle was flagged for a refund.
	 */
	private const REFUND_FLAGGED_META = '_wcsl_refund_flagged';

	/**
	 * Cycles read per page when looking up the cycle a replaced order billed.
	 */
	private const CYCLE_PAGE_SIZE = 50;

	/**
	 * Cycle statuses a paid order settles from.
	 */
	private const PAYABLE_CYCLE_STATUSES = [ CycleStatus::PENDING, CycleStatus::PROCESSING, CycleStatus::FAILED ];

	/**
	 * Cycle statuses a failed order or an asynchronous charge moves on from.
	 */
	private const OPEN_CYCLE_STATUSES = [ CycleStatus::PENDING, CycleStatus::PROCESSING ];

	/**
	 * Bind the order status listeners.
	 */
	public static function register(): void {
		$instance = new self();
		// Every status change: settle() reads the outcome off the order (`is_paid()` decides
		// paid, so paid statuses registered after load are covered too). A completed payment
		// always changes the status, so it needs no listener of its own.
		add_action( 'woocommerce_order_status_changed', [ $instance, 'handle_order' ] );
		add_action( 'woocommerce_trash_order', [ $instance, 'handle_order' ] );
		// Removal before it happens, while the order can still be read: permanent deletion,
		// and trash or deletion through the posts screens of the legacy order storage.
		add_action( 'woocommerce_before_delete_order', [ $instance, 'handle_removed_order' ] );
		add_action( 'wp_trash_post', [ $instance, 'handle_removed_post' ] );
		add_action( 'before_delete_post', [ $instance, 'handle_removed_post' ] );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', [ $instance, 'statuses_for_customer_cancel' ], 10, 2 );
		// Retries a settlement refused by another operation's lock, and settles a charge cut short.
		add_action( 'woocommerce_subscriptions_lite_settle_order', [ $instance, 'retry' ], 10, 2 );
	}

	/**
	 * Action Scheduler entry point for a refused settlement: settle the order when it still
	 * exists, else fail the cycle still linked to its id as dead (it was deleted unpaid). An
	 * order that was paid and then deleted fails nothing: a warning is logged. A retry refused
	 * again schedules the next one; the lock it waits for expires on its own.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $paid     Whether the order was paid when the retry was scheduled.
	 */
	public function retry( $order_id, $paid = false ): void {
		if ( ! is_numeric( $order_id ) ) {
			return;
		}

		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->settle_or_schedule_retry( $order );
			return;
		}

		if ( true === $paid ) {
			wc_get_logger()->warning(
				sprintf( 'Settlement: renewal order %d was paid and then deleted before its settlement; its renewal is left as it is. Check the payment and the subscription.', $order_id ),
				[
					'source'   => Package::EXTENSION_SLUG,
					'order_id' => $order_id,
				]
			);
			return;
		}

		$this->settle_removed( $order_id );
	}

	/**
	 * Schedule a retry of the order's settlement, once: an action already pending for it covers
	 * this conflict too.
	 *
	 * @param int      $order_id Order id.
	 * @param WP_Error $conflict The lock conflict.
	 * @param bool     $paid     Whether the order is paid.
	 */
	private function schedule_retry( int $order_id, WP_Error $conflict, bool $paid ): void {
		$data = (array) $conflict->get_error_data();
		wc_get_logger()->info(
			sprintf( 'Settlement: renewal order %d waits for the %s operation on contract %d; its settlement is retried.', $order_id, (string) ( $data['operation'] ?? '' ), (int) ( $data['contract_id'] ?? 0 ) ),
			[
				'source'      => Package::EXTENSION_SLUG,
				'contract_id' => $data['contract_id'] ?? null,
				'order_id'    => $order_id,
			]
		);

		ScheduledActions::schedule_once(
			'woocommerce_subscriptions_lite_settle_order',
			[
				'order_id' => $order_id,
				'paid'     => $paid,
			],
			ScheduledActions::LOCK_RETRY_DELAY_SECONDS
		);
	}

	/**
	 * Schedule the settlement of a renewal order about to be charged, for after the charging
	 * run's lock expires: it records a charge whose request died before settling (also on a
	 * held contract with no due moment), and writes nothing on a settled order.
	 *
	 * @param int $order_id The renewal order about to be charged.
	 */
	public static function schedule_charge_settlement( int $order_id ): void {
		ScheduledActions::schedule_once(
			'woocommerce_subscriptions_lite_settle_order',
			[
				'order_id' => $order_id,
				'paid'     => false,
			],
			ContractLock::TTL_SECONDS + ScheduledActions::LOCK_RETRY_DELAY_SECONDS
		);
	}

	/**
	 * Log a settlement failure under the Lite source.
	 *
	 * @param string $message  Message.
	 * @param int    $order_id Order id.
	 */
	private function log_failure( string $message, int $order_id ): void {
		wc_get_logger()->error(
			$message,
			[
				'source'   => Package::EXTENSION_SLUG,
				'order_id' => $order_id,
			]
		);
	}

	/**
	 * Customers cannot cancel a Lite renewal order from My Account: a dead renewal order holds
	 * the subscription until the merchant recovers it, while the order can still be paid.
	 *
	 * @param mixed $statuses Order statuses in which the customer may cancel the order.
	 * @param mixed $order    The order.
	 * @return mixed
	 */
	public function statuses_for_customer_cancel( $statuses, $order = null ) {
		return $order instanceof WC_Order && RenewalOrderBuilder::CREATED_VIA === $order->get_created_via() ? [] : $statuses;
	}

	/**
	 * Hook entry point for an order post about to be trashed or deleted (legacy order storage).
	 *
	 * @param mixed $post_id Post id.
	 */
	public function handle_removed_post( $post_id ): void {
		if ( is_numeric( $post_id ) && 'shop_order' === get_post_type( (int) $post_id ) ) {
			$this->handle_removed_order( $post_id );
		}
	}

	/**
	 * Hook entry point for a renewal order about to be trashed or deleted: an unpaid one dies
	 * like a cancelled one. A lock conflict schedules a retry (which finds the order trashed,
	 * or gone); any other failure is logged, not rethrown.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function handle_removed_order( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof WC_Order || RenewalOrderBuilder::CREATED_VIA !== $order->get_created_via() || $order->is_paid() ) {
			return;
		}

		$this->settle_removed( $order->get_id() );
	}

	/**
	 * Fail the open Lite cycle linked to an unpaid order that is removed or gone, under its
	 * contract's lock. A lock conflict schedules a retry; any other failure is logged, not
	 * rethrown.
	 *
	 * @param int $order_id Order id.
	 */
	private function settle_removed( int $order_id ): void {
		$conflict = null;
		try {
			$contract_id = self::find_lite_contract_id( $order_id );
			if ( null === $contract_id ) {
				return;
			}

			$lock     = HeldLocks::get( $contract_id );
			$acquired = null === $lock;
			if ( $acquired ) {
				$lock = HeldLocks::acquire( $contract_id, self::OPERATION );
			}

			if ( null === $lock ) {
				$conflict = HeldLocks::get_conflict( $contract_id );
			} else {
				try {
					$contract = Contracts::get( $contract_id );
					$cycle    = self::find_order_cycle( $order_id, $contract_id );
					if ( null !== $contract && null !== $cycle ) {
						$this->settle_dead( $contract, $cycle, $lock );
					}
				} finally {
					if ( $acquired ) {
						HeldLocks::release( $lock );
					}
				}
			}
		} catch ( ContractLockedException $e ) {
			$conflict = HeldLocks::get_refused_conflict( $e );
		} catch ( Throwable $e ) {
			$this->log_failure( sprintf( 'Settlement: settling removed renewal order %d failed: %s', $order_id, $e->getMessage() ), $order_id );
			return;
		}

		if ( null !== $conflict ) {
			$this->schedule_retry( $order_id, $conflict, false );
		}
	}

	/**
	 * Hook entry point: settle the order with this id. Another operation holding the
	 * contract's lock schedules a retry; any other failure is logged, not rethrown, so the
	 * order status change that triggered it still completes; a due renewal that finds the
	 * cycle settled re-applies the contract write ({@see RenewalProcessor}).
	 *
	 * @param mixed $order_id Order id.
	 */
	public function handle_order( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( $order instanceof WC_Order ) {
			$this->settle_or_schedule_retry( $order );
		}
	}

	/**
	 * Settle the order; a lock conflict schedules a retry, any other failure is logged.
	 *
	 * @param WC_Order $order The order.
	 */
	private function settle_or_schedule_retry( WC_Order $order ): void {
		try {
			$conflict = $this->settle( $order );
		} catch ( ContractLockedException $e ) {
			$conflict = HeldLocks::get_refused_conflict( $e );
		} catch ( Throwable $e ) {
			$this->log_failure( sprintf( 'Settlement: settling renewal order %d failed: %s', $order->get_id(), $e->getMessage() ), $order->get_id() );
			return;
		}

		if ( null !== $conflict ) {
			$this->schedule_retry( $order->get_id(), $conflict, $order->is_paid() );
		}
	}

	/**
	 * Settle a Lite renewal order from its status; any other order is ignored. The linked cycle
	 * only locates the contract: the decision reads the contract and the cycle under its lock,
	 * and the order as loaded before it (another request's change may not show in that copy).
	 *
	 * @param WC_Order               $order The order, loaded again by id.
	 * @param DateTimeImmutable|null $now   The current moment; the UTC wall clock when omitted.
	 * @return WP_Error|null A lock conflict when another operation holds the lock, else null.
	 */
	public function settle( WC_Order $order, ?DateTimeImmutable $now = null ): ?WP_Error {
		$renewal_order = wc_get_order( $order->get_id() );
		if ( ! $renewal_order instanceof WC_Order || RenewalOrderBuilder::CREATED_VIA !== $renewal_order->get_created_via() ) {
			return null;
		}

		$order_id    = $renewal_order->get_id();
		$contract_id = self::find_lite_contract_id( $order_id );
		if ( null === $contract_id ) {
			if ( ! $renewal_order->is_paid() ) {
				return null;
			}

			return $this->settle_replaced_paid( $renewal_order, $now );
		}

		$utc_now  = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$lock     = HeldLocks::get( $contract_id );
		$acquired = null === $lock;
		if ( $acquired ) {
			$lock = HeldLocks::acquire( $contract_id, self::OPERATION );
			if ( null === $lock ) {
				return HeldLocks::get_conflict( $contract_id );
			}
		}

		try {
			$contract = Contracts::get( $contract_id );
			if ( null === $contract ) {
				return null;
			}

			$cycle = self::find_order_cycle( $order_id, $contract_id );
			if ( null === $cycle ) {
				// The order was relinked away before the lock: settle it as a replaced order.
				if ( ! $renewal_order->is_paid() ) {
					return null;
				}

				return $this->settle_replaced_paid( $renewal_order, $now );
			}

			if ( $renewal_order->is_paid() ) {
				$this->settle_paid( $renewal_order, $contract, $cycle, $utc_now, $lock );
			} elseif ( $renewal_order->has_status( OrderStatus::FAILED ) ) {
				$this->settle_failed( $renewal_order, $contract, $cycle, $utc_now, $lock );
			} elseif ( $renewal_order->has_status( OrderStatus::ON_HOLD ) ) {
				$this->settle_in_progress( $contract, $cycle, $lock );
			} elseif ( self::is_dead( $renewal_order ) ) {
				$this->settle_dead( $contract, $cycle, $lock );
			}

			return null;
		} finally {
			if ( $acquired ) {
				HeldLocks::release( $lock );
			}
		}
	}

	/**
	 * The paid order bills its cycle; the contract resumes or records the payment. A repeat
	 * signal for a newest cycle this order already billed completes a contract write cut short
	 * (the due scan never would: no due moment) and fires the paid action, so that action is
	 * at-least-once. A paid order whose cycle can no longer be billed is flagged for a refund.
	 *
	 * @param WC_Order          $order    The paid order.
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle the order bills.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 * @param ContractLock      $lock     The held lock.
	 */
	private function settle_paid( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now, ContractLock $lock ): void {
		if ( CycleStatus::BILLED === $cycle->get_status() ) {
			if ( $order->get_id() !== $cycle->get_order_id() || ! self::is_head( $cycle ) || self::contract_records_payment( $contract, $cycle ) ) {
				return;
			}
		} elseif ( ! in_array( $cycle->get_status(), self::PAYABLE_CYCLE_STATUSES, true ) ) {
			self::flag_for_refund(
				$order,
				/* translators: %s: renewal (cycle) status. */
				sprintf( __( 'This renewal order was paid, but its renewal can no longer be billed (status: %s). Refund this payment.', 'woocommerce-subscriptions-lite' ), $cycle->get_status() ),
				sprintf( 'Settlement: renewal order %d was paid for cycle %d, which is %s; a refund is needed.', $order->get_id(), $cycle->get_id(), $cycle->get_status() ),
				$contract->get_id()
			);
			return;
		} else {
			$billed = Contracts::transition_cycle(
				$cycle->get_id(),
				[
					'from_status' => $cycle->get_status(),
					'to_status'   => CycleStatus::BILLED,
					'order_id'    => $order->get_id(),
					'reason'      => null,
				],
				$lock
			);
			if ( ! $billed ) {
				return;
			}
		}

		$this->record_payment( $order, $contract, $cycle, $now, $lock );

		$settled = $this->read_settled( $contract->get_id(), $cycle->get_id(), $order->get_id() );
		if ( null === $settled ) {
			return;
		}

		/**
		 * Fires after a Lite renewal order was paid and its cycle billed. Delivered at least
		 * once: completing a cut-short settlement fires it again. Runs under the contract lock:
		 * a callback writes the contract with `HeldLocks::get( $id )`, since an unlocked write
		 * is refused.
		 *
		 * @param ContractView $contract The contract after the payment was recorded.
		 * @param CycleView    $cycle    The billed cycle.
		 * @param WC_Order     $order    The paid renewal order.
		 */
		do_action( 'woocommerce_subscriptions_lite_renewal_paid', $settled['contract'], $settled['cycle'], $order );
	}

	/**
	 * The contract half of a paid settlement: an active contract, or one on Lite's own hold,
	 * becomes active with the next due moment after the paid cycle; a customer hold moves its
	 * anchor there instead; a winding-down or cancelled contract only records the payment.
	 *
	 * @param WC_Order          $order    The paid order.
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The billed cycle.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 * @param ContractLock      $lock     The held lock.
	 */
	private function record_payment( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now, ContractLock $lock ): void {
		$date_paid    = $order->get_date_paid();
		$last_payment = null === $date_paid ? $now : new DateTimeImmutable( '@' . $date_paid->getTimestamp() );
		$contract_id  = $contract->get_id();
		$status       = $contract->get_status();

		if ( ! in_array( $status, [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			Contracts::update( $contract_id, [ 'last_payment_gmt' => $last_payment ], $lock );
			return;
		}

		$policy = ContractPlans::get_billing_policy_for( $contract );
		$next   = null === $policy
			? null
			: BillingTerms::get_next_due_after( new DateTimeImmutable( $cycle->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ), $now, $policy );

		if ( ContractStatus::ON_HOLD === $status && null !== HoldAnchor::read( $contract_id ) ) {
			// A customer hold absorbs a late payment: it resumes from the period after this one.
			if ( null !== $next ) {
				HoldAnchor::store( $lock, $next->format( 'Y-m-d H:i:s' ) );
			}
			Contracts::update( $contract_id, [ 'last_payment_gmt' => $last_payment ], $lock );
			return;
		}

		Contracts::update(
			$contract_id,
			[
				'status'           => ContractStatus::ACTIVE,
				'next_payment_gmt' => $next,
				'last_payment_gmt' => $last_payment,
			],
			$lock
		);
		HoldAnchor::clear( $lock );
	}

	/**
	 * Whether the contract already shows the payment of its billed newest cycle: active (or
	 * held by the customer) with the next due moment (or anchor) at or after the cycle end.
	 * A winding-down or cancelled contract has nothing to resume, so it counts as recorded.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The billed cycle.
	 */
	private static function contract_records_payment( ContractView $contract, CycleView $cycle ): bool {
		$status = $contract->get_status();
		if ( ContractStatus::ACTIVE === $status ) {
			$resumes_at = $contract->get_next_payment_gmt();
		} elseif ( ContractStatus::ON_HOLD === $status ) {
			$resumes_at = HoldAnchor::read( $contract->get_id() );
			if ( null === $resumes_at ) {
				return false; // Lite's own hold over a renewal that is now paid.
			}
		} else {
			return true;
		}

		// An active contract with no due moment and no billing policy stays parked.
		return null === $resumes_at
			? null === ContractPlans::get_billing_policy_for( $contract )
			: $resumes_at >= (string) $cycle->get_ends_at_gmt();
	}

	/**
	 * Whether the cycle is its contract's newest.
	 *
	 * @param CycleView $cycle The cycle.
	 */
	private static function is_head( CycleView $cycle ): bool {
		$head = Contracts::get_cycles( $cycle->get_contract_id(), 1 )[0] ?? null;

		return null !== $head && $head->get_id() === $cycle->get_id();
	}

	/**
	 * The failed order fails its cycle; an active or held contract (a customer hold included)
	 * goes on the system hold until the customer pays.
	 *
	 * @param WC_Order          $order    The failed order.
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle the order bills.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 * @param ContractLock      $lock     The held lock.
	 */
	private function settle_failed( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now, ContractLock $lock ): void {
		if ( ! in_array( $cycle->get_status(), self::OPEN_CYCLE_STATUSES, true ) ) {
			return;
		}
		$failed = self::fail_cycle( $cycle, self::REASON_PAYMENT_FAILED, $lock );
		if ( ! $failed ) {
			return;
		}

		$contract_id = $contract->get_id();
		if ( in_array( $contract->get_status(), [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			// Also over a customer hold: its anchor would sit on the end of an unpaid period.
			Hold::system_hold( $lock, [ 'last_attempt_gmt' => $now ] );
		}

		$settled = $this->read_settled( $contract_id, $cycle->get_id(), $order->get_id() );
		if ( null === $settled ) {
			return;
		}

		/**
		 * Fires after a Lite renewal order failed and its cycle was marked failed. Runs under
		 * the contract lock: a callback writes the contract with `HeldLocks::get( $id )`, since
		 * an unlocked write is refused.
		 *
		 * @param ContractView $contract The contract after the failure was recorded.
		 * @param CycleView    $cycle    The failed cycle.
		 * @param WC_Order     $order    The failed renewal order.
		 */
		do_action( 'woocommerce_subscriptions_lite_renewal_failed', $settled['contract'], $settled['cycle'], $order );
	}

	/**
	 * An order awaiting an asynchronous charge moves its cycle to processing and
	 * clears the contract's due moment, so the due scan leaves it alone until the charge
	 * reports: paid re-arms the next period, failed holds the contract.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The cycle the order bills.
	 * @param ContractLock $lock     The held lock.
	 */
	private function settle_in_progress( ContractView $contract, CycleView $cycle, ContractLock $lock ): void {
		if ( CycleStatus::PENDING !== $cycle->get_status() ) {
			return;
		}

		$processing = Contracts::transition_cycle(
			$cycle->get_id(),
			[
				'from_status' => CycleStatus::PENDING,
				'to_status'   => CycleStatus::PROCESSING,
			],
			$lock
		);
		if ( $processing ) {
			Contracts::update( $contract->get_id(), [ 'next_payment_gmt' => null ], $lock );
		}
	}

	/**
	 * An unpaid cycle whose order was cancelled, trashed or deleted fails, and an active or held
	 * contract goes on the system hold: the renewal is still owed, and only a payment resumes
	 * the contract.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The cycle the order bills.
	 * @param ContractLock $lock     The held lock.
	 */
	private function settle_dead( ContractView $contract, CycleView $cycle, ContractLock $lock ): void {
		if ( ! in_array( $cycle->get_status(), self::OPEN_CYCLE_STATUSES, true ) ) {
			return;
		}
		$failed = self::fail_cycle( $cycle, self::REASON_ORDER_CANCELLED, $lock );
		if ( ! $failed ) {
			return;
		}

		if ( in_array( $contract->get_status(), [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			Hold::system_hold( $lock );
		}
	}

	/**
	 * Move an open cycle to failed with `$reason`.
	 *
	 * @param CycleView    $cycle  The open cycle.
	 * @param string       $reason Failure reason.
	 * @param ContractLock $lock   The held lock.
	 */
	private static function fail_cycle( CycleView $cycle, string $reason, ContractLock $lock ): bool {
		return Contracts::transition_cycle(
			$cycle->get_id(),
			[
				'from_status' => $cycle->get_status(),
				'to_status'   => CycleStatus::FAILED,
				'reason'      => $reason,
			],
			$lock
		);
	}

	/**
	 * Record on a dead renewal order the cycle a replacement order now bills.
	 *
	 * @param WC_Order $order       The replaced order.
	 * @param int      $contract_id Contract id.
	 * @param int      $cycle_id    The cycle it billed.
	 */
	public static function mark_replaced( WC_Order $order, int $contract_id, int $cycle_id ): void {
		$order->update_meta_data( self::REPLACED_CONTRACT_META, (string) $contract_id );
		$order->update_meta_data( self::REPLACED_CYCLE_META, (string) $cycle_id );
		$order->save();
	}

	/**
	 * A replaced renewal order was paid after all. While its renewal is unpaid and not in
	 * flight, it takes the renewal back (relink, cancel the unpaid replacement, settle);
	 * otherwise it is flagged once for a refund. Runs under the lock of the contract named on
	 * the order.
	 *
	 * @param WC_Order               $order The paid replaced order.
	 * @param DateTimeImmutable|null $now   The current moment.
	 * @return WP_Error|null A lock conflict when another operation holds the lock, else null.
	 */
	private function settle_replaced_paid( WC_Order $order, ?DateTimeImmutable $now ): ?WP_Error {
		$contract_id = (int) $order->get_meta( self::REPLACED_CONTRACT_META );
		if ( $contract_id <= 0 ) {
			return null;
		}

		$lock     = HeldLocks::get( $contract_id );
		$acquired = null === $lock;
		if ( $acquired ) {
			$lock = HeldLocks::acquire( $contract_id, self::OPERATION );
			if ( null === $lock ) {
				return HeldLocks::get_conflict( $contract_id );
			}
		}

		try {
			$contract = Contracts::get( $contract_id );
			if ( null === $contract || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
				return null;
			}

			$cycle_id = (int) $order->get_meta( self::REPLACED_CYCLE_META );
			$cycle    = $cycle_id <= 0 ? null : self::find_cycle( $contract_id, $cycle_id );
			if ( null === $cycle ) {
				self::flag_for_refund(
					$order,
					__( 'This renewal order was paid after another order replaced it, and its renewal no longer exists. Refund this payment.', 'woocommerce-subscriptions-lite' ),
					sprintf( 'Settlement: renewal order %d was paid after it was replaced, and its cycle %d no longer exists; a refund is needed.', $order->get_id(), $cycle_id ),
					$contract_id
				);
				return null;
			}

			$replacement_id = $cycle->get_order_id();
			$replacement    = null === $replacement_id || $replacement_id === $order->get_id() ? false : wc_get_order( $replacement_id );
			$in_flight      = CycleStatus::PROCESSING === $cycle->get_status()
				|| ( $replacement instanceof WC_Order && $replacement->has_status( OrderStatus::ON_HOLD ) );

			if ( in_array( $cycle->get_status(), self::PAYABLE_CYCLE_STATUSES, true ) && ! $in_flight ) {
				$cancel_replacement = $replacement instanceof WC_Order && ! $replacement->is_paid() && ! self::is_dead( $replacement );
				if ( $cancel_replacement ) {
					// Stamped before the relink, so should the replacement be paid after the relink
					// (before it is cancelled, or after all), that payment is flagged for a refund.
					self::mark_replaced( $replacement, $contract_id, $cycle_id );
				}

				$relinked = Contracts::transition_cycle(
					$cycle_id,
					[
						'from_status' => $cycle->get_status(),
						'to_status'   => $cycle->get_status(),
						'order_id'    => $order->get_id(),
					],
					$lock
				);
				if ( $relinked ) {
					// Settling again needs the cycle to carry this order, or it would land back here.
					$relinked_cycle = self::find_cycle( $contract_id, $cycle_id );
					$relinked       = null !== $relinked_cycle && $relinked_cycle->get_order_id() === $order->get_id();
				}
				if ( ! $relinked ) {
					self::flag_for_refund(
						$order,
						__( 'This renewal order was paid after another order replaced it, and the renewal could not be moved back to it. Refund this payment.', 'woocommerce-subscriptions-lite' ),
						sprintf( 'Settlement: renewal order %d was paid after it was replaced and could not be relinked to cycle %d; a refund is needed.', $order->get_id(), $cycle_id ),
						$contract_id
					);
					return null;
				}

				if ( $cancel_replacement ) {
					/* translators: %d: order number. */
					$replacement->update_status( OrderStatus::CANCELLED, sprintf( __( 'Order #%d paid this renewal after this order replaced it.', 'woocommerce-subscriptions-lite' ), $order->get_id() ) );
				}

				return $this->settle( $order, $now );
			}

			if ( $replacement_id === $order->get_id() ) {
				return null;
			}

			if ( null !== $replacement_id && CycleStatus::BILLED === $cycle->get_status() ) {
				/* translators: %d: order number. */
				$note    = sprintf( __( 'This renewal order was paid after order #%d replaced it and paid the same renewal. Refund one of the two payments.', 'woocommerce-subscriptions-lite' ), $replacement_id );
				$message = sprintf( 'Settlement: renewal order %d was paid after order %d replaced it and paid the same renewal; a refund is needed.', $order->get_id(), $replacement_id );
			} elseif ( null !== $replacement_id && $in_flight ) {
				// The replacement's charge may still complete: it keeps the renewal, so at most one
				// payment settles it and the other is flagged here rather than cancelled unseen.
				/* translators: %d: order number. */
				$note    = sprintf( __( 'This renewal order was paid after order #%d replaced it, while that order\'s payment was still awaiting confirmation. If that payment completes, refund one of the two payments.', 'woocommerce-subscriptions-lite' ), $replacement_id );
				$message = sprintf( 'Settlement: renewal order %d was paid after order %d replaced it while that order\'s payment was in flight; a refund is likely needed.', $order->get_id(), $replacement_id );
			} else {
				/* translators: %s: renewal (cycle) status. */
				$note    = sprintf( __( 'This renewal order was paid, but its renewal can no longer be billed (status: %s). Refund this payment.', 'woocommerce-subscriptions-lite' ), $cycle->get_status() );
				$message = sprintf( 'Settlement: renewal order %d was paid after it was replaced, for cycle %d, which is %s; a refund is needed.', $order->get_id(), $cycle_id, $cycle->get_status() );
			}

			self::flag_for_refund( $order, $note, $message, $contract_id );

			return null;
		} finally {
			if ( $acquired ) {
				HeldLocks::release( $lock );
			}
		}
	}

	/**
	 * Flag a paid order that bills no cycle for a refund: one order note, the flag meta and a
	 * warning. Idempotent on the flag.
	 *
	 * @param WC_Order $order       The paid order.
	 * @param string   $note        Order note for the merchant.
	 * @param string   $log_message Warning logged.
	 * @param int      $contract_id Contract id, for the log.
	 */
	private static function flag_for_refund( WC_Order $order, string $note, string $log_message, int $contract_id ): void {
		if ( '' !== (string) $order->get_meta( self::REFUND_FLAGGED_META ) ) {
			return;
		}

		$order->add_order_note( $note );
		$order->update_meta_data( self::REFUND_FLAGGED_META, '1' );
		$order->save();
		wc_get_logger()->warning(
			$log_message,
			[
				'source'      => Package::EXTENSION_SLUG,
				'contract_id' => $contract_id,
				'order_id'    => $order->get_id(),
			]
		);
	}

	/**
	 * A contract's cycle by id, read off its chain page by page.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $cycle_id    Cycle id.
	 */
	private static function find_cycle( int $contract_id, int $cycle_id ): ?CycleView {
		$offset = 0;
		do {
			$page       = Contracts::get_cycles( $contract_id, self::CYCLE_PAGE_SIZE, $offset );
			$page_count = count( $page );
			foreach ( $page as $cycle ) {
				if ( $cycle->get_id() === $cycle_id ) {
					return $cycle;
				}
			}
			$offset += self::CYCLE_PAGE_SIZE;
		} while ( self::CYCLE_PAGE_SIZE === $page_count );

		return null;
	}

	/**
	 * Whether a renewal order can no longer be paid as it is: cancelled or trashed.
	 *
	 * @param WC_Order $order The order.
	 */
	public static function is_dead( WC_Order $order ): bool {
		return $order->has_status( [ OrderStatus::CANCELLED, OrderStatus::TRASH ] );
	}

	/**
	 * The contract of the newest cycle linked to the order that is on a Lite contract, or null.
	 * It only locates the contract: callers re-read it under its lock.
	 *
	 * @param int $order_id Order id.
	 */
	private static function find_lite_contract_id( int $order_id ): ?int {
		foreach ( Contracts::find_cycles_by_order( $order_id ) as $cycle ) {
			$contract = Contracts::get( $cycle->get_contract_id() );
			if ( null !== $contract && Package::EXTENSION_SLUG === $contract->get_extension_slug() ) {
				return $contract->get_id();
			}
		}

		return null;
	}

	/**
	 * The newest cycle of the contract linked to the order, or null.
	 *
	 * @param int $order_id    Order id.
	 * @param int $contract_id Contract id.
	 */
	private static function find_order_cycle( int $order_id, int $contract_id ): ?CycleView {
		foreach ( Contracts::find_cycles_by_order( $order_id ) as $cycle ) {
			if ( $cycle->get_contract_id() === $contract_id ) {
				return $cycle;
			}
		}

		return null;
	}

	/**
	 * Re-read the contract and the cycle after a settlement write, for the action payload.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $cycle_id    Cycle id.
	 * @param int $order_id    The order the cycle is linked to.
	 * @return array{contract: ContractView, cycle: CycleView}|null
	 */
	private function read_settled( int $contract_id, int $cycle_id, int $order_id ): ?array {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract ) {
			return null;
		}

		foreach ( Contracts::find_cycles_by_order( $order_id ) as $cycle ) {
			if ( $cycle->get_id() === $cycle_id ) {
				return [
					'contract' => $contract,
					'cycle'    => $cycle,
				];
			}
		}

		return null;
	}
}
