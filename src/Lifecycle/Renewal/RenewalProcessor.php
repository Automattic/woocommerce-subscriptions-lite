<?php
/**
 * RenewalProcessor - renews a Lite contract: one cycle, one order, one charge.
 *
 * Runs under the contract lock (the dispatcher's, or Renew now's own), held through the
 * charge; the next lock holder resumes a cycle an interrupted run left pending. A gateway that
 * declares the engine's feature and `subscriptions` is charged through the engine; any other
 * gets a manual renewal order, with the contract on hold until it is paid.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use DateTimeImmutable;
use DateTimeZone;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal processor.
 */
final class RenewalProcessor {

	/**
	 * Operation label of a Renew now run's contract lock.
	 */
	public const OPERATION = 'renewal';

	/**
	 * The gateway feature, besides the engine's, that says a gateway can charge renewals
	 * automatically: the WooCommerce feature gateways already declare for subscriptions.
	 */
	public const GATEWAY_RENEWAL_FEATURE = 'subscriptions';

	/**
	 * Failure reason recorded on a cycle whose renewal order could not be built.
	 */
	public const REASON_ORDER_BUILD_FAILED = 'order-build-failed';

	/**
	 * Renew `$contract` for the period due at `$anchor` under `$lock` (the dispatcher's or
	 * Renew now's), registered as held in this request; every engine write passes it.
	 *
	 * @param ContractView      $contract The contract (read under the lock, with its items and addresses).
	 * @param DateTimeImmutable $anchor   The due moment the period is anchored on.
	 * @param DateTimeImmutable $now      The current moment.
	 * @param bool              $manual   Whether a person asked for the renewal (retries a failed cycle).
	 * @param ContractLock      $lock     The held lock on the contract.
	 * @return WC_Order|null The renewal order; null when nothing was renewed (in flight,
	 *                       awaiting payment, no billing policy, or the order could not be built).
	 */
	public function renew( ContractView $contract, DateTimeImmutable $anchor, DateTimeImmutable $now, bool $manual, ContractLock $lock ): ?WC_Order {
		$contract_id = $contract->get_id();
		$policy      = ContractPlans::get_billing_policy_for( $contract );
		$head        = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;
		if ( null === $policy || null === $head ) {
			$this->log( 'warning', sprintf( 'contract %d has no billing policy or no billing chain; its due moment is cleared.', $contract_id ), $contract_id );
			Contracts::update( $contract_id, [ 'next_payment_gmt' => null ], $lock );
			return null;
		}

		$cycle = $this->take_cycle( $contract, $head, $anchor, $now, $manual, $policy, $lock );
		if ( null === $cycle ) {
			return null;
		}

		$order = $this->ensure_cycle_order( $contract, $cycle, $lock );
		if ( null === $order ) {
			return null;
		}

		if ( $order->has_status( OrderStatus::CHECKOUT_DRAFT ) ) {
			$order->set_status( OrderStatus::PENDING );
			$order->save();
		}

		$this->collect_payment( $contract, $cycle, $order, $now, $lock );

		return $order;
	}

	/**
	 * Renew a Lite contract now, on request (admin "Renew now"), under its lock: an active
	 * contract bills its next period; an on-hold contract with an unpaid renewal retries that
	 * renewal. The contract and its head are read after acquiring.
	 *
	 * @param int $contract_id Contract id.
	 * @return WC_Order|null The renewal order; null when the contract is not renewable now.
	 * @throws ContractLockedException If another operation holds the contract's lock.
	 */
	public function renew_now( int $contract_id ): ?WC_Order {
		$lock     = HeldLocks::get( $contract_id );
		$acquired = null === $lock;
		if ( $acquired ) {
			$lock = Contracts::lock( $contract_id, self::OPERATION );
			if ( null === $lock ) {
				$conflict = HeldLocks::get_conflict( $contract_id );
				if ( null === $conflict ) {
					return null;
				}
				throw $conflict;
			}
			HeldLocks::register( $lock );
		}

		try {
			// can_renew_now() also checks the contract is Lite's.
			$contract = Contracts::get( $contract_id );
			if ( null === $contract || ! self::can_renew_now( $contract ) ) {
				return null;
			}

			$utc    = new DateTimeZone( 'UTC' );
			$now    = new DateTimeImmutable( 'now', $utc );
			$head   = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;
			$anchor = $contract->get_next_payment_gmt() ?? ( null === $head ? null : $head->get_ends_at_gmt() );

			return $this->renew( $contract, null === $anchor ? $now : new DateTimeImmutable( $anchor, $utc ), $now, true, $lock );
		} finally {
			if ( $acquired ) {
				HeldLocks::release( $lock );
			}
		}
	}

