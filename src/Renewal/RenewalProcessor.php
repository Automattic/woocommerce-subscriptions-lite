<?php
/**
 * RenewalProcessor - renews a Lite contract: one cycle, one order, one charge.
 *
 * Works on the engine's cycle tools so concurrent runs renew once: the new cycle is
 * appended claimed (append-if-absent with a lease), an interrupted cycle is resumed only
 * by the run that takes its expired lease, and settlement moves cycles by
 * compare-and-set. A due contract several periods behind bills the one period containing
 * now ({@see BillingPeriod}). Gateways that accept engine charges are charged through the
 * engine; any other gateway gets a manual renewal order the customer pays, with the
 * contract on hold until then.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal processor.
 */
final class RenewalProcessor {

	/**
	 * How long a run holds the cycle it renews, in seconds.
	 */
	public const LEASE_SECONDS = 900;

	/**
	 * Renew `$contract` for the period due at `$anchor`.
	 *
	 * @param ContractView      $contract The contract (read with its items and addresses).
	 * @param DateTimeImmutable $anchor   The due moment the period is anchored on.
	 * @param DateTimeImmutable $now      The current moment.
	 * @param bool              $manual   Whether a person asked for the renewal (retries a failed cycle).
	 * @return WC_Order|null The renewal order; null when nothing was renewed (in flight,
	 *                       awaiting payment, no billing policy, or the order could not be built).
	 */
	public function renew( ContractView $contract, DateTimeImmutable $anchor, DateTimeImmutable $now, bool $manual ): ?WC_Order {
		$contract_id = $contract->get_id();
		$policy      = ContractPlans::billing_policy_for( $contract );
		$head        = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;
		if ( null === $policy || null === $head ) {
			$this->log( 'warning', sprintf( 'contract %d has no billing policy or no billing chain; its due moment is cleared.', $contract_id ), $contract_id );
			Contracts::update( $contract_id, [ 'next_payment_gmt' => null ] );
			return null;
		}

		$cycle = $this->take_cycle( $contract, $head, $anchor, $now, $manual, $policy );
		if ( null === $cycle ) {
			return null;
		}

		$order = $this->ensure_cycle_order( $contract, $cycle );
		if ( null === $order ) {
			return null;
		}

		if ( $order->has_status( OrderStatus::CHECKOUT_DRAFT ) ) {
			$order->set_status( OrderStatus::PENDING );
			$order->save();
		}

		$this->collect_payment( $contract, $cycle, $order, $now );

		return $order;
	}

