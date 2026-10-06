<?php
/**
 * Integration tests for the Lite plan display order.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Plans\PlanOrder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Plans\PlanOrder
 */
final class PlanOrderTest extends LiteIntegrationTestCase {

	public function test_without_a_saved_order_plans_sort_by_id(): void {
		[ $first, $second, $third ] = $this->three_plans();

		$this->assertSame(
			[ $first->get_id(), $second->get_id(), $third->get_id() ],
			$this->ids( ( new PlanOrder() )->sort( [ $third, $first, $second ] ) )
		);
	}

	public function test_listed_plans_come_first_in_order_then_the_rest_by_id(): void {
		[ $first, $second, $third ] = $this->three_plans();
		( new PlanOrder() )->set( [ $third->get_id(), $first->get_id() ] );

		$this->assertSame(
			[ $third->get_id(), $first->get_id(), $second->get_id() ],
			$this->ids( ( new PlanOrder() )->sort( [ $first, $second, $third ] ) )
		);
	}

	public function test_stale_ids_are_ignored(): void {
		[ $first, $second ] = $this->three_plans();
		( new PlanOrder() )->set( [ 999999, $second->get_id(), 888888 ] );

		$this->assertSame(
			[ $second->get_id(), $first->get_id() ],
			$this->ids( ( new PlanOrder() )->sort( [ $first, $second ] ) )
		);
	}

	/**
	 * @dataProvider provide_malformed_options
	 *
	 * @param mixed $stored Raw option value.
	 */
	public function test_a_malformed_option_falls_back_to_id_order( $stored ): void {
		[ $first, $second, $third ] = $this->three_plans();
		update_option( PlanOrder::OPTION, $stored );

		$this->assertSame( [], ( new PlanOrder() )->get() );
		$this->assertSame(
			[ $first->get_id(), $second->get_id(), $third->get_id() ],
			$this->ids( ( new PlanOrder() )->sort( [ $second, $third, $first ] ) )
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_malformed_options(): array {
		return [
			'string'          => [ '3,1,2' ],
			'nested'          => [ [ [ 3 ], [ 1 ] ] ],
			'non-positive'    => [ [ 0, -2 ] ],
			'numeric strings' => [ [ '3', '1' ] ],
		];
	}

	public function test_get_drops_duplicates_and_keeps_valid_ids(): void {
		update_option( PlanOrder::OPTION, [ 5, 'x', 5, 2, null, 7 ] );

		$this->assertSame( [ 5, 2, 7 ], ( new PlanOrder() )->get() );
	}

	public function test_set_round_trips(): void {
		( new PlanOrder() )->set( [ 4, 2, 9 ] );

		$this->assertSame( [ 4, 2, 9 ], ( new PlanOrder() )->get() );
	}

	/**
	 * Three Lite plans in id order.
	 *
	 * @return array<int, PlanView>
	 */
	private function three_plans(): array {
		return [ $this->make_plan( 'week' ), $this->make_plan( 'month' ), $this->make_plan( 'year' ) ];
	}

	/**
	 * Ids of the given plans, in order.
	 *
	 * @param array<int, PlanView> $plans Plans.
	 * @return array<int, int>
	 */
	private function ids( array $plans ): array {
		return array_map(
			static function ( PlanView $plan ): int {
				return $plan->get_id();
			},
			$plans
		);
	}
}