	/**
	 * Whether Renew now applies to a contract: a Lite contract that is active (bills its next
	 * period now), or on hold with an unpaid renewal (retries that renewal).
	 *
	 * @param ContractView $contract The contract.
	 */
	public static function can_renew_now( ContractView $contract ): bool {
		if ( Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return false;
		}

		$status = $contract->get_status();

		return ContractStatus::ACTIVE === $status
			|| ( ContractStatus::ON_HOLD === $status && null !== UnpaidRenewal::find( $contract ) );
	}

	/**
	 * Take the cycle this run renews: resume the head when it is an interrupted (pending) or
	 * (manual) failed renewal, else append the next one. Null when the head waits on a charge
	 * confirmation, or the head already settled and only the contract write that
	 * should have followed was lost (re-applied here: a billed head that covers the period
	 * re-arms the next due moment, a failed head holds the contract), so a settlement cut
	 * short never bills the same period twice.
	 *
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $head     The newest cycle.
	 * @param DateTimeImmutable $anchor   The due moment.
	 * @param DateTimeImmutable $now      The current moment.
	 * @param bool              $manual   Whether a person asked.
	 * @param BillingPolicy     $policy   The billing policy.
	 * @param ContractLock      $lock     The held lock.
	 */
	private function take_cycle( ContractView $contract, CycleView $head, DateTimeImmutable $anchor, DateTimeImmutable $now, bool $manual, BillingPolicy $policy, ContractLock $lock ): ?CycleView {
		switch ( $head->get_status() ) {
			case CycleStatus::PENDING:
				// The lock excludes a concurrent run, so a pending head is one an interrupted run left.
				return $head;
			case CycleStatus::FAILED:
				if ( ! $manual ) {
					$this->reapply_hold( $contract, $lock );
					return null;
				}
				$retried = Contracts::transition_cycle(
					$head->get_id(),
					[
						'from_status' => CycleStatus::FAILED,
						'to_status'   => CycleStatus::PENDING,
					],
					$lock
				);
				return $retried ? $head : null;
			case CycleStatus::PROCESSING:
				$this->log( 'info', sprintf( 'contract %d is due while its renewal awaits a charge confirmation; nothing is charged.', $contract->get_id() ), $contract->get_id() );
				return null;
			default:
				break;
		}

		[ $starts_at, $ends_at ] = BillingTerms::get_period_to_bill( $anchor, $now, $policy );

		if ( CycleStatus::BILLED === $head->get_status() && new DateTimeImmutable( $head->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ) >= $ends_at ) {
			$this->reapply_schedule( $contract, $head, $now, $policy, $lock );
			return null;
		}

		// Null when the position is taken, which the lock leaves to a writer outside Lite.
		return Contracts::add_cycle(
			$contract->get_id(),
			[
				'status'         => CycleStatus::PENDING,
				'count'          => (int) $head->get_count() + 1,
				'starts_at_gmt'  => $starts_at,
				'ends_at_gmt'    => $ends_at,
				'expected_total' => $contract->get_billing_total(),
				'currency'       => $contract->get_currency() ?? get_woocommerce_currency(),
			],
			$lock
		);
	}

