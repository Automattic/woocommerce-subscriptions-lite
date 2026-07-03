<?php
/**
 * Unit tests for the fixture data provider.
 *
 * Assert the fixture set covers every contract status, that a known id resolves
 * for the requesting customer, and that an unknown id resolves to null (the
 * asymmetric not-found rule - exercised here via the unknown-id arm, since the
 * fixtures are scoped to whoever asks).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\CustomerPortal;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\FixtureDataProvider;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\FixtureDataProvider
 */
final class FixtureDataProviderTest extends TestCase {

	public function test_list_covers_every_status_plus_the_on_hold_variant(): void {
		$contracts = ( new FixtureDataProvider() )->get_contracts_for_customer( 1 );

		$statuses = array_map(
			static fn ( array $contract ): string => (string) $contract['status'],
			$contracts
		);

		foreach ( ContractStatus::all() as $status ) {
			$this->assertContains( $status, $statuses, "Fixtures cover the {$status} status." );
		}

		// Two on-hold fixtures: the admin-action path (no next payment) and the
		// failed-payment retry path (next payment scheduled).
		$on_hold = array_values(
			array_filter(
				$contracts,
				static fn ( array $contract ): bool => ContractStatus::ON_HOLD === $contract['status']
			)
		);
		$this->assertCount( 2, $on_hold, 'Both on-hold variants are present.' );

		$with_next    = array_filter( $on_hold, static fn ( array $c ): bool => null !== $c['next_payment_gmt'] );
		$without_next = array_filter( $on_hold, static fn ( array $c ): bool => null === $c['next_payment_gmt'] );
		$this->assertCount( 1, $with_next, 'One on-hold fixture has a scheduled next payment.' );
		$this->assertCount( 1, $without_next, 'One on-hold fixture has no scheduled next payment.' );
	}

	public function test_each_contract_carries_items_and_related_orders(): void {
		$contracts = ( new FixtureDataProvider() )->get_contracts_for_customer( 1 );

		foreach ( $contracts as $contract ) {
			$this->assertArrayHasKey( 'items', $contract );
			$this->assertNotEmpty( $contract['items'] );
			$this->assertArrayHasKey( 'related_orders', $contract );
			$this->assertNotEmpty( $contract['related_orders'] );
			$this->assertArrayHasKey( 'payment_method', $contract );
		}
	}

	public function test_get_contract_resolves_a_known_id_for_the_customer(): void {
		$contract = ( new FixtureDataProvider() )->get_contract( 101, 1 );

		$this->assertNotNull( $contract );
		$this->assertSame( 101, $contract['id'] );
		$this->assertSame( ContractStatus::ACTIVE, $contract['status'] );
	}

	public function test_get_contract_returns_null_for_an_unknown_id(): void {
		$contract = ( new FixtureDataProvider() )->get_contract( 999999, 1 );

		$this->assertNull( $contract, 'An unknown id resolves to not-found.' );
	}

	public function test_get_related_orders_returns_orders_for_a_known_contract(): void {
		$orders = ( new FixtureDataProvider() )->get_related_orders( 101 );

		$this->assertNotEmpty( $orders );
		$this->assertArrayHasKey( 'number', $orders[0] );
		$this->assertArrayHasKey( 'status', $orders[0] );
	}

	public function test_get_related_orders_is_empty_for_an_unknown_contract(): void {
		$this->assertSame( [], ( new FixtureDataProvider() )->get_related_orders( 999999 ) );
	}

	public function test_list_windows_with_limit_and_offset_newest_first(): void {
		$provider = new FixtureDataProvider();

		$all = $provider->get_contracts_for_customer( 1 );
		$this->assertCount( 12, $all, 'Twelve fixture contracts, so the list paginates out of the box.' );
		$this->assertSame( 112, $all[0]['id'], 'Newest (highest id) first, matching the engine ordering.' );

		$first_page = $provider->get_contracts_for_customer( 1, 10, 0 );
		$this->assertCount( 10, $first_page );
		$this->assertSame( 112, $first_page[0]['id'] );

		$second_page = $provider->get_contracts_for_customer( 1, 10, 10 );
		$this->assertCount( 2, $second_page );
		$this->assertSame( [ 102, 101 ], array_column( $second_page, 'id' ) );
	}

	public function test_related_orders_window_over_the_long_history(): void {
		$provider = new FixtureDataProvider();

		$this->assertCount( 15, $provider->get_related_orders( 101 ), 'The long-running fixture carries a multi-page history.' );

		$first_page = $provider->get_related_orders( 101, 10, 0 );
		$this->assertCount( 10, $first_page );
		$this->assertSame( '1015', $first_page[0]['number'], 'Newest order first.' );

		$second_page = $provider->get_related_orders( 101, 10, 10 );
		$this->assertCount( 5, $second_page );
		$this->assertSame( '1005', $second_page[0]['number'] );
	}
}
