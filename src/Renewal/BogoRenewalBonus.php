<?php
/**
 * BogoRenewalBonus - grants BOGO bonus units on renewal orders of Lite contracts.
 *
 * Listens on the engine's renewal-order-created action. For a Lite-owned
 * contract whose terms carry an in-scope `bogo` entry for the renewal's cycle,
 * each product line's quantity grows by its bonus units. Money-neutral: line
 * and order totals are never touched, so the cycle's expected total stays the
 * price authority.
 *
 * Terms come from the contract's plan snapshot, falling back to the live plan
 * when the snapshot predates the `pricing_policy` key.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PricingTerms;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * BOGO bonus units on renewal orders.
 */
final class BogoRenewalBonus {

	/**
	 * Register the renewal listener.
	 */
	public static function register(): void {
		add_action( 'woocommerce_subscriptions_engine_renewal_order_created', [ new self(), 'apply_bonus' ], 10, 2 );
	}

	/**
	 * Add the cycle's BOGO bonus units to the renewal order's product lines.
	 *
	 * @param mixed $order    Renewal order.
	 * @param mixed $contract Contract being renewed.
	 */
	public function apply_bonus( $order, $contract ): void {
		if ( ! $order instanceof WC_Order || ! $contract instanceof Contract || Package::EXTENSION_SLUG !== $contract->get_extension_slug() ) {
			return;
		}

		$terms = self::resolve_terms( $contract );
		if ( null === $terms || ! $terms->has_type( PricingTerms::TYPE_BOGO ) ) {
			return;
		}

		$cycle = self::cycle_count( (int) $contract->get_id(), $order->get_id() );
		if ( null === $cycle ) {
			return;
		}

		$calculator = new PriceCalculator( $terms );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$paid  = $item->get_quantity();
			$bonus = $calculator->bonus_quantity( (float) $paid, $cycle );
			if ( $bonus > 0 ) {
				$item->set_quantity( $paid + $bonus );
				$item->save();
			}
		}
	}

	/**
	 * Terms from the contract's plan snapshot, else from its live plan.
	 *
	 * @param Contract $contract Contract.
	 */
	private static function resolve_terms( Contract $contract ): ?PricingTerms {
		$snapshot = $contract->get_plan_snapshot();
		if ( null === $snapshot && null !== $contract->get_id() ) {
			$stored   = Subscriptions::get( (int) $contract->get_id() );
			$snapshot = null === $stored ? null : $stored->get_plan_snapshot();
		}

		$terms = null === $snapshot ? null : PricingTerms::from_snapshot( $snapshot );
		if ( null !== $terms ) {
			return $terms;
		}

		$plans = ( new SellingPlans( [ Package::EXTENSION_SLUG ] ) )->get_plans( [ $contract->get_selling_plan_id() ] );

		return isset( $plans[0] ) ? PricingTerms::from_plan( $plans[0] ) : null;
	}

	/**
	 * The billing cycle count of the cycle linked to the order, or null.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $order_id    Renewal order id.
	 */
	private static function cycle_count( int $contract_id, int $order_id ): ?int {
		foreach ( Subscriptions::get_history( $contract_id ) as $cycle ) {
			if ( $cycle->get_order_id() === $order_id ) {
				return $cycle->get_count();
			}
		}

		return null;
	}
}
