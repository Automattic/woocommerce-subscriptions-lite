<?php
/**
 * Settlement - records the outcome of Lite renewal orders on their cycles and contracts.
 *
 * Listens to the order status of Lite's own renewal orders (`created_via`
 * {@see RenewalOrderBuilder::CREATED_VIA}) and moves the billed cycle with the engine's
 * compare-and-set tool, so a repeated or concurrent signal settles once:
 * - paid: cycle billed; an active contract, or one held for a failed or manual renewal,
 *   becomes active with its next due moment armed past now; a customer hold (a hold anchor
 *   is present) stays on hold and its anchor moves to the next due moment; a winding-down
 *   or cancelled contract only records the payment;
 * - failed: cycle failed; the contract goes on the system hold ({@see SystemHold}: no due
 *   moment, no customer hold anchor), and the customer pays the failed order to resume;
 * - on-hold (an asynchronous charge awaits confirmation): cycle processing and no due
 *   moment, so it is not charged again;
 * - cancelled or trashed while unpaid: cycle failed (`order-cancelled`); an active contract
 *   goes on hold, as for a failed payment, so a dead order never renews for free. The
 *   merchant can restore the order or use Renew now, which builds a new one.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\SystemHold;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal order settlement.
 */
final class Settlement {

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
	 * Order meta set once a replaced order's late payment was flagged for a refund.
	 */
	private const REFUND_FLAGGED_META = '_wcsl_late_payment_flagged';

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
		// paid, so paid statuses registered after load are covered too).
		add_action( 'woocommerce_order_status_changed', [ $instance, 'handle_order' ] );
		add_action( 'woocommerce_payment_complete', [ $instance, 'handle_order' ] );
		add_action( 'woocommerce_trash_order', [ $instance, 'handle_order' ] );
		// Removal before it happens, while the order can still be read: permanent deletion,
		// and trash or deletion through the posts screens of the legacy order storage.
		add_action( 'woocommerce_before_delete_order', [ $instance, 'handle_removed_order' ] );
		add_action( 'wp_trash_post', [ $instance, 'handle_removed_post' ] );
		add_action( 'before_delete_post', [ $instance, 'handle_removed_post' ] );
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
	 * like a cancelled one. A failure is logged, not rethrown.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function handle_removed_order( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof WC_Order || RenewalOrderBuilder::CREATED_VIA !== $order->get_created_via() || $order->is_paid() ) {
			return;
		}

