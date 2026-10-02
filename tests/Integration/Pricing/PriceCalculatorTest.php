<?php
/**
 * Integration tests for Lite's price calculator: the policy chain, cycle gates
 * and BOGO bonus quantities.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PricingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator
 */
final class PriceCalculatorTest extends LiteIntegrationTestCase {

	/**
	 * A calculator over the given policy entries.
	 *
	 * @param array<int, mixed> $policies Raw policy entries.
	 */
	private static function calculator( array $policies ): PriceCalculator {
		return new PriceCalculator( PricingTerms::from_array( [ 'policies' => $policies ] ) );
	}

	public function test_empty_policy_returns_base_price(): void {
		$calculator = new PriceCalculator( PricingTerms::from_array( [] ) );

		$this->assertSame( 25.0, $calculator->unit_price( 25.0 ) );
		$this->assertSame( [], $calculator->get_terms()->get_policies() );
		$this->assertSame( [], $calculator->get_terms()->get_one_time_fees() );
	}

	public function test_percentage_discount_applies(): void {
		$calculator = self::calculator(
			[
				[
					'type'  => 'percentage',
					'value' => 10,
				],
			]
		);

		$this->assertSame( 90.0, $calculator->unit_price( 100.0 ) );
	}

	public function test_line_total_uses_effective_unit_price_for_quantity(): void {
		$calculator = self::calculator(
			[
				[
					'type'  => 'percentage',
					'value' => 10,
				],
			]
		);

		$this->assertSame( 270.0, $calculator->line_total( 100.0, 3.0 ) );
	}

	public function test_fixed_amount_is_clamped_at_zero(): void {
		$calculator = self::calculator(
			[
				[
					'type'  => 'fixed_amount',
					'value' => 30,
				],
			]
		);

		$this->assertSame( 0.0, $calculator->unit_price( 20.0 ) );
	}

	public function test_price_replaces_base_and_starting_cycle_gates(): void {
		$calculator = self::calculator(
			[
				[
					'type'           => 'price',
					'value'          => 5,
					'starting_cycle' => 2,
				],
			]
		);

		// Cycle 1 is before the rule's starting cycle, so the base price stands.
		$this->assertSame( 50.0, $calculator->unit_price( 50.0, 1 ) );
		$this->assertSame( 5.0, $calculator->unit_price( 50.0, 2 ) );
	}

	public function test_duration_cycles_limits_policy_window(): void {
		$calculator = self::calculator(
			[
				[
					'type'            => 'percentage',
					'value'           => 50,
					'starting_cycle'  => 2,
					'duration_cycles' => 2,
				],
			]
		);

		$this->assertSame( 100.0, $calculator->unit_price( 100.0, 1 ) );
		$this->assertSame( 50.0, $calculator->unit_price( 100.0, 2 ) );
		$this->assertSame( 50.0, $calculator->unit_price( 100.0, 3 ) );
		$this->assertSame( 100.0, $calculator->unit_price( 100.0, 4 ) );
	}

	public function test_chained_entries_apply_in_order(): void {
		$calculator = self::calculator(
			[
				[
					'type'  => 'percentage',
					'value' => 10,
				],
				[
					'type'  => 'fixed_amount',
					'value' => 5,
				],
			]
		);

		// 100 * 0.9 = 90, then 90 - 5.
		$this->assertSame( 85.0, $calculator->unit_price( 100.0 ) );

		$reversed = self::calculator(
			[
				[
					'type'  => 'fixed_amount',
					'value' => 5,
				],
				[
					'type'  => 'percentage',
					'value' => 10,
				],
			]
		);

		// 100 - 5 = 95, then 95 * 0.9.
		$this->assertSame( 85.5, $reversed->unit_price( 100.0 ) );
	}

	public function test_bogo_entry_hydrates_value_less_and_leaves_prices_unchanged(): void {
		$calculator = self::calculator( [ [ 'type' => 'bogo' ] ] );

		$this->assertSame(
			[
				[
					'type'  => 'bogo',
					'value' => 0.0,
				],
			],
			$calculator->get_terms()->to_array()['policies']
		);

		// Money-neutral: neither the unit price nor the line total moves.
		$this->assertSame( 100.0, $calculator->unit_price( 100.0 ) );
		$this->assertSame( 300.0, $calculator->line_total( 100.0, 3.0 ) );
	}

	public function test_bogo_bonus_quantity_applies_to_all_cycles_without_scope_gates(): void {
		$calculator = self::calculator( [ [ 'type' => 'bogo' ] ] );

		// One free unit per paid unit, on every cycle.
		$this->assertSame( 2.0, $calculator->bonus_quantity( 2.0, 1 ) );
		$this->assertSame( 2.0, $calculator->bonus_quantity( 2.0, 5 ) );
		$this->assertSame( 1.0, $calculator->bonus_quantity( 1.0, 3 ) );

		// A non-positive paid quantity earns nothing.
		$this->assertSame( 0.0, $calculator->bonus_quantity( 0.0, 1 ) );
	}

	/**
	 * @dataProvider provide_bogo_scope_windows
	 *
	 * @param array<string, int> $gates    Scope gate keys for the bogo entry.
	 * @param int                $cycle    Cycle under test.
	 * @param float              $expected Expected bonus for paid quantity 2.
	 */
	public function test_bogo_bonus_quantity_respects_the_cycle_scope_gates( array $gates, int $cycle, float $expected ): void {
		$calculator = self::calculator( [ array_merge( [ 'type' => 'bogo' ], $gates ) ] );

		$this->assertSame( $expected, $calculator->bonus_quantity( 2.0, $cycle ) );
	}

