<?php
/**
 * Unit tests for the engine-backed data provider.
 *
 * The engine's public {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions}
 * facade is static and reaches into the database, so it cannot be called without WordPress.
 * The provider reads through one narrow Lite seam ({@see SubscriptionsReader}); these tests
 * inject a double for it that returns engine value objects (a hydrated {@see Contract} and
 * `WC_Order` doubles) and assert that the provider's own mapping:
 *
 *  - returns a structure key-identical to {@see FixtureDataProvider} for an equivalent
 *    contract (so {@see ViewModel} renders identically),
 *  - sources the billing cadence off the contract's plan snapshot, degrading to an empty
 *    period / zero interval when the snapshot is absent, and
 *  - delegates the asymmetric not-found rule to the facade read (unknown id and
 *    foreign-owned both resolve to null).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\CustomerPortal;

use DateTimeImmutable;
use DateTimeZone;
use WC_Order;
use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\PlanSnapshot;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\SubscriptionsReader;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\FixtureDataProvider;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider
 */
final class EngineDataProviderTest extends TestCase {

	/**
	 * Build a hydrated contract for the provider to map.
	 *
	 * Carries a plan snapshot with a monthly billing policy unless `$with_snapshot` is
	 * false, so the cadence-from-snapshot path can be exercised both ways.
	 *
	 * @param int    $id            Contract id.
	 * @param string $status        Contract status.
	 * @param bool   $with_snapshot Whether to attach a plan snapshot.
	 * @return Contract
	 */
	private function contract( int $id = 101, string $status = ContractStatus::ACTIVE, bool $with_snapshot = true ): Contract {
		$contract = Contract::create(
			[
				'customer_id'          => 1,
				'currency'             => 'USD',
				'selling_plan_id'      => 7,
				'payment_method_title' => 'Visa ending in 4242',
				'start_gmt'            => '2026-01-01 00:00:00',
				'next_payment_gmt'     => ContractStatus::ACTIVE === $status ? '2026-07-15 00:00:00' : null,
				'billing_total'        => '19.99',
				'last_payment_gmt'     => '2026-06-15 00:00:00',
				'status'               => $status,
				'discount_total'       => '2.00',
				'shipping_total'       => '3.00',
				'tax_total'            => '1.50',
				'items'                => [
					[
						'item_name'  => 'Monthly coffee box',
						'item_type'  => 'line_item',
						'product_id' => 200,
						'quantity'   => '1.0000',
						'subtotal'   => '19.99',
						'total'      => '17.99',
					],
				],
				'addresses'            => [
					'billing'  => [
						// Storage bookkeeping keys the provider must drop.
						'contract_id'  => 101,
						'address_type' => 'billing',
						'first_name'   => 'Ada',
						'last_name'    => 'Lovelace',
						'address_1'    => '10 Analytical Way',
						'city'         => 'London',
						'postcode'     => 'SW1A 1AA',
						'country'      => 'GB',
						'email'        => 'ada@example.com',
						'phone'        => '+44 20 7946 0000',
					],
					'shipping' => [
						'contract_id'  => 101,
						'address_type' => 'shipping',
						'first_name'   => 'Ada',
						'last_name'    => 'Lovelace',
						'address_1'    => '1 Engine House',
						'city'         => 'Manchester',
						'postcode'     => 'M1 1AE',
						'country'      => 'GB',
					],
				],
			]
		);
		$contract->set_id( $id );

		if ( $with_snapshot ) {
			$contract->set_plan_snapshot(
				PlanSnapshot::from_array(
					[
						'selling_plan_id' => 7,
						'billing_policy'  => [
							'period'   => 'month',
							'interval' => 1,
						],
					]
				)
			);
		}

		return $contract;
	}

