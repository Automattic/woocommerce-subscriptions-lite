<?php
/**
 * Integration tests for ContractCreationHandler's payment-method check: an order paid with a
 * payment method that cannot charge the renewals gets no contract and a deferral, on the
 * status-change hook and on the resume action, while renewing and free subscriptions are
 * created as before.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Support\RenewingTestGateway;
use WC_Order;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class ContractCreationPaymentMethodTest extends LiteIntegrationTestCase {

	/**
	 * A plan order for a new customer, paid with `$payment_method` (not yet in a paid status).
	 *
	 * @param string $payment_method Gateway id.
	 * @param string $price          Product price.
	 */
	private function plan_order( string $payment_method, string $price = '19.99' ): WC_Order {
		$order = $this->create_subscription_order(
			$this->create_customer(),
			[
				'payment_method'       => $payment_method,
				'payment_method_title' => 'cod' === $payment_method ? 'Cash on delivery' : $payment_method,
				'price'                => $price,
			]
		);
		$this->stamp_plan( $order, $this->make_plan() );

		return $order;
	}

	/**
	 * The deferral reason recorded on an order, or '' when none.
	 *
	 * @param WC_Order $order The order.
	 */
	private function deferral_reason( WC_Order $order ): string {
		return (string) wc_get_order( $order->get_id() )->get_meta( ContractCreationHandler::CREATION_DEFERRED_META );
	}

	/**
	 * Pending resume actions for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, mixed>
	 */
	private function pending_resumes( WC_Order $order ): array {
		return $this->get_pending_actions( 'woocommerce_subscriptions_lite_resume_checkout', [ 'order_id' => $order->get_id() ] );
	}

	/**
	 * Make `is_paid()` true for the order without a status change, so the hook never runs.
	 *
	 * @param WC_Order $order The order.
	 */
	private function treat_as_paid( WC_Order $order ): void {
		$order_id = $order->get_id();
		add_filter(
			'woocommerce_order_is_paid',
			static function ( $is_paid, $paid_order ) use ( $order_id ) {
				return $paid_order instanceof WC_Order && $order_id === $paid_order->get_id() ? true : $is_paid;
			},
			10,
			2
		);
	}

	public function test_an_order_paid_with_a_gateway_that_cannot_renew_gets_no_contract_and_a_deferral(): void {
		$this->capture_log();
		$order = $this->plan_order( 'cod' );

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PAYMENT_METHOD_CANNOT_RENEW, $this->deferral_reason( $order ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'No subscription was created' ) );
		$this->assertTrue( $this->has_logged( sprintf( 'deferred contract creation for order %d (payment_method_cannot_renew)', $order->get_id() ) ) );
		$this->assertSame( [], $this->pending_resumes( $order ), 'Nothing to resume.' );
	}

	public function test_a_later_paid_transition_neither_creates_nor_notes_again(): void {
		$order = $this->plan_order( 'cod' );

		$order->update_status( OrderStatus::PROCESSING );
		$order->update_status( OrderStatus::COMPLETED );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'No subscription was created' ) );
	}

	/**
	 * @testWith ["ppcp-gateway"]
	 *           [""]
	 *
	 * @param string $payment_method A gateway id that is not registered, or none.
	 */
	public function test_an_unregistered_or_empty_payment_method_defers( string $payment_method ): void {
		$order = $this->plan_order( $payment_method );

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PAYMENT_METHOD_CANNOT_RENEW, $this->deferral_reason( $order ) );
	}

	public function test_a_disabled_gateway_that_can_renew_creates_the_contract(): void {
		$gateway = WC()->payment_gateways()->payment_gateways()[ RenewingTestGateway::ID ] ?? null;
		$this->assertInstanceOf( RenewingTestGateway::class, $gateway );
		$this->assertSame( 'no', $gateway->enabled, 'The suite gateway is disabled.' );
		$order = $this->plan_order( RenewingTestGateway::ID );

		$order->update_status( OrderStatus::PROCESSING );

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertSame( ContractStatus::ACTIVE, $contracts[0]->get_status() );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	public function test_a_free_subscription_without_a_payment_method_creates_the_contract(): void {
		$order = $this->plan_order( '', '0' );

		$order->update_status( OrderStatus::PROCESSING );

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts, 'A subscription that renews for 0 needs no gateway.' );
		$this->assertSame( '0.00000000', $contracts[0]->get_billing_total() );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	public function test_a_mixed_cart_on_a_gateway_that_cannot_renew_keeps_the_mixed_cart_reason(): void {
		$order   = $this->plan_order( 'cod' );
		$product = new WC_Product_Simple();
		$product->set_name( 'One-time mug' );
		$product->set_regular_price( '5.00' );
		$product->save();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( ContractCreationHandler::REASON_MIXED_CART, $this->deferral_reason( $order ) );
	}

	public function test_the_resume_action_defers_once_without_counting_a_failed_run(): void {
		$order = $this->plan_order( 'cod' );
		$this->treat_as_paid( $order );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PAYMENT_METHOD_CANNOT_RENEW, $this->deferral_reason( $order ) );
		$this->assertCount( 1, $this->get_order_notes_containing( $order->get_id(), 'No subscription was created' ), 'The second run is a no-op.' );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ), 'Not a failed run.' );
		$this->assertSame( [], $this->pending_resumes( $order ), 'Not rescheduled.' );
	}

	public function test_a_draft_already_written_for_the_order_still_resumes(): void {
		$order   = $this->plan_order( 'cod' );
		$plan_id = (int) $order->get_items()[ array_key_first( $order->get_items() ) ]->get_meta( '_wcsl_selling_plan_id' );
		$created = Contracts::create(
			[
				'extension_slug'  => Package::EXTENSION_SLUG,
				'creation_key'    => 'order:' . $order->get_id(),
				'status'          => ContractStatus::DRAFT,
				'customer_id'     => $order->get_customer_id(),
				'currency'        => 'USD',
				'selling_plan_id' => $plan_id,
				'origin_order_id' => $order->get_id(),
				'billing_total'   => '19.99',
			],
			[ 'operation' => ContractCreationHandler::OPERATION ]
		);
		$created['lock']->release();

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $created['contract']->get_id() )->get_status(), 'The check ran before the draft was written.' );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}
}