		try {
			$match = $this->find_lite_cycle( $order->get_id() );
			if ( null !== $match ) {
				$this->settle_dead( $match['contract'], $match['cycle'] );
			}
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'Settlement: settling removed renewal order %d failed: %s', $order->get_id(), $e->getMessage() ),
				[
					'source'   => Package::EXTENSION_SLUG,
					'order_id' => $order->get_id(),
				]
			);
		}
	}

	/**
	 * Hook entry point: settle the order with this id. A failure is logged, not rethrown, so
	 * the order status change that triggered it still completes; a due renewal that finds the
	 * cycle settled re-applies the contract write ({@see RenewalProcessor}).
	 *
	 * @param mixed $order_id Order id.
	 */
	public function handle_order( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		try {
			$this->settle( $order );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				sprintf( 'Settlement: settling renewal order %d failed: %s', $order->get_id(), $e->getMessage() ),
				[
					'source'   => Package::EXTENSION_SLUG,
					'order_id' => $order->get_id(),
				]
			);
		}
	}

	/**
	 * Settle a Lite renewal order from its current status; any other order is ignored.
	 *
	 * @param WC_Order               $order The order (re-read, so a stale object is fine).
	 * @param DateTimeImmutable|null $now   The current moment; the UTC wall clock when omitted.
	 */
	public function settle( WC_Order $order, ?DateTimeImmutable $now = null ): void {
		$fresh_order = wc_get_order( $order->get_id() );
		if ( ! $fresh_order instanceof WC_Order || RenewalOrderBuilder::CREATED_VIA !== $fresh_order->get_created_via() ) {
			return;
		}

		$match = $this->find_lite_cycle( $fresh_order->get_id() );
		if ( null === $match ) {
			if ( $fresh_order->is_paid() ) {
				$this->settle_replaced_paid( $fresh_order, $now );
			}
			return;
		}

		$utc_now = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );

		if ( $fresh_order->is_paid() ) {
			$this->settle_paid( $fresh_order, $match['contract'], $match['cycle'], $utc_now );
		} elseif ( $fresh_order->has_status( OrderStatus::FAILED ) ) {
			$this->settle_failed( $fresh_order, $match['contract'], $match['cycle'], $utc_now );
		} elseif ( $fresh_order->has_status( OrderStatus::ON_HOLD ) ) {
			$this->settle_in_progress( $match['contract'], $match['cycle'] );
		} elseif ( self::is_dead( $fresh_order ) ) {
			$this->settle_dead( $match['contract'], $match['cycle'] );
		}
	}

	/**
	 * The paid order bills its cycle; the contract resumes or records the payment. A repeat
	 * paid signal for the newest cycle, already billed by this order, completes a contract
	 * write that was cut short (a held, manual or async renewal has no due moment, so the due
	 * scan never would).
	 *
	 * @param WC_Order          $order    The paid order.
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle the order bills.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 */
	private function settle_paid( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now ): void {
		if ( CycleStatus::BILLED === $cycle->get_status() ) {
			if ( $order->get_id() === $cycle->get_order_id() && self::is_head( $cycle ) && ! self::contract_records_payment( $contract, $cycle ) ) {
				$this->record_payment( $order, $contract, $cycle, $now );
			}
			return;
		}

		if ( ! in_array( $cycle->get_status(), self::PAYABLE_CYCLE_STATUSES, true )
			|| ! Contracts::transition_cycle(
				$cycle->get_id(),
				$cycle->get_status(),
				CycleStatus::BILLED,
				[
					'order_id' => $order->get_id(),
					'reason'   => null,
				]
			) ) {
			return;
		}

		$this->record_payment( $order, $contract, $cycle, $now );

		$settled = $this->read_settled( $contract->get_id(), $cycle->get_id(), $order->get_id() );
		if ( null === $settled ) {
			return;
		}

		/**
		 * Fires after a Lite renewal order was paid and its cycle billed.
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
	 */
	private function record_payment( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now ): void {
		$date_paid    = $order->get_date_paid();
		$last_payment = null === $date_paid ? $now : new DateTimeImmutable( '@' . $date_paid->getTimestamp() );
		$contract_id  = $contract->get_id();
		$status       = $contract->get_status();

		if ( ! in_array( $status, [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			Contracts::update( $contract_id, [ 'last_payment_gmt' => $last_payment ] );
			return;
		}

		$policy = ContractPlans::billing_policy_for( $contract );
		$next   = null === $policy
			? null
			: BillingPeriod::next_due_after( new DateTimeImmutable( $cycle->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ), $now, $policy );

		if ( ContractStatus::ON_HOLD === $status && null !== HoldAnchor::read( $contract_id ) ) {
			// A customer hold absorbs a late payment: it resumes from the period after this one.
			if ( null !== $next ) {
				HoldAnchor::store( $contract_id, $next->format( 'Y-m-d H:i:s' ) );
			}
			Contracts::update( $contract_id, [ 'last_payment_gmt' => $last_payment ] );
			return;
		}

		Contracts::update(
			$contract_id,
			[
				'status'           => ContractStatus::ACTIVE,
				'next_payment_gmt' => $next,
				'last_payment_gmt' => $last_payment,
			]
		);
		HoldAnchor::clear( $contract_id );
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
			? null === ContractPlans::billing_policy_for( $contract )
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
	 * The failed order fails its cycle; an active contract goes on hold until the customer pays.
	 *
	 * @param WC_Order          $order    The failed order.
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle the order bills.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 */
	private function settle_failed( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now ): void {
		if ( ! in_array( $cycle->get_status(), self::OPEN_CYCLE_STATUSES, true )
			|| ! Contracts::transition_cycle( $cycle->get_id(), $cycle->get_status(), CycleStatus::FAILED, [ 'reason' => self::REASON_PAYMENT_FAILED ] ) ) {
			return;
		}

		$contract_id = $contract->get_id();
		if ( in_array( $contract->get_status(), [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			// Also over a customer hold: its anchor would sit on the end of an unpaid period.
			SystemHold::place( $contract_id, [ 'last_attempt_gmt' => $now ] );
		}

		$settled = $this->read_settled( $contract_id, $cycle->get_id(), $order->get_id() );
		if ( null === $settled ) {
			return;
		}

		/**
		 * Fires after a Lite renewal order failed and its cycle was marked failed.
		 *
		 * @param ContractView $contract The contract after the failure was recorded.
		 * @param CycleView    $cycle    The failed cycle.
		 * @param WC_Order     $order    The failed renewal order.
		 */
		do_action( 'woocommerce_subscriptions_lite_renewal_failed', $settled['contract'], $settled['cycle'], $order );
	}

	/**
	 * An order awaiting an asynchronous charge moves its cycle to processing (no lease) and
	 * clears the contract's due moment, so the due scan leaves it alone until the charge
	 * reports: paid re-arms the next period, failed holds the contract.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The cycle the order bills.
	 */
	private function settle_in_progress( ContractView $contract, CycleView $cycle ): void {
		if ( CycleStatus::PENDING === $cycle->get_status()
			&& Contracts::transition_cycle( $cycle->get_id(), CycleStatus::PENDING, CycleStatus::PROCESSING ) ) {
			Contracts::update( $contract->get_id(), [ 'next_payment_gmt' => null ] );
		}
	}

	/**
	 * An unpaid cycle whose order was cancelled or trashed fails, and an active contract goes
	 * on hold: the renewal is still owed, and only a payment resumes the contract.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The cycle the order bills.
	 */
	private function settle_dead( ContractView $contract, CycleView $cycle ): void {
		if ( ! in_array( $cycle->get_status(), self::OPEN_CYCLE_STATUSES, true )
			|| ! Contracts::transition_cycle( $cycle->get_id(), $cycle->get_status(), CycleStatus::FAILED, [ 'reason' => self::REASON_ORDER_CANCELLED ] ) ) {
			return;
		}

		if ( in_array( $contract->get_status(), [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			SystemHold::place( $contract->get_id() );
		}
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
	 * A replaced renewal order was paid after all. While its renewal is still unpaid, it takes
	 * the renewal back: the cycle is relinked to it, the unpaid replacement is cancelled, and
	 * the payment settles. Once the replacement paid the renewal, the customer paid twice:
	 * the order gets a note and a warning is logged, so the merchant refunds one payment.
	 *
	 * @param WC_Order               $order The paid replaced order.
	 * @param DateTimeImmutable|null $now   The current moment.
	 */
	private function settle_replaced_paid( WC_Order $order, ?DateTimeImmutable $now ): void {
		$contract_id = (int) $order->get_meta( self::REPLACED_CONTRACT_META );
		$cycle_id    = (int) $order->get_meta( self::REPLACED_CYCLE_META );
		$contract    = $contract_id > 0 ? Contracts::get( $contract_id ) : null;
		$cycle       = null === $contract || $cycle_id <= 0 ? null : self::find_cycle( $contract_id, $cycle_id );
		if ( null === $cycle || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return;
		}

		$replacement_id = $cycle->get_order_id();

		if ( in_array( $cycle->get_status(), self::PAYABLE_CYCLE_STATUSES, true ) ) {
			if ( ! Contracts::transition_cycle( $cycle_id, $cycle->get_status(), $cycle->get_status(), [ 'order_id' => $order->get_id() ] ) ) {
				return;
			}

			$replacement = null === $replacement_id ? false : wc_get_order( $replacement_id );
			if ( $replacement instanceof WC_Order && ! $replacement->is_paid() && ! self::is_dead( $replacement ) ) {
				/* translators: %d: order number. */
				$replacement->update_status( OrderStatus::CANCELLED, sprintf( __( 'Order #%d paid this renewal after this order replaced it.', 'woocommerce-subscriptions-lite' ), $order->get_id() ) );
			}

			$this->settle( $order, $now );
			return;
		}

		if ( CycleStatus::BILLED !== $cycle->get_status() || $replacement_id === $order->get_id() || '' !== (string) $order->get_meta( self::REFUND_FLAGGED_META ) ) {
			return;
		}

		/* translators: %d: order number. */
		$order->add_order_note( sprintf( __( 'This renewal order was paid after order #%d replaced it and paid the same renewal. Refund one of the two payments.', 'woocommerce-subscriptions-lite' ), (int) $replacement_id ) );
		$order->update_meta_data( self::REFUND_FLAGGED_META, '1' );
		$order->save();
		wc_get_logger()->warning(
			sprintf( 'Settlement: renewal order %d was paid after order %d replaced it and paid the same renewal; a refund is needed.', $order->get_id(), (int) $replacement_id ),
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
	 * The newest cycle billed by the order on a Lite-owned contract, with its contract.
	 *
	 * @param int $order_id Order id.
	 * @return array{contract: ContractView, cycle: CycleView}|null
	 */
	private function find_lite_cycle( int $order_id ): ?array {
		foreach ( Contracts::find_cycles_by_order( $order_id ) as $cycle ) {
			$contract = Contracts::get( $cycle->get_contract_id() );
			if ( null !== $contract && Package::EXTENSION_SLUG === $contract->get_extension_slug() ) {
				return [
					'contract' => $contract,
					'cycle'    => $cycle,
				];
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