	/**
	 * A related-order double carrying the accessors the provider reduces.
	 *
	 * @return WC_Order
	 */
	private function order(): WC_Order {
		return new WC_Order(
			1001,
			[],
			[],
			[
				'order_number'          => '1001',
				'status'                => 'completed',
				'date_created'          => new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) ),
				'formatted_order_total' => 'USD19.99',
				'view_order_url'        => 'https://example.test/order/1001',
			]
		);
	}

	/**
	 * A seam double over a fixed contract owned by customer 1, mirroring the facade's
	 * ownership-checked read, plus a fixed related-order list.
	 *
	 * @param Contract          $contract The contract to serve.
	 * @param array<int, mixed> $orders   Related orders to serve.
	 * @return SubscriptionsReader
	 */
	private function reader( Contract $contract, array $orders = [] ): SubscriptionsReader {
		return new class( $contract, $orders ) implements SubscriptionsReader {

			/** @var Contract */
			private $contract;

			/** @var array<int, mixed> */
			private $orders;

			public function __construct( Contract $contract, array $orders ) {
				$this->contract = $contract;
				$this->orders   = $orders;
			}

			public function list_for_customer( int $customer_id, int $limit = 20, int $offset = 0 ): array {
				$contracts = 1 === $customer_id ? [ $this->contract ] : [];

				return array_slice( $contracts, max( 0, $offset ), $limit > 0 ? $limit : null );
			}

			public function get_for_customer( int $contract_id, int $customer_id ): ?Contract {
				// Mirror the facade: served only when owned by the requesting customer.
				return ( (int) $this->contract->get_id() === $contract_id && 1 === $customer_id ) ? $this->contract : null;
			}

			public function get_related_orders( int $contract_id, int $limit = -1, int $offset = 0 ): array {
				return array_slice( $this->orders, max( 0, $offset ), $limit > 0 ? $limit : null );
			}
		};
	}

	public function test_list_row_shape_matches_the_fixture_row_shape(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		$engine_rows  = $provider->get_contracts_for_customer( 1 );
		$fixture_rows = ( new FixtureDataProvider() )->get_contracts_for_customer( 1 );

		$this->assertNotEmpty( $engine_rows );

		// The view-model reads a fixed set of row fields off each contract; assert the
		// engine row carries every one of them with the same type.
		foreach ( [ 'id', 'status', 'billing_total', 'currency', 'billing_period', 'billing_interval', 'next_payment_gmt', 'payment_method' ] as $key ) {
			$this->assertArrayHasKey( $key, $engine_rows[0], "Engine row carries the {$key} field." );
			$this->assertArrayHasKey( $key, $fixture_rows[0], "Fixture row carries the {$key} field." );
			$this->assertSame(
				gettype( $fixture_rows[0][ $key ] ),
				gettype( $engine_rows[0][ $key ] ),
				"Engine and fixture row agree on the type of {$key}."
			);
		}

		// The payment_method sub-array has the same keys.
		$this->assertSame(
			array_keys( $fixture_rows[0]['payment_method'] ),
			array_keys( $engine_rows[0]['payment_method'] ),
			'Engine and fixture payment_method carry the same keys.'
		);
	}

	public function test_detail_shape_matches_the_fixture_detail_shape(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		$engine_detail  = $provider->get_contract( 101, 1 );
		$fixture_detail = ( new FixtureDataProvider() )->get_contract( 101, 1 );

		$this->assertNotNull( $engine_detail );
		$this->assertNotNull( $fixture_detail );

		// Every detail field the view-model reads is present with the same type.
		foreach ( [ 'id', 'status', 'billing_total', 'currency', 'billing_period', 'billing_interval', 'next_payment_gmt', 'payment_method', 'start_gmt', 'end_gmt', 'last_payment_gmt', 'last_updated_gmt', 'discount_total', 'shipping_total', 'tax_total', 'items', 'addresses' ] as $key ) {
			$this->assertArrayHasKey( $key, $engine_detail, "Engine detail carries the {$key} field." );
			$this->assertArrayHasKey( $key, $fixture_detail, "Fixture detail carries the {$key} field." );
			$this->assertSame(
				gettype( $fixture_detail[ $key ] ),
				gettype( $engine_detail[ $key ] ),
				"Engine and fixture detail agree on the type of {$key}."
			);
		}

		// The line-item rows agree on the canonical item keys.
		$this->assertSame(
			array_keys( $fixture_detail['items'][0] ),
			array_keys( $engine_detail['items'][0] ),
			'Engine and fixture line items carry the same keys.'
		);
	}

	public function test_detail_items_are_normalized_to_the_canonical_shape(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		$detail = $provider->get_contract( 101, 1 );

		$this->assertNotNull( $detail );
		$this->assertSame(
			[
				[
					'name'     => 'Monthly coffee box',
					'quantity' => 1.0,
					'subtotal' => '19.99',
					'total'    => '17.99',
				],
			],
			$detail['items'],
			'Engine item rows reduce to name/quantity/subtotal/total.'
		);
	}

	public function test_detail_addresses_are_reduced_to_wc_field_arrays(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		$detail = $provider->get_contract( 101, 1 );

		$this->assertNotNull( $detail );
		$this->assertSame( [ 'billing', 'shipping' ], array_keys( $detail['addresses'] ), 'Both address types survive.' );
		$this->assertSame( 'Ada', $detail['addresses']['billing']['first_name'] );
		$this->assertSame( 'ada@example.com', $detail['addresses']['billing']['email'] );
		$this->assertArrayNotHasKey( 'contract_id', $detail['addresses']['billing'], 'Storage bookkeeping keys are dropped.' );
		$this->assertArrayNotHasKey( 'address_type', $detail['addresses']['billing'], 'Storage bookkeeping keys are dropped.' );
		$this->assertArrayNotHasKey( 'email', $detail['addresses']['shipping'], 'Empty/absent fields are omitted.' );
	}

	public function test_related_orders_shape_matches_the_fixture_order_shape(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract(), [ $this->order() ] ) );

		$engine_orders  = $provider->get_related_orders( 101 );
		$fixture_orders = ( new FixtureDataProvider() )->get_related_orders( 101 );

		$this->assertNotEmpty( $engine_orders );
		$this->assertNotEmpty( $fixture_orders );

		$this->assertSame(
			array_keys( $fixture_orders[0] ),
			array_keys( $engine_orders[0] ),
			'Engine and fixture related-order rows carry the same keys.'
		);
	}

	public function test_cadence_is_sourced_from_the_plan_snapshot(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		$row = $provider->get_contracts_for_customer( 1 )[0];

		$this->assertSame( 'month', $row['billing_period'], 'Period is read off the plan snapshot.' );
		$this->assertSame( 1, $row['billing_interval'], 'Interval is read off the plan snapshot.' );
	}

	public function test_cadence_degrades_to_empty_when_the_snapshot_is_absent(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract( 101, ContractStatus::ACTIVE, false ) ) );

		$row = $provider->get_contracts_for_customer( 1 )[0];

		$this->assertSame( '', $row['billing_period'], 'A missing snapshot degrades to an empty period.' );
		$this->assertSame( 0, $row['billing_interval'], 'A missing snapshot degrades to a zero interval.' );
	}

	public function test_get_contract_returns_null_for_a_foreign_owned_contract(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		// Owned by customer 1, requested as customer 2: the facade's ownership-checked read
		// returns null, indistinguishable from not-found.
		$this->assertNull( $provider->get_contract( 101, 2 ) );
	}

	public function test_get_contract_returns_null_for_an_unknown_id(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ) );

		$this->assertNull( $provider->get_contract( 999999, 1 ) );
	}
}
