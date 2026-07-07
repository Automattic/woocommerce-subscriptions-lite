<?php
/**
 * Integration tests for the applicability store.
 *
 * Meta I/O runs against the real postmeta table: round trips per mode, the
 * plan-row reconciliation (stale rows removed, externally duplicated rows
 * collapsed), and the all-or-nothing write validation - a rejected write must
 * leave zero meta behind.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use InvalidArgumentException;
use WC_Product_External;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore
 */
final class ApplicabilityStoreTest extends LiteIntegrationTestCase {

	/**
	 * Create a saved simple product.
	 */
	private function simple_product_id(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( '24.00' );

		return (int) $product->save();
	}

	/**
	 * Persist a Lite-owned plan and return its id.
	 */
	private function plan_id(): int {
		return (int) $this->make_plan()->get_id();
	}

	/**
	 * Read the raw plan-id meta rows as ints.
	 *
	 * @param int $product_id Product id.
	 * @return array<int, int>
	 */
	private function plan_id_rows( int $product_id ): array {
		$rows = get_post_meta( $product_id, ApplicabilityStore::META_PLAN_IDS, false );

		$ids = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$ids[] = is_scalar( $row ) ? (int) $row : 0;
		}

		return $ids;
	}

	public function test_fresh_product_returns_defaults(): void {
		$store         = new ApplicabilityStore();
		$applicability = $store->get( $this->simple_product_id() );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $applicability->get_mode() );
		$this->assertSame( [], $applicability->get_plan_ids() );
		$this->assertTrue( $applicability->allows_one_time() );
	}

	public function test_disable_round_trips(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_DISABLE, [], false ) );
		$fetched = $store->get( $product_id );

		$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
		$this->assertSame( [], $fetched->get_plan_ids() );
		$this->assertFalse( $fetched->allows_one_time() );
	}

	public function test_inherit_all_round_trips(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
		$fetched = $store->get( $product_id );

		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, $fetched->get_mode() );
		$this->assertSame( [], $fetched->get_plan_ids() );
		$this->assertTrue( $fetched->allows_one_time() );
	}

	public function test_inherit_select_round_trips(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();
		$first_id   = $this->plan_id();
		$second_id  = $this->plan_id();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $first_id, $second_id ], false ) );
		$fetched = $store->get( $product_id );

		$this->assertSame( ProductApplicability::MODE_INHERIT_SELECT, $fetched->get_mode() );
		$this->assertSame( [ $first_id, $second_id ], $fetched->get_plan_ids() );
		$this->assertFalse( $fetched->allows_one_time() );
	}

	public function test_switching_select_to_all_clears_plan_rows(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $this->plan_id(), $this->plan_id() ] ) );
		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->assertSame( [], $this->plan_id_rows( $product_id ) );
		$this->assertSame( ProductApplicability::MODE_INHERIT_ALL, $store->get( $product_id )->get_mode() );
	}

	public function test_plan_ids_are_stored_one_row_each(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();
		$plan_ids   = [ $this->plan_id(), $this->plan_id(), $this->plan_id() ];

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, $plan_ids ) );

		$this->assertSame( $plan_ids, $this->plan_id_rows( $product_id ) );
	}

	public function test_reconcile_removes_stale_rows_and_adds_missing_ones(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();
		$stale_id   = $this->plan_id();
		$kept_id    = $this->plan_id();
		$added_id   = $this->plan_id();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $stale_id, $kept_id ] ) );
		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $kept_id, $added_id ] ) );

		$this->assertEqualsCanonicalizing( [ $kept_id, $added_id ], $this->plan_id_rows( $product_id ) );
		$this->assertEqualsCanonicalizing( [ $kept_id, $added_id ], $store->get( $product_id )->get_plan_ids() );
	}

	public function test_reconcile_collapses_externally_duplicated_rows(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();
		$plan_id    = $this->plan_id();

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $plan_id ] ) );
		add_post_meta( $product_id, ApplicabilityStore::META_PLAN_IDS, $plan_id );

		$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $plan_id ] ) );

		$this->assertSame( [ $plan_id ], $this->plan_id_rows( $product_id ) );
	}

	public function test_set_rejects_unknown_product(): void {
		$this->expectException( InvalidArgumentException::class );

		( new ApplicabilityStore() )->set( 999999, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
	}

	public function test_set_rejects_a_variation_id(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Coffee box' );
		$parent_id = (int) $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '30.00' );
		$variation_id = (int) $variation->save();

		$store = new ApplicabilityStore();

		try {
			$store->set( $variation_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
			$this->fail( 'Expected InvalidArgumentException for a variation id.' );
		} catch ( InvalidArgumentException $e ) {
			// Nothing was written - the parent still reads as default.
			$this->assertSame( ProductApplicability::MODE_DISABLE, $store->get( $parent_id )->get_mode() );
		}
	}

	public function test_set_rejects_product_types_that_cannot_carry_applicability(): void {
		$product = new WC_Product_External();
		$product->set_name( 'Affiliate gadget' );
		$product_id = (int) $product->save();

		$store = new ApplicabilityStore();

		try {
			$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
			$this->fail( 'Expected InvalidArgumentException for an external product.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( [], get_post_meta( $product_id, ApplicabilityStore::META_APPLY_MODE, false ) );
		}
	}

	public function test_set_rejects_nonexistent_plan_id_and_writes_nothing(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();

		try {
			$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ 999999 ] ) );
			$this->fail( 'Expected InvalidArgumentException for a nonexistent plan id.' );
		} catch ( InvalidArgumentException $e ) {
			$fetched = $store->get( $product_id );
			$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
			$this->assertSame( [], $fetched->get_plan_ids() );
		}
	}

	public function test_set_rejects_plan_owned_by_another_slug_and_writes_nothing(): void {
		$store      = new ApplicabilityStore();
		$product_id = $this->simple_product_id();
		$foreign_id = (int) $this->make_plan( 'month', 1, null, [ 'extension_slug' => 'another-extension' ] )->get_id();

		try {
			$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $foreign_id ] ) );
			$this->fail( 'Expected InvalidArgumentException for a foreign-slug plan id.' );
		} catch ( InvalidArgumentException $e ) {
			$fetched = $store->get( $product_id );
			$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
			$this->assertSame( [], $fetched->get_plan_ids() );
		}
	}

	public function test_set_rejects_mixed_valid_and_invalid_plan_selection_and_writes_nothing(): void {
		$store       = new ApplicabilityStore();
		$product_id  = $this->simple_product_id();
		$own_plan_id = $this->plan_id();
		$foreign_id  = (int) $this->make_plan( 'month', 1, null, [ 'extension_slug' => 'another-extension' ] )->get_id();

		try {
			$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $own_plan_id, $foreign_id, 999999 ] ) );
			$this->fail( 'Expected InvalidArgumentException for a selection mixing valid, foreign, and unknown plan ids.' );
		} catch ( InvalidArgumentException $e ) {
			// All-or-nothing: the valid id was not written either.
			$fetched = $store->get( $product_id );
			$this->assertSame( ProductApplicability::MODE_DISABLE, $fetched->get_mode() );
			$this->assertSame( [], $fetched->get_plan_ids() );
			$this->assertSame( [], get_post_meta( $product_id, ApplicabilityStore::META_APPLY_MODE, false ) );
			$this->assertSame( [], get_post_meta( $product_id, ApplicabilityStore::META_PLAN_IDS, false ) );
		}
	}

	public function test_set_rejects_an_archived_plan_id(): void {
		$store       = new ApplicabilityStore();
		$product_id  = $this->simple_product_id();
		$archived_id = (int) $this->make_plan( 'month', 1, null, [ 'status' => Plan::STATUS_ARCHIVED ] )->get_id();

		try {
			$store->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $archived_id ] ) );
			$this->fail( 'Expected InvalidArgumentException for an archived plan id.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( ProductApplicability::MODE_DISABLE, $store->get( $product_id )->get_mode() );
		}
	}
}
