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

	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

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

		$order = $this->get_cycle_order( $contract, $cycle );
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
		if ( null === $contract || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return null;
		}

		$status = $contract->get_status();
		if ( ContractStatus::ACTIVE !== $status && ! ( ContractStatus::ON_HOLD === $status && null !== UnpaidRenewal::find( $contract ) ) ) {
			return null;
		}

		$utc    = new DateTimeZone( 'UTC' );
		$now    = new DateTimeImmutable( 'now', $utc );
		$head   = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;
		$anchor = $contract->get_next_payment_gmt() ?? ( null === $head ? null : $head->get_ends_at_gmt() );

		return $this->renew( $contract, null === $anchor ? $now : new DateTimeImmutable( $anchor, $utc ), $now, true );
	}

	/**
	 * Take the cycle this run renews: resume the head when it is an interrupted or (manual)
	 * failed renewal, else append the next one, claimed. Null when another run holds it or
	 * the head waits on a payment.
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
				return Contracts::claim_cycle( $head->get_id(), self::LEASE_SECONDS ) ? $head : null;
			case CycleStatus::FAILED:
				if ( ! $manual ) {
					return null;
				}
				return Contracts::transition_cycle( $head->get_id(), CycleStatus::FAILED, CycleStatus::PENDING, [ 'lease_seconds' => self::LEASE_SECONDS ] ) ? $head : null;
			case CycleStatus::PROCESSING:
				return null;
			default:
				break;
		}

		[ $starts_at, $ends_at ] = BillingPeriod::containing( $anchor, $now, $policy );

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
	 * The order of a taken cycle: its linked order when it has one, else a new draft built
	 * and linked while the lease is held.
	 *
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The taken cycle.
	 */
	private function get_cycle_order( ContractView $contract, CycleView $cycle ): ?WC_Order {
		$order_id = $cycle->get_order_id();
		if ( null !== $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		$order = ( new RenewalOrderBuilder() )->build( $contract, $cycle );
		if ( null === $order ) {
			return null; // The cycle stays leased; a later run resumes it once the lease expires.
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
			Payments::charge( $order );
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
				'source'      => self::LOG_SOURCE,
				'contract_id' => $contract_id,
			]
		);
	}
}
