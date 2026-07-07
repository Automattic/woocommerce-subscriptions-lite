<?php
/**
 * Integration tests for the product plan resolver.
 *
 * Resolution runs END TO END: real products and variations, applicability in
 * real postmeta through the Lite store, plans read back through the engine's
 * catalog facade, and the Lite product-plans filter dispatched through the
 * real hook table.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver
 */
final class ProductPlanResolverTest extends LiteIntegrationTestCase {

	public function tear_down(): void {
		remove_all_filters( ProductPlanResolver::PRODUCT_PLANS_FILTER );

		parent::tear_down();
	}

	/**
	 * Create a saved simple product.
	 */
	private function make_product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( '24.00' );

		return (int) $product->save();
	}

	/**
	 * Map resolved plans to their ids.
	 *
	 * @param array<int, Plan> $plans Resolved plans.
	 * @return array<int, int|null>
	 */
	private static function plan_ids( array $plans ): array {
		return array_map(
			static function ( Plan $plan ): ?int {
				return $plan->get_id();
			},
			$plans
		);
	}

	public function test_unknown_product_resolves_to_no_plans(): void {
		$this->assertSame( [], ( new ProductPlanResolver() )->for_product( 999999 ) );
	}

	public function test_disable_mode_resolves_to_no_plans(): void {
		$product_id = $this->make_product();
		$this->make_plan();

		// Fresh products default to disable; write it explicitly for the round trip.
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE ) );

		$this->assertSame( [], ( new ProductPlanResolver() )->for_product( $product_id ) );
	}

	public function test_inherit_all_resolves_every_active_lite_plan(): void {
		$product_id = $this->make_product();

		$first_id  = (int) $this->make_plan( 'month', 1, null, [ 'sort_order' => 1 ] )->get_id();
		$second_id = (int) $this->make_plan( 'week', 1, null, [ 'sort_order' => 2 ] )->get_id();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id );

		$this->assertSame( [ $first_id, $second_id ], self::plan_ids( $plans ) );
	}

	public function test_inherit_select_resolves_only_attached_active_plans(): void {
		$product_id = $this->make_product();

		$attached_id = (int) $this->make_plan()->get_id();
		$this->make_plan( 'week' ); // Unattached.
		$archived = $this->make_plan( 'year' );

		// Attach both while active, then archive one - archived attachments
		// stay in meta but drop out of resolution.
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id, (int) $archived->get_id() ] )
		);
		$archived->set_status( Plan::STATUS_ARCHIVED );
		( new PlanRepository() )->update( $archived );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id );

		$this->assertSame( [ $attached_id ], self::plan_ids( $plans ) );
	}

	public function test_empty_selection_short_circuits_without_running_the_filter(): void {
		$product_id = $this->make_product();

		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [] )
		);

		$calls = 0;
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( &$calls ): array {
				++$calls;

				return $plans;
			}
		);

		$this->assertSame( [], ( new ProductPlanResolver() )->for_product( $product_id ) );
		$this->assertSame( 0, $calls );
	}

	public function test_disable_mode_runs_the_filter_over_the_empty_set(): void {
		$product_id = $this->make_product();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE ) );

		$calls = 0;
		$seen  = null;
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( &$calls, &$seen ): array {
				++$calls;
				$seen = $plans;

				return $plans;
			}
		);

		$this->assertSame( [], ( new ProductPlanResolver() )->for_product( $product_id ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( [], $seen );
	}

	public function test_archived_and_foreign_slug_plans_are_excluded(): void {
		$product_id = $this->make_product();

		$active_id = (int) $this->make_plan()->get_id();
		$this->make_plan( 'week', 1, null, [ 'status' => Plan::STATUS_ARCHIVED ] );
		$this->make_plan( 'year', 1, null, [ 'extension_slug' => 'other-extension' ] );

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id );

		$this->assertSame( [ $active_id ], self::plan_ids( $plans ) );
	}

	public function test_variation_id_resolves_to_the_parent_applicability(): void {
		$plan_id = (int) $this->make_plan()->get_id();

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Coffee subscription box' );
		$parent_id = (int) $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '30.00' );
		$variation_id = (int) $variation->save();

		( new ApplicabilityStore() )->set( $parent_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $variation_id );

		$this->assertSame( [ $plan_id ], self::plan_ids( $plans ) );
	}

	public function test_plans_come_back_in_sort_order(): void {
		$product_id = $this->make_product();

		$last_id  = (int) $this->make_plan( 'month', 1, null, [ 'sort_order' => 9 ] )->get_id();
		$first_id = (int) $this->make_plan( 'week', 1, null, [ 'sort_order' => 1 ] )->get_id();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$plans = ( new ProductPlanResolver() )->for_product( $product_id );

		$this->assertSame( [ $first_id, $last_id ], self::plan_ids( $plans ) );
	}

	public function test_filter_can_remove_and_append_plans(): void {
		$product_id = $this->make_product();

		$removed_id = (int) $this->make_plan( 'month', 1, null, [ 'name' => 'Removed' ] )->get_id();
		$this->make_plan( 'week', 1, null, [ 'name' => 'Kept' ] );
		$appended = $this->make_plan(
			'year',
			1,
			null,
			[
				'name'   => 'Appended',
				'status' => Plan::STATUS_ARCHIVED,
			]
		);

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( $removed_id, $appended ): array {
				$plans = array_filter(
					$plans,
					static function ( Plan $plan ) use ( $removed_id ): bool {
						return $plan->get_id() !== $removed_id;
					}
				);

				$plans[] = $appended;

				return $plans;
			}
		);

		$plans = ( new ProductPlanResolver() )->for_product( $product_id );
		$names = array_map(
			static function ( Plan $plan ): string {
				return $plan->get_name();
			},
			$plans
		);

		$this->assertSame( [ 'Kept', 'Appended' ], $names );
	}

	public function test_filter_receives_the_original_product_id(): void {
		// A variation: applicability is read from the parent, but the filter must
		// see the variation id the caller asked about - not the parent id.
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Coffee Box' );
		$parent->save();
		( new ApplicabilityStore() )->set( $parent->get_id(), new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->make_plan();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '24.00' );
		$variation_id = (int) $variation->save();

		$seen = null;
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans, int $filtered_product_id ) use ( &$seen ): array {
				$seen = $filtered_product_id;

				return $plans;
			},
			10,
			2
		);

		( new ProductPlanResolver() )->for_product( $variation_id );

		$this->assertSame( $variation_id, $seen );
		$this->assertNotSame( (int) $parent->get_id(), $seen, 'The filter must see the variation id, not the parent it resolved applicability from.' );
	}

	public function test_non_plan_filter_garbage_is_dropped(): void {
		$product_id = $this->make_product();
		$plan_id    = (int) $this->make_plan()->get_id();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ): array {
				$plans[] = 'not-a-plan';
				$plans[] = null;
				$plans[] = new \stdClass();

				return $plans;
			}
		);

		$plans = ( new ProductPlanResolver() )->for_product( $product_id );

		$this->assertSame( [ $plan_id ], self::plan_ids( $plans ) );
	}

	public function test_filter_returning_garbage_yields_no_plans(): void {
		$product_id = $this->make_product();
		$this->make_plan();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		add_filter( ProductPlanResolver::PRODUCT_PLANS_FILTER, '__return_false' );

		$this->assertSame( [], ( new ProductPlanResolver() )->for_product( $product_id ) );
	}
}
