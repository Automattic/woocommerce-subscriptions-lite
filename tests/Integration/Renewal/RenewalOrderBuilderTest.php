<?php
/**
 * Integration tests for RenewalOrderBuilder.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Payment_Token_CC;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder
 */
final class RenewalOrderBuilderTest extends LiteIntegrationTestCase {

	/**
	 * Save a card token for a customer.
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $last4       Last four digits.
	 */
	private function make_token( int $customer_id, string $last4 ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_' . $last4 );
		$token->set_gateway_id( 'cod' );
		$token->set_user_id( $customer_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( $last4 );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2099' );
		$token->save();

		return $token;
	}

	/**
	 * Append cycle 2 to a contract.
	 *
	 * @param int    $contract_id    Contract id.
	 * @param string $expected_total Expected total.
	 */
	private function add_cycle_two( int $contract_id, string $expected_total = '19.99' ): CycleView {
		return Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => '2026-02-15 00:00:00',
				'ends_at_gmt'    => '2026-03-15 00:00:00',
				'expected_total' => $expected_total,
				'currency'       => 'USD',
			]
		);
	}

	public function test_builds_a_draft_renewal_order_from_the_contract(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract(
			$customer_id,
			[
				'payment_method'       => 'cod',
				'payment_method_title' => 'Cash on delivery',
				'quantity'             => 2,
			]
		);
		$cycle       = $this->add_cycle_two( $contract_id, '42.50' );

		$order = ( new RenewalOrderBuilder() )->build( $this->get_contract( $contract_id ), $cycle );

		$this->assertInstanceOf( WC_Order::class, $order );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'checkout-draft', $reloaded->get_status() );
		$this->assertSame( RenewalOrderBuilder::CREATED_VIA, $reloaded->get_created_via() );
		$this->assertSame( $customer_id, $reloaded->get_customer_id() );
		$this->assertSame( 'USD', $reloaded->get_currency() );
		$this->assertSame( 'cod', $reloaded->get_payment_method() );
		$this->assertSame( 'Cash on delivery', $reloaded->get_payment_method_title() );
		$this->assertSame( '42.50', $reloaded->get_total() );
		$this->assertSame( self::BILLING_ADDRESS['address_1'], $reloaded->get_billing_address_1() );
		$this->assertSame( self::BILLING_ADDRESS['email'], $reloaded->get_billing_email() );
		$this->assertSame( self::SHIPPING_ADDRESS['address_1'], $reloaded->get_shipping_address_1() );

		$items = array_values( $reloaded->get_items( 'line_item' ) );
		$this->assertCount( 1, $items );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $items[0] );
		$this->assertSame( 'Monthly Coffee Box', $items[0]->get_name() );
		$this->assertGreaterThan( 0, $items[0]->get_product_id() );
		$this->assertSame( 2, $items[0]->get_quantity() );
		$this->assertSame( 39.98, (float) $items[0]->get_total() );
	}

	public function test_copies_the_origin_gateway_data_and_tokens(): void {
		$customer_id    = $this->create_customer();
		$origin_token   = $this->make_token( $customer_id, '4242' );
		$contract_token = $this->make_token( $customer_id, '1111' );
		$contract_id    = $this->create_contract( $customer_id, [ 'payment_method' => 'cod' ] );
		$contract       = $this->get_contract( $contract_id );

		$origin = wc_get_order( (int) $contract->get_origin_order_id() );
		$this->assertInstanceOf( WC_Order::class, $origin );
		$origin->add_payment_token( $origin_token );
		$origin->update_meta_data( '_gateway_customer_id', 'cus_123' );
		$origin->update_meta_data( '_wc_points_earned', '20' );
		$origin->update_meta_data( '_edit_lock', '123:1' );
		$origin->update_meta_data( '_wcsl_marker', 'x' );
		$origin->update_meta_data( '_subscription_renewal', '9' );
		$origin->update_meta_data( '_wc_order_attribution_source_type', 'typein' );
		$origin->save();
		Contracts::update( $contract_id, [ 'payment_token_id' => $contract_token->get_id() ] );

		$order = ( new RenewalOrderBuilder() )->build( $this->get_contract( $contract_id ), $this->add_cycle_two( $contract_id ) );

		$this->assertInstanceOf( WC_Order::class, $order );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'cus_123', $reloaded->get_meta( '_gateway_customer_id' ) );
		foreach ( [ '_wc_points_earned', '_edit_lock', '_wcsl_marker', '_subscription_renewal', '_wc_order_attribution_source_type' ] as $excluded ) {
			$this->assertSame( '', $reloaded->get_meta( $excluded ), $excluded . ' is not copied.' );
		}
		$this->assertSame( [ $origin_token->get_id(), $contract_token->get_id() ], array_map( 'intval', $reloaded->get_payment_tokens() ) );
	}

	public function test_the_contract_token_is_not_attached_twice(): void {
		$customer_id = $this->create_customer();
		$token       = $this->make_token( $customer_id, '4242' );
		$contract_id = $this->create_contract( $customer_id, [ 'payment_method' => 'cod' ] );
		$contract    = $this->get_contract( $contract_id );
		$origin      = wc_get_order( (int) $contract->get_origin_order_id() );
		$this->assertInstanceOf( WC_Order::class, $origin );
		$origin->add_payment_token( $token );
		Contracts::update( $contract_id, [ 'payment_token_id' => $token->get_id() ] );

		$order = ( new RenewalOrderBuilder() )->build( $this->get_contract( $contract_id ), $this->add_cycle_two( $contract_id ) );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( [ $token->get_id() ], array_map( 'intval', $order->get_payment_tokens() ) );
	}

	public function test_a_deleted_product_returns_null_and_leaves_no_draft(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$contract    = $this->get_contract( $contract_id );
		$items       = $contract->get_items() ?? [];
		wp_delete_post( (int) $items[0]['product_id'], true );
		$this->capture_log();
		$drafts_before = wc_get_orders(
			[
				'status' => 'checkout-draft',
				'return' => 'ids',
			]
		);

		$order = ( new RenewalOrderBuilder() )->build( $contract, $this->add_cycle_two( $contract_id ) );

		$this->assertNull( $order );
		$this->assertSame(
			$drafts_before,
			wc_get_orders(
				[
					'status' => 'checkout-draft',
					'return' => 'ids',
				]
			)
		);
		$this->assertTrue( $this->has_logged( 'cannot build the renewal order' ) );
	}
}