	/**
	 * Renew a Lite contract now, on request (admin "Renew now"): an active contract bills its
	 * next period; an on-hold contract with an unpaid renewal retries that renewal.
	 *
	 * @param int $contract_id Contract id.
	 * @return WC_Order|null The renewal order; null when the contract is not renewable now.
	 */
	public function renew_now( int $contract_id ): ?WC_Order {
		$contract = Contracts::get( $contract_id );
		if ( null === $contract || ! self::can_renew_now( $contract ) ) {
			return null;
		}

		$utc    = new DateTimeZone( 'UTC' );
		$now    = new DateTimeImmutable( 'now', $utc );
		$head   = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;
		$anchor = $contract->get_next_payment_gmt() ?? ( null === $head ? null : $head->get_ends_at_gmt() );

		return $this->renew( $contract, null === $anchor ? $now : new DateTimeImmutable( $anchor, $utc ), $now, true );
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
	 * Take the cycle this run renews: resume the head when it is an interrupted or (manual)
	 * failed renewal, else append the next one, claimed. Null when another run holds it, the
	 * head waits on a payment, or the head already settled and only the contract write that
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
	 */
	private function take_cycle( ContractView $contract, CycleView $head, DateTimeImmutable $anchor, DateTimeImmutable $now, bool $manual, BillingPolicy $policy ): ?CycleView {
		switch ( $head->get_status() ) {
			case CycleStatus::PENDING:
				return Contracts::claim_cycle( $head->get_id(), CycleStatus::PENDING, self::LEASE_SECONDS ) ? $head : null;
			case CycleStatus::FAILED:
				if ( ! $manual ) {
					$this->reapply_hold( $contract );
					return null;
				}
				return Contracts::transition_cycle( $head->get_id(), CycleStatus::FAILED, CycleStatus::PENDING, [ 'lease_seconds' => self::LEASE_SECONDS ] ) ? $head : null;
			case CycleStatus::PROCESSING:
				$this->log( 'info', sprintf( 'contract %d is due while its renewal awaits a charge confirmation; nothing is charged.', $contract->get_id() ), $contract->get_id() );
				return null;
			default:
				break;
		}

		[ $starts_at, $ends_at ] = BillingPeriod::containing( $anchor, $now, $policy );

		if ( CycleStatus::BILLED === $head->get_status() && new DateTimeImmutable( $head->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ) >= $ends_at ) {
			$this->reapply_schedule( $contract, $head, $now, $policy );
			return null;
		}

		try {
			return Contracts::add_cycle(
				$contract->get_id(),
				[
					'status'         => CycleStatus::PENDING,
					'count'          => (int) $head->get_count() + 1,
					'starts_at_gmt'  => $starts_at,
					'ends_at_gmt'    => $ends_at,
					'expected_total' => $contract->get_billing_total(),
					'currency'       => $contract->get_currency() ?? get_woocommerce_currency(),
					'lease_seconds'  => self::LEASE_SECONDS,
				]
			);
		} catch ( DomainException $e ) {
			return null; // A concurrent run appended this cycle.
		}
	}

	/**
	 * Re-apply the contract write of a paid settlement: the next due moment after the billed
	 * head, rolled past now.
	 *
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $head     The billed head.
	 * @param DateTimeImmutable $now      The current moment.
	 * @param BillingPolicy     $policy   The billing policy.
	 */
	private function reapply_schedule( ContractView $contract, CycleView $head, DateTimeImmutable $now, BillingPolicy $policy ): void {
		$this->log( 'warning', sprintf( 'contract %d is due for a period its newest cycle already billed; its next due moment is re-applied.', $contract->get_id() ), $contract->get_id() );
		Contracts::update(
			$contract->get_id(),
			[ 'next_payment_gmt' => BillingPeriod::next_due_after( new DateTimeImmutable( $head->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ), $now, $policy ) ]
		);
	}

	/**
	 * Re-apply the contract write of a failed settlement: hold the contract until the
	 * customer pays the failed order.
	 *
	 * @param ContractView $contract The contract.
	 */
	private function reapply_hold( ContractView $contract ): void {
		$this->log( 'warning', sprintf( 'contract %d is due while its newest renewal failed; it is put on hold until the renewal is paid.', $contract->get_id() ), $contract->get_id() );
		Contracts::update(
			$contract->get_id(),
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		HoldAnchor::clear( $contract->get_id() ); // A leftover anchor would read as a customer hold.
	}

	/**
	 * The order of a taken cycle: its linked order when it has one that can still be paid,
	 * else a new draft built and linked while the lease is held (a cancelled, trashed or
	 * deleted order is replaced).
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The taken cycle.
	 */
	private function ensure_cycle_order( ContractView $contract, CycleView $cycle ): ?WC_Order {
		$order_id = $cycle->get_order_id();
		if ( null !== $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && ! Settlement::is_dead( $order ) ) {
				return $order;
			}
		}

		$order = ( new RenewalOrderBuilder() )->build( $contract, $cycle );
		if ( null === $order ) {
			// A build that failed once (a deleted product) fails again: take the contract off the
			// due scan and release the cycle, so Renew now can retry once the cause is fixed.
			$this->log( 'error', sprintf( 'contract %d: the renewal order could not be built; its due moment is cleared until it is renewed by hand.', $contract->get_id() ), $contract->get_id() );
			Contracts::transition_cycle( $cycle->get_id(), CycleStatus::PENDING, CycleStatus::PENDING );
			Contracts::update( $contract->get_id(), [ 'next_payment_gmt' => null ] );
			return null;
		}

		$linked = Contracts::transition_cycle(
			$cycle->get_id(),
			CycleStatus::PENDING,
			CycleStatus::PENDING,
			[
				'order_id'      => $order->get_id(),
				'lease_seconds' => self::LEASE_SECONDS,
			]
		);
		if ( ! $linked ) {
			$order->delete( true );
			return null;
		}

		return $order;
	}

	/**
	 * Collect the order's payment: settle an already paid order, complete a free one, charge
	 * through the engine when the gateway accepts engine charges, else leave a manual
	 * renewal order for the customer and hold the contract.
	 *
	 * After a charge the order status is the outcome: paid, failed, or on-hold while an
	 * asynchronous charge awaits confirmation. An order still pending (the charge was not
	 * handed over, the gateway threw, or it reported nothing) is a failed charge: the order
	 * is marked failed, so the cycle fails and the contract waits on the customer's payment.
	 *
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle being billed.
	 * @param WC_Order          $order    The pending renewal order.
	 * @param DateTimeImmutable $now      The current moment.
	 */
	private function collect_payment( ContractView $contract, CycleView $cycle, WC_Order $order, DateTimeImmutable $now ): void {
		$settlement = new Settlement();

		if ( $order->is_paid() ) {
			$settlement->settle( $order, $now );
			return;
		}

		if ( (float) $order->get_total() <= 0 ) {
			$order->payment_complete();
			$settlement->settle( $order, $now );
			return;
		}

		if ( Payments::supports_gateway( (string) $contract->get_payment_method() ) ) {
			$handed_over = Payments::charge( $order );
			$charged     = wc_get_order( $order->get_id() );
			if ( $charged instanceof WC_Order && $charged->has_status( OrderStatus::PENDING ) ) {
				// No outcome reported: nothing is in flight, so this is a failed charge the
				// customer can still pay on order-pay.
				$charged->update_status(
					OrderStatus::FAILED,
					$handed_over
						? __( 'Renewal payment failed: the payment gateway did not report an outcome for the charge.', 'woocommerce-subscriptions-lite' )
						: __( 'Renewal payment failed: the charge could not be handed to the payment gateway.', 'woocommerce-subscriptions-lite' )
				);
			}
			$settlement->settle( $order, $now );
			return;
		}

		$order->add_order_note( __( 'Renewal order created for manual payment: the payment method cannot be charged automatically. The subscription is on hold until the customer pays this order.', 'woocommerce-subscriptions-lite' ) );
		Contracts::transition_cycle( $cycle->get_id(), CycleStatus::PENDING, CycleStatus::PENDING, [ 'order_id' => $order->get_id() ] );
		Contracts::update(
			$contract->get_id(),
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);
		HoldAnchor::clear( $contract->get_id() ); // A leftover anchor would read as a customer hold.
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