	/**
	 * Re-apply the contract write of a paid settlement: the next due moment after the billed
	 * head, rolled past now.
	 *
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $head     The billed head.
	 * @param DateTimeImmutable $now      The current moment.
	 * @param BillingPolicy     $policy   The billing policy.
	 * @param ContractLock      $lock     The held lock.
	 */
	private function reapply_schedule( ContractView $contract, CycleView $head, DateTimeImmutable $now, BillingPolicy $policy, ContractLock $lock ): void {
		$this->log( 'warning', sprintf( 'contract %d is due for a period its newest cycle already billed; its next due moment is re-applied.', $contract->get_id() ), $contract->get_id() );
		Contracts::update(
			$contract->get_id(),
			[ 'next_payment_gmt' => BillingTerms::get_next_due_after( new DateTimeImmutable( $head->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ), $now, $policy ) ],
			$lock
		);
	}

	/**
	 * Re-apply the contract write of a failed settlement: hold the contract until the
	 * customer pays the failed order.
	 *
	 * @param ContractView $contract The contract.
	 * @param ContractLock $lock     The held lock.
	 */
	private function reapply_hold( ContractView $contract, ContractLock $lock ): void {
		$this->log( 'warning', sprintf( 'contract %d is due while its newest renewal failed; it is put on hold until the renewal is paid.', $contract->get_id() ), $contract->get_id() );
		Hold::system_hold( $lock );
	}

	/**
	 * The order of a taken cycle: its linked order when it has one that can still be paid,
	 * else a new draft built and linked in one compare-and-set (a cancelled, trashed or
	 * deleted order is replaced).
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The taken cycle.
	 * @param ContractLock $lock     The held lock.
	 */
	private function ensure_cycle_order( ContractView $contract, CycleView $cycle, ContractLock $lock ): ?WC_Order {
		$order_id = $cycle->get_order_id();
		$replaced = null === $order_id ? false : wc_get_order( $order_id );
		if ( $replaced instanceof WC_Order && ! Settlement::is_dead( $replaced ) ) {
			return $replaced;
		}

		$order = ( new RenewalOrderBuilder() )->build( $contract, $cycle );
		if ( null === $order ) {
			// The renewal is owed but cannot be billed: fail the cycle and hold the contract, as
			// for a dead order. Renew now retries it once the cause (a deleted product, a
			// database error) is fixed.
			$this->log( 'error', sprintf( 'contract %d: the renewal order could not be built; the renewal failed and the contract is on hold until it is renewed by hand.', $contract->get_id() ), $contract->get_id() );
			$failed = Contracts::transition_cycle(
				$cycle->get_id(),
				[
					'from_status' => CycleStatus::PENDING,
					'to_status'   => CycleStatus::FAILED,
					'reason'      => self::REASON_ORDER_BUILD_FAILED,
				],
				$lock
			);
			if ( $failed ) {
				Hold::system_hold( $lock );
			}
			return null;
		}

		$linked = Contracts::transition_cycle(
			$cycle->get_id(),
			[
				'from_status' => CycleStatus::PENDING,
				'to_status'   => CycleStatus::PENDING,
				'order_id'    => $order->get_id(),
			],
			$lock
		);
		if ( ! $linked ) {
			$order->delete( true );
			return null;
		}

		if ( $replaced instanceof WC_Order ) {
			// Should the dead order still be paid (a late gateway confirmation), settlement
			// finds the renewal it was replaced on and never bills it twice.
			Settlement::mark_replaced( $replaced, $contract->get_id(), $cycle->get_id() );
		}

		return $order;
	}

