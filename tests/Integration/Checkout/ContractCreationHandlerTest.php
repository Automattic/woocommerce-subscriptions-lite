<?php
/**
 * Integration tests for the checkout contract-creation handler.
 *
 * The happy paths run END TO END: a real order whose line item carries a
 * `_selling_plan_id`, a real plan row, the handler with its production seams,
 * and the contract read back through the engine facade. The failure-isolation
 * case injects a throwing factory through the handler's own constructor seam.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use RuntimeException;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class ContractCreationHandlerTest extends LiteIntegrationTestCase {

	/**
	 * Stamp a selling-plan id on every line item of an order.
	 *
	 * @param WC_Order $order   The order.
	 * @param int      $plan_id The plan id to stamp.
	 */
	private function stamp_plan_on_items( WC_Order $order, int $plan_id ): void {
		foreach ( $order->get_items() as $item ) {
			$item->add_meta_data( '_selling_plan_id', (string) $plan_id, true );
			$item->save();
		}
	}

	public function test_creates_a_contract_for_a_subscription_order_end_to_end(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id, [ 'product_name' => 'Checkout Box' ] );
		$this->stamp_plan_on_items( $order, (int) $plan->get_id() );

		( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id(), [], $order );

		$contracts = Subscriptions::list_for_customer( $customer_id );
		$this->assertCount( 1, $contracts, 'One contract per subscription order.' );

		// The single-contract read hydrates the full shape (items included).
		$contract = Subscriptions::get_for_customer( (int) $contracts[0]->get_id(), $customer_id );
		$this->assertSame( 'active', $contract->get_status() );
		$this->assertSame( (int) $plan->get_id(), $contract->get_selling_plan_id() );
		$this->assertSame( $order->get_id(), $contract->get_origin_order_id() );
		$this->assertSame( 'Checkout Box', $contract->get_items()[0]['item_name'] );
	}

	public function test_is_idempotent_for_an_order_that_already_has_a_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->stamp_plan_on_items( $order, (int) $plan->get_id() );

		$handler = new ContractCreationHandler();
		$handler->create_contracts_for_order( $order->get_id(), [], $order );
		$handler->create_contracts_for_order( $order->get_id(), [], wc_get_order( $order->get_id() ) );

		$this->assertCount(
			1,
			Subscriptions::list_for_customer( $customer_id ),
			'Re-processing the same order creates no second contract.'
		);
	}

	public function test_an_order_without_plan_items_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id ); // No plan meta on the item.

		( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id(), [], $order );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ) );
	}

	public function test_an_unresolvable_plan_id_creates_nothing(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id );
		$this->stamp_plan_on_items( $order, 999999 ); // Plan deleted between cart-add and checkout.

		( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id(), [], $order );

		$this->assertSame( [], Subscriptions::list_for_customer( $customer_id ) );
	}

	public function test_a_throwing_line_item_does_not_block_its_siblings(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		// A second plan-carrying line item on the same order.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Second Box' );
		$product->set_regular_price( '5.00' );
		$product->save();
		$order->add_product( $product, 1 );
		$order->save();
		$this->stamp_plan_on_items( $order, (int) $plan->get_id() );

		$calls   = 0;
		$handler = new ContractCreationHandler(
			static function ( WC_Order $o, Plan $p ) use ( &$calls ): Contract {
				++$calls;
				if ( 1 === $calls ) {
					throw new RuntimeException( 'factory failure under test' );
				}
				return ( new \Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\ContractFactory() )->create_from_order( $o, $p );
			}
		);
		$handler->create_contracts_for_order( $order->get_id(), [], $order );

		$this->assertSame( 2, $calls, 'The throwing line item does not abort the loop.' );
		$this->assertCount(
			1,
			Subscriptions::list_for_customer( $customer_id ),
			'The sibling line item still gets its contract.'
		);
	}

	public function test_the_checkout_hook_is_bound_by_the_bootstrap(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_order_processed' ),
			'The classic checkout-processed hook is bound.'
		);
	}
}
