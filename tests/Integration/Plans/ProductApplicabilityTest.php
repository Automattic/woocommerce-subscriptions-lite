<?php
/**
 * Tests for the ProductApplicability value object.
 *
 * The VO's contract is two-faced: the constructor is strict (unknown modes
 * and non-positive plan ids throw) while from_storage() is lenient (invalid
 * meta degrades to the defaults) - raw database rows must never fatal a
 * storefront.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use InvalidArgumentException;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability
 */
final class ProductApplicabilityTest extends LiteIntegrationTestCase {

	public function test_defaults_are_disable_with_one_time_allowed(): void {
		$applicability = new ProductApplicability( ProductApplicability::DEFAULT_MODE );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_unknown_mode_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProductApplicability( 'inherit_everything' );
	}

	/**
	 * @dataProvider provide_invalid_plan_ids
	 *
	 * @param mixed $plan_id Invalid plan id.
	 */
	public function test_non_positive_plan_ids_are_rejected( $plan_id ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $plan_id ] );
	}

	/**
	 * Plan id values the constructor must reject.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function provide_invalid_plan_ids(): array {
		return [
			'zero'        => [ 0 ],
			'negative'    => [ -3 ],
			'non-numeric' => [ 'abc' ],
			'fractional'  => [ '1.5' ],
			'array'       => [ [ 2 ] ],
		];
	}

	public function test_plan_ids_are_coerced_unique_ints(): void {
		$applicability = new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ '3', 5, 3, '5' ] );

		$this->assertSame( [ 3, 5 ], $applicability->get_plan_ids() );
	}

	/**
	 * @dataProvider provide_non_select_modes
	 *
	 * @param string $mode Mode that carries no attachment rows.
	 */
	public function test_plan_ids_are_dropped_for_non_select_modes( string $mode ): void {
		$applicability = new ProductApplicability( $mode, [ 7, 9 ] );

		$this->assertSame( [], $applicability->get_plan_ids() );
	}

	/**
	 * Modes whose plan ids normalize away (all-mode is virtual).
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_non_select_modes(): array {
		return [
			'disable'     => [ ProductApplicability::MODE_DISABLE ],
			'inherit_all' => [ ProductApplicability::MODE_INHERIT_ALL ],
		];
	}

	public function test_empty_selection_under_inherit_select_is_allowed(): void {
		$applicability = new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [] );

		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids() );
	}

	public function test_from_storage_defaults_for_absent_values(): void {
		$applicability = ProductApplicability::from_storage( [] );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_from_storage_falls_back_to_disable_for_invalid_mode(): void {
		$applicability = ProductApplicability::from_storage(
			[
				'mode'     => 'bogus',
				'plan_ids' => [ '4' ],
			]
		);

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids() );
	}

	public function test_from_storage_coerces_plan_id_strings_and_drops_invalid_entries(): void {
		$applicability = ProductApplicability::from_storage(
			[
				'mode'     => ProductApplicability::MODE_INHERIT_SELECT,
				'plan_ids' => [ '4', '0', 'junk', 6, '-2', '4' ],
			]
		);

		$this->assertSame( [ 4, 6 ], $applicability->get_plan_ids() );
	}

	public function test_from_storage_maps_yes_no_strings_to_bool(): void {
		$yes = ProductApplicability::from_storage( [ 'allow_one_time' => 'yes' ] );
		$no  = ProductApplicability::from_storage( [ 'allow_one_time' => 'no' ] );

		$this->assertTrue( $yes->allows_one_time() );
		$this->assertFalse( $no->allows_one_time() );
	}

	public function test_to_storage_round_trips_through_from_storage(): void {
		$original = new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ 2, 8 ], false );

		$stored = $original->to_storage();

		$this->assertSame(
			[
				'mode'           => ProductApplicability::MODE_INHERIT_SELECT,
				'plan_ids'       => [ 2, 8 ],
				'allow_one_time' => 'no',
			],
			$stored
		);

		$rehydrated = ProductApplicability::from_storage( $stored );

		$this->assertSame( $original->get_mode(), $rehydrated->get_mode() );
		$this->assertSame( $original->get_plan_ids(), $rehydrated->get_plan_ids() );
		$this->assertSame( $original->allows_one_time(), $rehydrated->allows_one_time() );
	}

	public function test_to_storage_serializes_allow_one_time_as_yes(): void {
		$applicability = new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL );

		$this->assertSame( 'yes', $applicability->to_storage()['allow_one_time'] );
	}
}
