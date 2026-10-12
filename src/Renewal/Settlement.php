<?php
/**
 * Settlement - records the outcome of Lite renewal orders on their cycles and contracts.
 *
 * Listens to the order status of Lite's own renewal orders (`created_via`
 * {@see RenewalOrderBuilder::CREATED_VIA}) and moves the billed cycle with the engine's
 * compare-and-set tool, so a repeated or concurrent signal settles once:
 * - paid: cycle billed; an active or held contract becomes active with its next due
 *   moment armed past now; a winding-down or cancelled contract only records the payment;
 * - failed: cycle failed; an active contract goes on hold with no due moment, and the
 *   customer pays the failed order to resume;
 * - on-hold (an asynchronous charge awaits confirmation): cycle processing, so it is not
 *   charged again.
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
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use DateTimeImmutable;
use DateTimeZone;
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
		add_action( 'woocommerce_payment_complete', [ $instance, 'handle_order' ] );
		add_action( 'woocommerce_order_status_failed', [ $instance, 'handle_order' ] );
		add_action( 'woocommerce_order_status_on-hold', [ $instance, 'handle_order' ] );
		foreach ( wc_get_is_paid_statuses() as $paid_status ) {
			add_action( 'woocommerce_order_status_' . $paid_status, [ $instance, 'handle_order' ] );
		}
	}

	/**
	 * Hook entry point: settle the order with this id.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function handle_order( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( $order instanceof WC_Order ) {
			$this->settle( $order );
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
			return;
		}

		$utc_now = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );

		if ( $fresh_order->is_paid() ) {
			$this->settle_paid( $fresh_order, $match['contract'], $match['cycle'], $utc_now );
		} elseif ( $fresh_order->has_status( OrderStatus::FAILED ) ) {
			$this->settle_failed( $fresh_order, $match['contract'], $match['cycle'], $utc_now );
		} elseif ( $fresh_order->has_status( OrderStatus::ON_HOLD ) ) {
			$this->settle_in_progress( $match['cycle'] );
		}
	}

	/**
	 * The paid order bills its cycle; the contract resumes or records the payment.
	 *
	 * @param WC_Order          $order    The paid order.
	 * @param ContractView      $contract The contract.
	 * @param CycleView         $cycle    The cycle the order bills.
	 * @param DateTimeImmutable $now      The current moment (UTC).
	 */
	private function settle_paid( WC_Order $order, ContractView $contract, CycleView $cycle, DateTimeImmutable $now ): void {
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

		$date_paid    = $order->get_date_paid();
		$last_payment = null === $date_paid ? $now : new DateTimeImmutable( '@' . $date_paid->getTimestamp() );
		$contract_id  = $contract->get_id();

		if ( in_array( $contract->get_status(), [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ) ) {
			$policy = ContractPlans::billing_policy_for( $contract );
			$next   = null === $policy
				? null
				: BillingPeriod::next_due_after( new DateTimeImmutable( $cycle->get_ends_at_gmt(), new DateTimeZone( 'UTC' ) ), $now, $policy );
			Contracts::update(
				$contract_id,
				[
					'status'           => ContractStatus::ACTIVE,
					'next_payment_gmt' => $next,
					'last_payment_gmt' => $last_payment,
				]
			);
			HoldAnchor::clear( $contract_id );
		} else {
			Contracts::update( $contract_id, [ 'last_payment_gmt' => $last_payment ] );
		}

		$settled = $this->read_settled( $contract_id, $cycle->get_id(), $order->get_id() );
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
		if ( ContractStatus::ACTIVE === $contract->get_status() ) {
			Contracts::update(
				$contract_id,
				[
					'status'           => ContractStatus::ON_HOLD,
					'next_payment_gmt' => null,
					'last_attempt_gmt' => $now,
				]
			);
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
	 * An order awaiting an asynchronous charge moves its cycle to processing (no lease), so
	 * the next notification does not charge it again.
	 *
	 * @param CycleView $cycle The cycle the order bills.
	 */
	private function settle_in_progress( CycleView $cycle ): void {
		if ( CycleStatus::PENDING === $cycle->get_status() ) {
			Contracts::transition_cycle( $cycle->get_id(), CycleStatus::PENDING, CycleStatus::PROCESSING );
		}
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
