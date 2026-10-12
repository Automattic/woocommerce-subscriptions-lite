<?php
/**
 * RelatedOrders - the orders of a Lite contract: the orders its cycles billed, plus its
 * origin order.
 *
 * Read off the engine's cycle chain, newest cycle first, so paging follows the chain: a
 * window of cycles yields its distinct orders (an order serving several cycles, or a
 * retried cycle's order, is listed once per window). The origin order closes the list
 * when the chain does not already carry it.
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
	 * Cycles read per page when every order is requested.
	 */
	private const ALL_PAGE_SIZE = 100;

	/**
	 * The contract's orders, newest cycle first, then the origin order.
	 *
	 * @param ContractView $contract The contract.
	 * @param int          $limit    Cycles to read; -1 for all, 0 for none.
	 * @param int          $offset   Cycles to skip (for paging).
	 * @return array<int, WC_Order>
	 */
	public static function for_contract( ContractView $contract, int $limit = -1, int $offset = 0 ): array {
		if ( 0 === $limit ) {
			return [];
		}

		$contract_id = $contract->get_id();
		$first_cycle = max( 0, $offset );

		if ( $limit > 0 ) {
			// One cycle past the window tells whether the window reaches the chain's end.
			$cycles      = Contracts::get_cycles( $contract_id, $limit + 1, $first_cycle );
			$reached_end = count( $cycles ) <= $limit;
			$cycles      = array_slice( $cycles, 0, $limit );
		} else {
			$cycles      = self::get_all_cycles( $contract_id, $first_cycle );
			$reached_end = true;
		}

		$order_ids = self::get_order_ids( $cycles );

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

		return $orders;
	}

	/**
	 * Every cycle of the contract from `$first_cycle` on, newest first, read in pages.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $first_cycle Cycles to skip.
	 * @return array<int, CycleView>
	 */
	private static function get_all_cycles( int $contract_id, int $first_cycle ): array {
		$cycles      = [];
		$page_offset = $first_cycle;
		do {
			$page         = Contracts::get_cycles( $contract_id, self::ALL_PAGE_SIZE, $page_offset );
			$page_count   = count( $page );
			$cycles       = array_merge( $cycles, $page );
			$page_offset += self::ALL_PAGE_SIZE;
		} while ( self::ALL_PAGE_SIZE === $page_count );

		return $cycles;
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
