<?php
/**
 * Integration tests for RelatedOrders.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Orders;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Orders\RelatedOrders;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Orders\RelatedOrders
 */
final class RelatedOrdersTest extends LiteIntegrationTestCase {

	/**
	 * The order ids of a window.
	 *
	 * @param array{orders: array<int, WC_Order>, has_more: bool} $window A window.
	 * @return array<int, int>
	 */
	private function ids( array $window ): array {
		return array_map(
			static function ( WC_Order $order ): int {
				return $order->get_id();
			},
			$window['orders']
		);
	}

	/**
	 * A checkout contract (cycle 1 = origin order) with `$renewals` renewal orders.
	 *
	 * @param int $renewals Renewal count.
	 * @return array{0: int, 1: int, 2: array<int, int>} Contract id, origin order id, renewal ids oldest first.
	 */
	private function contract_with_renewals( int $renewals ): array {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );
		$renewal_ids = [];
		for ( $i = 0; $i < $renewals; $i++ ) {
			$renewal_ids[] = $this->create_renewal_order( $contract_id, $customer_id )->get_id();
		}

		return [ $contract_id, (int) $this->get_contract( $contract_id )->get_origin_order_id(), $renewal_ids ];
	}

	public function test_lists_renewals_newest_first_then_the_origin(): void {
		[ $contract_id, $origin_id, $renewal_ids ] = $this->contract_with_renewals( 2 );

		$orders = RelatedOrders::for_contract( $this->get_contract( $contract_id ), 10 );

		$this->assertSame( [ $renewal_ids[1], $renewal_ids[0], $origin_id ], $this->ids( $orders ) );
	}

	public function test_an_order_serving_several_cycles_is_listed_once(): void {
		[ $contract_id, $origin_id, $renewal_ids ] = $this->contract_with_renewals( 1 );
		$this->add_cycle_with_lock(
			$contract_id,
			[
				'status'         => CycleStatus::BILLED,
				'count'          => 3,
				'starts_at_gmt'  => '2026-03-15 00:00:00',
				'ends_at_gmt'    => '2026-04-15 00:00:00',
				'expected_total' => '19.99',
				'currency'       => 'USD',
				'order_id'       => $renewal_ids[0],
			]
		);

		$orders = RelatedOrders::for_contract( $this->get_contract( $contract_id ), 10 );

		$this->assertSame( [ $renewal_ids[0], $origin_id ], $this->ids( $orders ) );
	}

	public function test_a_window_pages_over_the_chain(): void {
		[ $contract_id, $origin_id, $renewal_ids ] = $this->contract_with_renewals( 3 );
		$contract                                  = $this->get_contract( $contract_id );

		$first = RelatedOrders::for_contract( $contract, 2, 0 );
		$this->assertSame( [ $renewal_ids[2], $renewal_ids[1] ], $this->ids( $first ) );
		$this->assertTrue( $first['has_more'] );
		$last = RelatedOrders::for_contract( $contract, 2, 2 );
		$this->assertSame( [ $renewal_ids[0], $origin_id ], $this->ids( $last ) );
		$this->assertFalse( $last['has_more'] );
		$this->assertSame( [], $this->ids( RelatedOrders::for_contract( $contract, 2, 4 ) ) );
	}

	public function test_a_window_short_of_orders_still_reports_the_next_window(): void {
		[ $contract_id, $origin_id, $renewal_ids ] = $this->contract_with_renewals( 1 );
		$this->add_cycle_with_lock(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 3,
				'starts_at_gmt'  => '2026-03-15 00:00:00',
				'ends_at_gmt'    => '2026-04-15 00:00:00',
				'expected_total' => '19.99',
				'currency'       => 'USD',
			]
		);
		$contract = $this->get_contract( $contract_id );

		$first = RelatedOrders::for_contract( $contract, 1, 0 );
		$this->assertSame( [], $this->ids( $first ), 'The newest cycle has no order yet.' );
		$this->assertTrue( $first['has_more'], 'Older orders stay reachable.' );
		$this->assertSame( [ $renewal_ids[0], $origin_id ], $this->ids( RelatedOrders::for_contract( $contract, 2, 1 ) ) );
	}

	public function test_a_non_positive_limit_reads_none(): void {
		[ $contract_id ] = $this->contract_with_renewals( 3 );
		$contract        = $this->get_contract( $contract_id );

		$this->assertSame(
			[
				'orders'   => [],
				'has_more' => false,
			],
			RelatedOrders::for_contract( $contract, 0 )
		);
		$this->assertSame( [], $this->ids( RelatedOrders::for_contract( $contract, -1 ) ) );
	}

	public function test_the_origin_closes_the_list_when_cycle_one_carries_no_order(): void {
		$contract_id = $this->seed_contract();
		$origin      = $this->create_subscription_order( $this->create_customer() );
		Contracts::update( $contract_id, [ 'origin_order_id' => $origin->get_id() ] );
		$renewal = $this->create_renewal_order( $contract_id, 1 );

		$contract = $this->get_contract( $contract_id );

		$this->assertSame( [ $renewal->get_id(), $origin->get_id() ], $this->ids( RelatedOrders::for_contract( $contract, 10 ) ) );
		$this->assertSame( [ $renewal->get_id(), $origin->get_id() ], $this->ids( RelatedOrders::for_contract( $contract, 1, 0 ) ), 'A window ending at the chain end takes the origin.' );
		$this->assertSame( [], $this->ids( RelatedOrders::for_contract( $contract, 1, 1 ) ), 'An empty window past the end does not repeat it.' );
	}

	public function test_a_contract_without_cycles_lists_its_origin(): void {
		$contract_id = $this->seed_contract();
		$origin      = $this->create_subscription_order( $this->create_customer() );
		Contracts::update( $contract_id, [ 'origin_order_id' => $origin->get_id() ] );

		$this->assertSame( [ $origin->get_id() ], $this->ids( RelatedOrders::for_contract( $this->get_contract( $contract_id ), 10 ) ) );
	}
}
