<?php
/**
 * RelatedOrders - the orders of a Lite contract: the orders its cycles billed, plus its
 * origin order.
 *
 * Read off the engine's cycle chain, newest cycle first, so paging follows the chain: a
 * window of cycles yields its distinct orders (an order serving several cycles, or a
 * retried cycle's order, is listed once per window) and whether another window follows.
 * The origin order closes the list when the chain does not already carry it.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Orders
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Orders;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Related orders of a contract.
 */
final class RelatedOrders {

	/**
	 * A window of the contract's orders, newest cycle first, then the origin order.
	 *
	 * Pages count cycles, not orders: a window can hold fewer orders than `$limit` (a cycle
	 * without an order, a deleted order, an order serving several cycles), so whether
	 * another window follows is returned as a fact rather than inferred from a count.
	 *
	 * @param ContractView $contract The contract.
	 * @param int          $limit    Cycles to read (positive; anything else reads none).
	 * @param int          $offset   Cycles to skip (for paging).
	 * @return array{orders: array<int, WC_Order>, has_more: bool}
	 */
	public static function for_contract( ContractView $contract, int $limit, int $offset = 0 ): array {
		if ( $limit <= 0 ) {
			return [
				'orders'   => [],
				'has_more' => false,
			];
		}

		$first_cycle = max( 0, $offset );

		// One cycle past the window tells whether the window reaches the chain's end.
		$cycles      = Contracts::get_cycles( $contract->get_id(), $limit + 1, $first_cycle );
		$reached_end = count( $cycles ) <= $limit;
		$cycles      = array_slice( $cycles, 0, $limit );
		$order_ids   = self::get_order_ids( $cycles );

		// The origin closes the chain: it joins the window that reaches the chain's end,
		// never an empty window past it (unless the chain itself is empty).
		$origin_order_id = $contract->get_origin_order_id();
		if ( $reached_end && ( [] !== $cycles || 0 === $first_cycle ) && null !== $origin_order_id ) {
			$order_ids[ $origin_order_id ] = $origin_order_id;
		}

		$orders = [];
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}

		return [
			'orders'   => $orders,
			'has_more' => ! $reached_end,
		];
	}

	/**
	 * The cycles' order ids, keyed by id, in cycle order.
	 *
	 * @param array<int, CycleView> $cycles Cycles, newest first.
	 * @return array<int, int>
	 */
	private static function get_order_ids( array $cycles ): array {
		$order_ids = [];
		foreach ( $cycles as $cycle ) {
			$order_id = $cycle->get_order_id();
			if ( null !== $order_id ) {
				$order_ids[ $order_id ] = $order_id;
			}
		}

		return $order_ids;
	}
}
