<?php
/**
 * Unit tests for the checkout contract-creation handler.
 *
 * The handler is a thin driver: for each subscription line item on a processed
 * order it resolves the chosen plan and hands the order + plan to the engine's
 * ContractFactory. These tests inject fake factory / plan-finder / scheduler
 * seams so the iteration, idempotency, and error-handling logic is exercised
 * without a database or a booted WooCommerce.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class ContractCreationHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['woocommerce_subscriptions_lite_test_logs']  = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'] = [];
	}

	/**
	 * Build a persisted plan double (id assigned, monthly cadence).
	 *
	 * @param int $id Plan id to stamp on the double.
	 */
	private function make_plan( int $id = 7 ): Plan {
		$plan = Plan::create(
			1,
			[
				'name'           => 'Monthly',
				'billing_policy' => new BillingPolicy( 'month', 1, null, null, null ),
				'category'       => Plan::DEFAULT_CATEGORY,
				'extension_slug' => 'lite',
			]
		);
		$plan->set_id( $id );
		return $plan;
	}

	/**
	 * Build a contract double standing in for the factory's return value.
	 *
	 * @param int $id      Contract id to stamp on the double.
	 * @param int $plan_id Selling plan id the contract references.
	 */
	private function make_contract( int $id = 100, int $plan_id = 7 ): Contract {
		$contract = Contract::create(
			[
				'customer_id'     => 1,
				'currency'        => 'USD',
				'selling_plan_id' => $plan_id,
				'origin_order_id' => 55,
				'billing_total'   => '19.99',
				'start_gmt'       => '2026-01-01 00:00:00',
				'schedule_source' => Contract::SCHEDULE_SOURCE_PRIMITIVE,
				'items'           => [],
				'addresses'       => [],
				'meta'            => [],
			]
		);
		$contract->set_id( $id );
		return $contract;
	}

	public function test_creates_one_contract_per_subscription_line_item(): void {
		$plan  = $this->make_plan( 7 );
		$order = new WC_Order(
			55,
			[
				new WC_Order_Item_Product( 1, [ '_selling_plan_id' => '7' ] ),
				new WC_Order_Item_Product( 2, [] ), // No plan - skipped.
			]
		);

		$factory_calls = [];
		$handler       = new ContractCreationHandler(
			function ( WC_Order $o, Plan $p ) use ( &$factory_calls ): Contract {
				$factory_calls[] = [ $o->get_id(), $p->get_id() ];
				return $this->make_contract( 100, (int) $p->get_id() );
			},
			static fn ( int $plan_id ): ?Plan => 7 === $plan_id ? $plan : null,
			static function ( Contract $c ): bool {
				return true;
			},
			static fn ( WC_Order $o ): ?Contract => null
		);

		$handler->create_contracts_for_order( 55, [], $order );

		$this->assertSame( [ [ 55, 7 ] ], $factory_calls, 'Factory called once, only for the subscription line item.' );
	}

	public function test_skips_whole_order_when_a_contract_already_exists(): void {
		$plan  = $this->make_plan( 7 );
		$order = new WC_Order( 55, [ new WC_Order_Item_Product( 1, [ '_selling_plan_id' => '7' ] ) ] );

		$factory_called = false;
		$existing       = $this->make_contract();
		$handler        = new ContractCreationHandler(
			function ( WC_Order $o, Plan $p ) use ( &$factory_called ): Contract {
				$factory_called = true;
				return $this->make_contract();
			},
			static fn ( int $plan_id ): ?Plan => $plan,
			static fn ( Contract $c ): bool => true,
			static fn ( WC_Order $o ): ?Contract => $existing // Order already has a contract.
		);

		$handler->create_contracts_for_order( 55, [], $order );

		$this->assertFalse( $factory_called, 'Idempotency: an order that already has a contract is skipped wholesale.' );
	}

	public function test_skips_line_item_with_unresolvable_plan(): void {
		$order = new WC_Order( 55, [ new WC_Order_Item_Product( 1, [ '_selling_plan_id' => '7' ] ) ] );

		$factory_called = false;
		$handler        = new ContractCreationHandler(
			function ( WC_Order $o, Plan $p ) use ( &$factory_called ): Contract {
				$factory_called = true;
				return $this->make_contract();
			},
			static fn ( int $plan_id ): ?Plan => null, // Plan deleted between cart-add and checkout.
			static fn ( Contract $c ): bool => true,
			static fn ( WC_Order $o ): ?Contract => null
		);

		$handler->create_contracts_for_order( 55, [], $order );

		$this->assertFalse( $factory_called, 'A line item whose plan cannot be resolved is skipped, not passed to the factory.' );
		$this->assertNotEmpty( $GLOBALS['woocommerce_subscriptions_lite_test_logs'], 'The unresolvable plan is logged.' );
	}

	public function test_factory_throw_is_logged_and_does_not_block_siblings(): void {
		$plan  = $this->make_plan( 7 );
		$order = new WC_Order(
			55,
			[
				new WC_Order_Item_Product( 1, [ '_selling_plan_id' => '7' ] ),
				new WC_Order_Item_Product( 2, [ '_selling_plan_id' => '7' ] ),
			]
		);

		$contracts_created = 0;
		$handler           = new ContractCreationHandler(
			function ( WC_Order $o, Plan $p ) use ( &$contracts_created ): Contract {
				++$contracts_created;
				if ( 1 === $contracts_created ) {
					throw new RuntimeException( 'boom' );
				}
				return $this->make_contract();
			},
			static fn ( int $plan_id ): ?Plan => $plan,
			static fn ( Contract $c ): bool => true,
			static fn ( WC_Order $o ): ?Contract => null
		);

		$handler->create_contracts_for_order( 55, [], $order );

		$this->assertSame( 2, $contracts_created, 'A throwing line item does not abort the loop; the sibling still gets a contract.' );
		$this->assertNotEmpty( $GLOBALS['woocommerce_subscriptions_lite_test_logs'], 'The factory failure is logged.' );
	}

	public function test_schedules_first_renewal_for_each_created_contract(): void {
		$plan  = $this->make_plan( 7 );
		$order = new WC_Order( 55, [ new WC_Order_Item_Product( 1, [ '_selling_plan_id' => '7' ] ) ] );

		$scheduled = [];
		$handler   = new ContractCreationHandler(
			fn ( WC_Order $o, Plan $p ): Contract => $this->make_contract( 100, 7 ),
			static fn ( int $plan_id ): ?Plan => $plan,
			static function ( Contract $c ) use ( &$scheduled ): bool {
				$scheduled[] = $c->get_id();
				return true;
			},
			static fn ( WC_Order $o ): ?Contract => null
		);

		$handler->create_contracts_for_order( 55, [], $order );

		$this->assertSame( [ 100 ], $scheduled, 'The created contract is handed to the scheduler.' );
	}

	public function test_register_binds_the_checkout_hook(): void {
		ContractCreationHandler::register();

		$hooks = array_filter(
			$GLOBALS['woocommerce_subscriptions_lite_test_hooks'],
			static fn ( array $h ): bool => 'woocommerce_checkout_order_processed' === $h['hook']
		);
		$this->assertNotEmpty( $hooks, 'register() binds the classic checkout-processed hook.' );
	}

	public function test_non_order_argument_is_ignored(): void {
		$factory_called = false;
		$handler        = new ContractCreationHandler(
			function ( WC_Order $o, Plan $p ) use ( &$factory_called ): Contract {
				$factory_called = true;
				return $this->make_contract();
			},
			static fn ( int $plan_id ): ?Plan => $this->make_plan(),
			static fn ( Contract $c ): bool => true,
			static fn ( WC_Order $o ): ?Contract => null
		);

		$handler->create_contracts_for_order( 0, [], null );

		$this->assertFalse( $factory_called, 'A null order (defensive) is a no-op.' );
	}
}
