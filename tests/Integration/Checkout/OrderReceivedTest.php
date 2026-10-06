<?php
/**
 * Integration tests for the order-received subscription summary.
 *
 * The summary path builds a real contract through Lite's checkout mapping and finds
 * it by origin order through the engine facade, exactly as the thank-you page does;
 * the deferral and plain-order paths assert the branch selection off order meta.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\OrderReceived;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\OrderReceived
 */
final class OrderReceivedTest extends LiteIntegrationTestCase {

	/**
	 * Capture the thank-you output for an order.
	 *
	 * @param int $order_id Order id.
	 */
	private function render( int $order_id ): string {
		ob_start();
		( new OrderReceived() )->render( $order_id );

		return (string) ob_get_clean();
	}

	public function test_renders_a_summary_with_a_manage_link_for_a_created_contract(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );
		$order_id    = (int) Subscriptions::get( $contract_id )->get_origin_order_id();

		$this->assertSame( '', (string) wc_get_order( $order_id )->get_meta( '_subscription_contract_id' ), 'No contract meta on the order: the page finds the contract by origin order.' );

		$html = $this->render( $order_id );

		$this->assertStringContainsString( 'Related subscriptions', $html, 'The related-subscriptions section renders.' );
		$this->assertStringContainsString( 'woocommerce-orders-table', $html, 'It reuses the WooCommerce orders-table markup so the theme styles it.' );
		$this->assertStringContainsString( '/ month', $html, 'The amount carries the plan cadence, read from the shared Formatter.' );
		$this->assertStringContainsString( 'wc-subscriptions-lite-manage-subscription', $html, 'The View link renders.' );
		$this->assertStringContainsString( '#' . $contract_id, $html, 'The row shows the contract number.' );
		$this->assertStringContainsString( (string) $contract_id, $html, 'The View link targets the contract.' );
	}

	public function test_renders_nothing_for_a_contract_owned_by_another_customer(): void {
		$contract_id = $this->create_contract( $this->create_customer() );
		$order_id    = (int) Subscriptions::get( $contract_id )->get_origin_order_id();
		Contracts::update( $contract_id, [ 'customer_id' => $this->create_customer() ] );

		$this->assertSame( '', trim( $this->render( $order_id ) ) );
	}

	public function test_renders_nothing_for_a_draft(): void {
		$contract_id = $this->create_contract( $this->create_customer() );
		$order_id    = (int) Subscriptions::get( $contract_id )->get_origin_order_id();
		Contracts::update( $contract_id, [ 'status' => 'draft' ] );

		$this->assertSame( '', trim( $this->render( $order_id ) ) );
	}

	public function test_renders_a_warning_when_creation_was_deferred(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id );
		$order->update_meta_data( ContractCreationHandler::CREATION_DEFERRED_META, ContractCreationHandler::REASON_MIXED_CART );
		$order->save();

		$this->assertStringContainsString( 'wc-subscriptions-lite-order-notice', $this->render( $order->get_id() ), 'The deferral warning renders.' );
	}

	public function test_renders_nothing_for_a_plain_order(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id );

		$this->assertSame( '', trim( $this->render( $order->get_id() ) ) );
	}
}
