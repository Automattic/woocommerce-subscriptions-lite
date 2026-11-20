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
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Support\RenewingTestGateway;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class ContractCreationPaymentMethodTest extends LiteIntegrationTestCase {

	/**
	 * The plan the last {@see self::plan_order()} stamped.
	 *
	 * @var PlanView|null
	 */
	private $plan;

	/**
	 * A plan order for a new customer, paid with `$payment_method` (not yet in a paid status).
	 * The plan is kept in {@see self::$plan}.
	 *
	 * @param string $payment_method Gateway id.
	 * @param string $price          Product price.
	 */
	private function plan_order( string $payment_method, string $price = '19.99' ): WC_Order {
		$order      = $this->create_subscription_order(
			$this->create_customer(),
			[
				'payment_method'       => $payment_method,
				'payment_method_title' => 'cod' === $payment_method ? 'Cash on delivery' : $payment_method,
				'price'                => $price,
			]
		);
		$this->plan = $this->make_plan();
		$this->stamp_plan( $order, $this->plan );

		return $order;
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
	 * The order's deferral notes (both wordings start "No subscription was created").
	 *
	 * @param WC_Order $order The order.
	 * @return array<int, string>
	 */
	private function deferral_notes( WC_Order $order ): array {
		return $this->get_order_notes_containing( $order->get_id(), 'No subscription was created' );
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
		$notes = $this->deferral_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'Cash on delivery (cod)', $notes[0], 'The note names the payment method.' );
		$this->assertStringContainsString( 'cannot charge subscription renewals automatically', $notes[0], 'A registered gateway without renewal support.' );
		$this->assertStringContainsString( 'Refund it', $notes[0], 'The way forward.' );
		$this->assertStringNotContainsString( 'was not found', $notes[0] );
		$this->assertStringNotContainsString( '(reason:', $notes[0], 'Not the generic note.' );
		$this->assertTrue( $this->has_logged( sprintf( 'deferred contract creation for order %d (payment_method_cannot_renew: payment method "cod" cannot charge renewals automatically)', $order->get_id() ) ) );
		$this->assertSame( [], $this->pending_resumes( $order ), 'Nothing to resume.' );
	}

	public function test_a_later_paid_transition_neither_creates_nor_notes_again(): void {
		$order = $this->plan_order( 'cod' );

		$order->update_status( OrderStatus::PROCESSING );
		$order->update_status( OrderStatus::COMPLETED );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertCount( 1, $this->deferral_notes( $order ) );
	}

	public function test_a_payment_method_that_is_not_a_registered_gateway_defers_as_unavailable(): void {
		$this->capture_log();
		$order = $this->plan_order( 'ppcp-gateway' );

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PAYMENT_METHOD_CANNOT_RENEW, $this->deferral_reason( $order ) );
		$notes = $this->deferral_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'its payment method, ppcp-gateway, was not found among this site\'s payment methods', $notes[0], 'Not registered; a title equal to the id is printed once.' );
		$this->assertStringNotContainsString( 'cannot charge subscription renewals', $notes[0] );
		$this->assertTrue( $this->has_logged( sprintf( 'deferred contract creation for order %d (payment_method_cannot_renew: payment method "ppcp-gateway" was not found among the site\'s payment gateways)', $order->get_id() ) ) );
	}

	public function test_an_order_without_a_payment_method_defers_with_its_own_wording(): void {
		$this->capture_log();
		$order = $this->plan_order( '' );

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PAYMENT_METHOD_CANNOT_RENEW, $this->deferral_reason( $order ) );
		$notes = $this->deferral_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'it has no payment method', $notes[0] );
		$this->assertTrue( $this->has_logged( sprintf( 'deferred contract creation for order %d (payment_method_cannot_renew: the order has no payment method)', $order->get_id() ) ) );
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

	/**
	 * A subscription that renews for 0 needs no gateway, whatever the order was paid with.
	 *
	 * @testWith [""]
	 *           ["cod"]
	 *
	 * @param string $payment_method No payment method, or one that cannot renew.
	 */
	public function test_a_free_subscription_creates_the_contract_whatever_the_payment_method( string $payment_method ): void {
		$order = $this->plan_order( $payment_method, '0' );

		$order->update_status( OrderStatus::PROCESSING );

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$this->assertEqualsWithDelta( 0.0, (float) $contracts[0]->get_billing_total(), 0.0001 );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	/**
	 * The rule reads the recurring total of the plan lines, not the order total: a fee is
	 * charged once, so a free plan line plus a fee still renews for 0.
	 */
	public function test_a_free_plan_line_with_a_paid_fee_on_a_gateway_that_cannot_renew_creates_the_contract(): void {
		$order = $this->plan_order( 'cod', '0' );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Delivery fee' );
		$fee->set_total( '5.00' );
		$order->add_item( $fee );
		$order->calculate_totals( false );
		$order->save();
		$this->assertEqualsWithDelta( 5.0, (float) $order->get_total(), 0.0001, 'The order itself is paid.' );

		$order->update_status( OrderStatus::PROCESSING );

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts, 'Its renewals are free, so no gateway is needed.' );
		$this->assertEqualsWithDelta( 0.0, (float) $contracts[0]->get_billing_total(), 0.0001 );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}

	public function test_a_mixed_cart_on_a_gateway_that_cannot_renew_keeps_the_mixed_cart_reason_and_note(): void {
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
		$notes = $this->deferral_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( '(reason: mixed_cart)', $notes[0], 'The generic note, not the payment-method one.' );
	}

	public function test_the_resume_action_defers_once_without_counting_a_failed_run(): void {
		$order = $this->plan_order( 'cod' );
		$this->treat_as_paid( $order );

		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );
		do_action( 'woocommerce_subscriptions_lite_resume_checkout', $order->get_id() );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PAYMENT_METHOD_CANNOT_RENEW, $this->deferral_reason( $order ) );
		$this->assertCount( 1, $this->deferral_notes( $order ), 'The second run is a no-op.' );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_wcsl_resume_attempts' ), 'Not a failed run.' );
		$this->assertSame( [], $this->pending_resumes( $order ), 'Not rescheduled.' );
	}

	public function test_a_draft_already_written_for_the_order_still_resumes(): void {
		$order   = $this->plan_order( 'cod' );
		$created = Contracts::create(
			[
				'extension_slug'  => Package::EXTENSION_SLUG,
				'creation_key'    => 'order:' . $order->get_id(),
				'status'          => ContractStatus::DRAFT,
				'customer_id'     => $order->get_customer_id(),
				'currency'        => 'USD',
				'selling_plan_id' => $this->plan->get_id(),
				'origin_order_id' => $order->get_id(),
				'billing_total'   => '19.99',
			],
			[ 'operation' => ContractCreationHandler::OPERATION ]
		);
		$created['lock']->release();

		$order->update_status( OrderStatus::PROCESSING );

		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $created['contract']->get_id() )->get_status(), 'A draft already written for the order resumes even though its payment method cannot renew.' );
		$this->assertSame( '', $this->deferral_reason( $order ) );
	}
}