	/**
	 * Collect the order's payment: settle a paid order or one whose asynchronous charge is in
	 * flight, complete a free one, charge through the engine when the gateway supports it, else
	 * leave the order for the customer. A charge the gateway left pending is left for payment
	 * too; every charge is guarded by a settlement past the lock's expiry ({@see Settlement::guard_charge()}).
	 *
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle being billed.
	 * @param WC_Order          $order    The pending renewal order.
	 * @param DateTimeImmutable $now      The current moment.
	 * @param ContractLock      $lock     The held lock, kept through the charge.
	 */
	private function collect_payment( ContractView $contract, CycleView $cycle, WC_Order $order, DateTimeImmutable $now, ContractLock $lock ): void {
		$settlement = new Settlement();

		// Paid, or an asynchronous charge in flight (a resumed run whose settlement was cut
		// short): settle what the order says instead of charging it again.
		if ( $order->is_paid() || $order->has_status( OrderStatus::ON_HOLD ) ) {
			$settlement->settle( $order, $now );
			return;
		}

		if ( (float) $order->get_total() <= 0 ) {
			Settlement::guard_charge( $order->get_id() );
			$order->payment_complete();
			$settlement->settle( $order, $now );
			return;
		}

		if ( ! Payments::gateway_supports( (string) $contract->get_payment_method(), self::GATEWAY_RENEWAL_FEATURE ) ) {
			$this->leave_for_payment(
				$contract,
				$cycle,
				$order,
				$lock,
				__( 'Renewal order created for manual payment: the payment method cannot be charged automatically. The subscription is on hold until the customer pays this order.', 'woocommerce-subscriptions-lite' )
			);
			return;
		}

		Settlement::guard_charge( $order->get_id() );
		$handed_over = Payments::process_scheduled_payment( $order );
		$charged     = wc_get_order( $order->get_id() );
		if ( $charged instanceof WC_Order && $charged->has_status( OrderStatus::PENDING ) ) {
			// The gateway reported no outcome yet. A false hand-over may also mean it threw part
			// way, so that note does not claim the charge never ran.
			$this->leave_for_payment(
				$contract,
				$cycle,
				$charged,
				$lock,
				$handed_over
					? __( 'Renewal payment not completed yet: the payment gateway did not report an outcome. The subscription is on hold until this order is paid.', 'woocommerce-subscriptions-lite' )
					: __( 'Renewal payment not completed: the payment gateway reported an error. Check the payment gateway logs before taking a payment again. The subscription is on hold until this order is paid.', 'woocommerce-subscriptions-lite' )
			);
			return;
		}

		$settlement->settle( $order, $now );
	}

	/**
	 * Leave a pending renewal order for payment: note it, confirm the cycle is still pending
	 * on this order, and put the contract on the system hold, so it is not charged again.
	 * Paying the order settles the cycle and resumes the contract. Under the lock the cycle
	 * cannot move, so a lost compare-and-set is an anomaly: it is logged and nothing is held.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The cycle the order bills.
	 * @param WC_Order     $order    The pending renewal order.
	 * @param ContractLock $lock     The held lock.
	 * @param string       $note     Order note saying why the order awaits payment.
	 */
	private function leave_for_payment( ContractView $contract, CycleView $cycle, WC_Order $order, ContractLock $lock, string $note ): void {
		$order->add_order_note( $note );
		$pending = Contracts::transition_cycle(
			$cycle->get_id(),
			[
				'from_status' => CycleStatus::PENDING,
				'to_status'   => CycleStatus::PENDING,
				'order_id'    => $order->get_id(),
			],
			$lock
		);
		if ( ! $pending ) {
			$this->log( 'warning', sprintf( 'contract %d: cycle %d is no longer pending on order %d; the contract is not held for its payment.', $contract->get_id(), $cycle->get_id(), $order->get_id() ), $contract->get_id() );
			return;
		}

		Hold::system_hold( $lock );
	}

	/**
	 * Log under the Lite source.
	 *
	 * @param string $level       Log level.
	 * @param string $message     Message, without the class prefix.
	 * @param int    $contract_id Contract id.
	 */
	private function log( string $level, string $message, int $contract_id ): void {
		wc_get_logger()->log(
			$level,
			'RenewalProcessor: ' . $message,
			[
				'source'      => Package::EXTENSION_SLUG,
				'contract_id' => $contract_id,
			]
		);
	}
}
