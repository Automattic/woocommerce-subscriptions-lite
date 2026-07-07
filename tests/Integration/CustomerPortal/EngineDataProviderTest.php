<?php
/**
 * Integration tests for EngineDataProvider against the real engine.
 *
 * Contracts are seeded through the production checkout path (order -> engine
 * factory) and read back through the provider over the real facade - no seams,
 * no doubles. Restores the coverage retired with the stub-based suite.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider
 */
final class EngineDataProviderTest extends LiteIntegrationTestCase {

	/**
	 * System under test.
	 *
	 * @var EngineDataProvider
	 */
	private $provider;

	public function set_up(): void {
		parent::set_up();
		$this->provider = new EngineDataProvider();
	}

	public function test_list_rows_carry_the_canonical_row_shape(): void {
		$customer_id = $this->create_customer();
		$this->create_contract( $customer_id );

		$rows = $this->provider->get_contracts_for_customer( $customer_id );

		$this->assertCount( 1, $rows );
		$this->assertSame(
			[ 'id', 'status', 'billing_total', 'currency', 'billing_period', 'billing_interval', 'next_payment_gmt', 'payment_method' ],
			array_keys( $rows[0] )
		);
		$this->assertSame( 'active', $rows[0]['status'] );
		$this->assertSame( 'USD', $rows[0]['currency'] );
	}

	public function test_detail_extends_the_row_with_dates_totals_items_and_addresses(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );

		$detail = $this->provider->get_contract( $contract_id, $customer_id );

		$this->assertIsArray( $detail );
		foreach ( [ 'start_gmt', 'end_gmt', 'last_payment_gmt', 'last_updated_gmt', 'discount_total', 'shipping_total', 'tax_total', 'items', 'addresses' ] as $key ) {
			$this->assertArrayHasKey( $key, $detail );
		}
		$this->assertSame( $contract_id, $detail['id'] );
	}

	public function test_items_are_normalized_to_the_canonical_shape(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'product_name' => 'Weekly Veg Box',
				'price'        => '19.99',
				'quantity'     => 2,
			]
		);

		$detail = $this->provider->get_contract( $contract_id, $customer_id );

		$this->assertCount( 1, $detail['items'] );
		$item = $detail['items'][0];
		$this->assertSame( [ 'name', 'quantity', 'subtotal', 'total' ], array_keys( $item ) );
		$this->assertSame( 'Weekly Veg Box', $item['name'] );
		$this->assertSame( 2.0, $item['quantity'] );
		// Decimal columns read back with full storage precision; the ViewModel's
		// money formatter owns display rounding.
		$this->assertSame( 39.98, (float) $item['subtotal'] );
	}

	public function test_addresses_are_reduced_to_wc_field_arrays(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );

		$addresses = $this->provider->get_contract( $contract_id, $customer_id )['addresses'];

		$this->assertArrayHasKey( 'billing', $addresses );
		$this->assertArrayHasKey( 'shipping', $addresses );
		$this->assertSame( 'Ada', $addresses['billing']['first_name'] );
		$this->assertSame( 'ada@example.com', $addresses['billing']['email'] );
		$this->assertSame( '1 Engine Court', $addresses['shipping']['address_1'] );
		$this->assertArrayNotHasKey( 'email', $addresses['shipping'], 'Shipping carries no email.' );
		$this->assertArrayNotHasKey( 'company', $addresses['billing'], 'Empty fields are dropped.' );
	}

	public function test_related_orders_carry_the_canonical_order_shape_and_window(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );
		$this->create_renewal_order( $contract_id, $customer_id, [ 'date_created' => '2026-03-01 00:00:00' ] );
		$this->create_renewal_order( $contract_id, $customer_id, [ 'date_created' => '2026-04-01 00:00:00' ] );
		$this->create_renewal_order( $contract_id, $customer_id, [ 'date_created' => '2026-05-01 00:00:00' ] );

		// The origin order is linked by the checkout factory, so it counts as a
		// related order alongside the three renewals - exactly as in production.
		$all = $this->provider->get_related_orders( $contract_id );
		$this->assertCount( 4, $all );
		$this->assertSame(
			[ 'number', 'date_gmt', 'status', 'status_label', 'total', 'view_url' ],
			array_keys( $all[0] )
		);

		$window = $this->provider->get_related_orders( $contract_id, 2, 1 );
		$this->assertCount( 2, $window, 'limit/offset window through to the read.' );
	}

	public function test_cadence_is_sourced_from_the_plan_snapshot(): void {
		$customer_id = $this->create_customer();
		$this->create_contract(
			$customer_id,
			[
				'period'   => 'year',
				'interval' => 1,
			]
		);

		$rows = $this->provider->get_contracts_for_customer( $customer_id );

		$this->assertSame( 'year', $rows[0]['billing_period'] );
		$this->assertSame( 1, $rows[0]['billing_interval'] );
	}

	public function test_cadence_degrades_to_empty_when_the_snapshot_is_absent(): void {
		global $wpdb;

		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );

		// Simulate a contract with no frozen plan snapshot (pre-snapshot data).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS ),
			[ 'plan_snapshot_id' => null ],
			[ 'id' => $contract_id ]
		);

		$detail = $this->provider->get_contract( $contract_id, $customer_id );

		$this->assertSame( '', $detail['billing_period'] );
		$this->assertSame( 0, $detail['billing_interval'] );
	}

	public function test_get_contract_is_ownership_asymmetric(): void {
		$customer_id = $this->create_customer();
		$stranger_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );

		$this->assertNull( $this->provider->get_contract( $contract_id, $stranger_id ), 'Foreign-owned reads null.' );
		$this->assertNull( $this->provider->get_contract( 999999, $customer_id ), 'Unknown id reads null.' );
		$this->assertIsArray( $this->provider->get_contract( $contract_id, $customer_id ) );
	}

	public function test_list_windows_with_limit_and_offset(): void {
		$customer_id = $this->create_customer();
		$this->create_contract( $customer_id );
		$this->create_contract( $customer_id );
		$this->create_contract( $customer_id );

		$this->assertCount( 3, $this->provider->get_contracts_for_customer( $customer_id ) );
		$this->assertCount( 2, $this->provider->get_contracts_for_customer( $customer_id, 2, 0 ) );
		$this->assertCount( 1, $this->provider->get_contracts_for_customer( $customer_id, 2, 2 ) );
	}
}
