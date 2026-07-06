<?php
/**
 * Smoke test for the integration environment plumbing.
 *
 * Proves the stack the whole suite relies on: WordPress test framework up,
 * WooCommerce active, the plugin booted with its vendored engine, engine
 * schema installed, and the seed -> facade read round-trip working.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsLite\Package;

/**
 * @coversNothing
 */
final class SmokeTest extends LiteIntegrationTestCase {

	public function test_woocommerce_and_the_plugin_are_loaded(): void {
		$this->assertTrue( class_exists( \WooCommerce::class ), 'WooCommerce is loaded.' );
		$this->assertTrue( class_exists( Package::class ), 'The plugin package is loaded.' );
		$this->assertTrue( class_exists( Contract::class ), 'The vendored engine is resolved.' );
	}

	public function test_seeded_contract_reads_back_through_the_facade(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'product_name' => 'Smoke Box',
				'price'        => '12.00',
			]
		);

		$contract = Subscriptions::get_for_customer( $contract_id, $customer_id );

		$this->assertInstanceOf( Contract::class, $contract );
		$this->assertSame( 'active', $contract->get_status() );
		$this->assertSame( $customer_id, $contract->get_customer_id() );

		$items = $contract->get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( 'Smoke Box', $items[0]['item_name'] );

		$snapshot = $contract->get_plan_snapshot();
		$this->assertNotNull( $snapshot, 'The origin-cycle plan snapshot hydrates on read.' );

		$this->assertNull(
			Subscriptions::get_for_customer( $contract_id, $customer_id + 1 ),
			'A foreign customer reads null (ownership asymmetry).'
		);
	}
}
