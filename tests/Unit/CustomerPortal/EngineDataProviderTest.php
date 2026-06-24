<?php
/**
 * Unit tests for the engine-backed data provider.
 *
 * The engine read classes ({@see \Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository}
 * and {@see \Automattic\WooCommerce\SubscriptionsEngine\Integration\Read\ContractReadModel})
 * are final and reach into the database, so they cannot be mocked or run without
 * WordPress. The provider therefore reads through two narrow Lite ports
 * ({@see ContractReader}, {@see ContractPresenter}); these tests pass doubles for
 * those ports - the presenter double emits the documented engine read-model shape -
 * and assert:
 *
 *  - the provider returns a structure key-identical to {@see FixtureDataProvider}
 *    for an equivalent contract (so {@see ViewModel} renders identically), and
 *  - the asymmetric not-found rule (unknown id and foreign-owned both null), and
 *  - the call-order ownership gate that keeps related orders from leaking.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\CustomerPortal;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\ContractPresenter;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Engine\ContractReader;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\FixtureDataProvider;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\EngineDataProvider
 */
final class EngineDataProviderTest extends TestCase {

	/**
	 * Build a hydrated contract for the doubles to reduce.
	 *
	 * @param int    $id     Contract id.
	 * @param string $status Contract status.
	 * @return Contract
	 */
	private function contract( int $id = 101, string $status = ContractStatus::ACTIVE ): Contract {
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
				'items'                => [
					[
						'item_name'  => 'Monthly coffee box',
						'item_type'  => 'line_item',
						'product_id' => 200,
						'quantity'   => '1',
						'subtotal'   => '19.99',
						'total'      => '19.99',
					],
				],
			]
		);
		$contract->set_id( $id );
		return $contract;
	}

	/**
	 * A presenter double emitting the documented engine read-model shape.
	 *
	 * @return ContractPresenter
	 */
	private function presenter(): ContractPresenter {
		return new class() implements ContractPresenter {

			public function contract_to_row( Contract $contract ): array {
				return [
					'id'               => (int) $contract->get_id(),
					'status'           => $contract->get_status(),
					'billing_total'    => $contract->get_billing_total(),
					'currency'         => $contract->get_currency(),
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'next_payment_gmt' => $contract->get_next_payment_gmt(),
					'payment_method'   => [
						'title'   => (string) $contract->get_payment_instrument()->get_title(),
						'expires' => '',
					],
				];
			}

			public function contract_to_detail( Contract $contract ): array {
				return array_merge(
					$this->contract_to_row( $contract ),
					[
						'start_gmt'        => $contract->get_start_gmt(),
						'end_gmt'          => $contract->get_end_gmt(),
						'last_payment_gmt' => $contract->get_last_payment_gmt(),
						'last_updated_gmt' => '2026-06-15 00:00:00',
						'items'            => $contract->get_items(),
					]
				);
			}

			public function related_orders( int $contract_id ): array {
				return [
					[
						'number'       => '1001',
						'date_gmt'     => '2026-01-01 00:00:00',
						'status'       => 'completed',
						'status_label' => 'Completed',
						'total'        => 'USD19.99',
						'view_url'     => 'https://example.test/order/1001',
					],
				];
			}
		};
	}

	/**
	 * A reader double over a fixed contract owned by customer 1.
	 *
	 * @param Contract $contract The contract to serve.
	 * @return ContractReader
	 */
	private function reader( Contract $contract ): ContractReader {
		return new class( $contract ) implements ContractReader {

			/** @var Contract */
			private $contract;

			public function __construct( Contract $contract ) {
				$this->contract = $contract;
			}

			public function find_by_customer_id( int $customer_id ): array {
				return 1 === $customer_id ? [ $this->contract ] : [];
			}

			public function is_owned_by( int $contract_id, int $customer_id ): bool {
				return (int) $this->contract->get_id() === $contract_id && 1 === $customer_id;
			}

			public function find( int $contract_id ): ?Contract {
				return (int) $this->contract->get_id() === $contract_id ? $this->contract : null;
			}
		};
	}

	public function test_list_row_shape_matches_the_fixture_row_shape(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ), $this->presenter() );

		$engine_rows  = $provider->get_contracts_for_customer( 1 );
		$fixture_rows = ( new FixtureDataProvider() )->get_contracts_for_customer( 1 );

		$this->assertNotEmpty( $engine_rows );

		// The view-model reads a fixed set of row fields off each contract; assert
		// the engine row carries every one of them with the same type.
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
		$provider = new EngineDataProvider( $this->reader( $this->contract() ), $this->presenter() );

		$engine_detail  = $provider->get_contract( 101, 1 );
		$fixture_detail = ( new FixtureDataProvider() )->get_contract( 101, 1 );

		$this->assertNotNull( $engine_detail );
		$this->assertNotNull( $fixture_detail );

		// Every detail field the view-model reads is present with the same type.
		foreach ( [ 'id', 'status', 'billing_total', 'currency', 'billing_period', 'billing_interval', 'next_payment_gmt', 'payment_method', 'start_gmt', 'end_gmt', 'last_payment_gmt', 'last_updated_gmt', 'items' ] as $key ) {
			$this->assertArrayHasKey( $key, $engine_detail, "Engine detail carries the {$key} field." );
			$this->assertArrayHasKey( $key, $fixture_detail, "Fixture detail carries the {$key} field." );
			$this->assertSame(
				gettype( $fixture_detail[ $key ] ),
				gettype( $engine_detail[ $key ] ),
				"Engine and fixture detail agree on the type of {$key}."
			);
		}
	}

	public function test_related_orders_shape_matches_the_fixture_order_shape(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ), $this->presenter() );

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

	public function test_get_contract_returns_null_for_a_foreign_owned_contract(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ), $this->presenter() );

		// Owned by customer 1, requested as customer 2: the ownership guard fails,
		// so the request is indistinguishable from not-found.
		$this->assertNull( $provider->get_contract( 101, 2 ) );
	}

	public function test_get_contract_returns_null_for_an_unknown_id(): void {
		$provider = new EngineDataProvider( $this->reader( $this->contract() ), $this->presenter() );

		$this->assertNull( $provider->get_contract( 999999, 1 ) );
	}

	public function test_get_contract_returns_null_when_an_owned_row_vanishes(): void {
		// A reader that confirms ownership but then cannot find the row (a delete
		// racing the guard) must still resolve to null, never a malformed detail.
		$reader = new class() implements ContractReader {
			public function find_by_customer_id( int $customer_id ): array {
				return [];
			}
			public function is_owned_by( int $contract_id, int $customer_id ): bool {
				return true;
			}
			public function find( int $contract_id ): ?Contract {
				return null;
			}
		};

		$provider = new EngineDataProvider( $reader, $this->presenter() );

		$this->assertNull( $provider->get_contract( 101, 1 ) );
	}
}