	/**
	 * @return array<string, array{0: array<string, int>, 1: int, 2: float}>
	 */
	public function provide_bogo_scope_windows(): array {
		return [
			'first cycle only, cycle 1'    => [ [ 'duration_cycles' => 1 ], 1, 2.0 ],
			'first cycle only, cycle 2'    => [ [ 'duration_cycles' => 1 ], 2, 0.0 ],
			'three cycles, cycle 3'        => [ [ 'duration_cycles' => 3 ], 3, 2.0 ],
			'three cycles, cycle 4'        => [ [ 'duration_cycles' => 3 ], 4, 0.0 ],
			'starting cycle 2, cycle 1'    => [ [ 'starting_cycle' => 2 ], 1, 0.0 ],
			'starting cycle 2, cycle 2'    => [ [ 'starting_cycle' => 2 ], 2, 2.0 ],
			'window 2..3, cycle 3'         => [
				[
					'starting_cycle'  => 2,
					'duration_cycles' => 2,
				],
				3,
				2.0,
			],
			'window 2..3, cycle 4 (ended)' => [
				[
					'starting_cycle'  => 2,
					'duration_cycles' => 2,
				],
				4,
				0.0,
			],
		];
	}

	public function test_bogo_bonus_sums_per_in_scope_entry(): void {
		$calculator = self::calculator(
			[
				[ 'type' => 'bogo' ],
				[
					'type'            => 'bogo',
					'duration_cycles' => 2,
				],
			]
		);

		// Both entries in scope: one bonus unit per paid unit from each.
		$this->assertSame( 4.0, $calculator->bonus_quantity( 2.0, 1 ) );
		$this->assertSame( 4.0, $calculator->bonus_quantity( 2.0, 2 ) );
		// The windowed entry has ended; only the ungated one grants.
		$this->assertSame( 2.0, $calculator->bonus_quantity( 2.0, 3 ) );
	}

	public function test_line_total_applies_the_cycle_gates_of_the_given_cycle(): void {
		$calculator = self::calculator(
			[
				[
					'type'           => 'percentage',
					'value'          => 10,
					'starting_cycle' => 3,
				],
			]
		);

		$this->assertSame( 200.0, $calculator->line_total( 100.0, 2.0, 2 ) );
		$this->assertSame( 180.0, $calculator->line_total( 100.0, 2.0, 3 ) );
	}

	public function test_bonus_quantity_is_zero_without_a_bogo_entry(): void {
		$calculator = self::calculator(
			[
				[
					'type'  => 'percentage',
					'value' => 10,
				],
			]
		);

		$this->assertSame( 0.0, $calculator->bonus_quantity( 5.0, 1 ) );
		$this->assertSame( 0.0, ( new PriceCalculator( PricingTerms::from_array( [] ) ) )->bonus_quantity( 5.0, 1 ) );
	}

	public function test_bogo_composes_with_a_percentage_discount(): void {
		$calculator = self::calculator(
			[
				[
					'type'  => 'percentage',
					'value' => 10,
				],
				[ 'type' => 'bogo' ],
			]
		);

		// The percentage entry discounts the price AND the bogo entry grants the bonus.
		$this->assertSame( 90.0, $calculator->unit_price( 100.0 ) );
		$this->assertSame( 180.0, $calculator->line_total( 100.0, 2.0 ) );
		$this->assertSame( 2.0, $calculator->bonus_quantity( 2.0, 1 ) );
	}

	public function test_for_plan_without_payload_returns_base_price(): void {
		$this->assertSame( 42.0, PriceCalculator::for_plan( $this->make_plan() )->unit_price( 42.0 ) );
	}

	public function test_for_plan_skips_an_unknown_stored_entry_type(): void {
		$plan = $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => [
					'policies' => [
						[
							'type'  => 'tiered',
							'value' => 50,
						],
						[
							'type'  => 'percentage',
							'value' => 20,
						],
					],
				],
			]
		);

		$this->assertSame( 80.0, PriceCalculator::for_plan( $plan )->unit_price( 100.0 ) );
	}

	/**
	 * @dataProvider provide_out_of_range_stored_entries
	 *
	 * @param array<string, mixed> $entry Stored entry a write would reject.
	 */
	public function test_an_out_of_range_stored_entry_leaves_the_price_unchanged( array $entry ): void {
		$plan = $this->make_plan( 'month', 1, null, [ 'pricing_policy' => [ 'policies' => [ $entry ] ] ] );

		$calculator = PriceCalculator::for_plan( $plan );

		$this->assertSame( 100.0, $calculator->unit_price( 100.0 ) );
		$this->assertSame( 200.0, $calculator->line_total( 100.0, 2.0 ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function provide_out_of_range_stored_entries(): array {
		return [
			'percentage over 100'   => [
				[
					'type'  => 'percentage',
					'value' => 150,
				],
			],
			'negative percentage'   => [
				[
					'type'  => 'percentage',
					'value' => -10,
				],
			],
			'negative fixed amount' => [
				[
					'type'  => 'fixed_amount',
					'value' => -5,
				],
			],
			'negative price'        => [
				[
					'type'  => 'price',
					'value' => -1,
				],
			],
		];
	}
}
