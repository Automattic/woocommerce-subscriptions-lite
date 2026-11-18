<?php
/**
 * Integration tests for PaymentRequirement.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use ReflectionMethod;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Product_Simple;
use WP_Error;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\PaymentRequirement
 */
final class PaymentRequirementTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( did_action( 'wp_loaded' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		unset( $GLOBALS['wp']->query_vars['order-pay'] );
		foreach ( $this->gateways_enabled_before as $gateway_id => $enabled ) {
			WC()->payment_gateways()->payment_gateways()[ $gateway_id ]->enabled = $enabled;
		}
		parent::tear_down();
	}

	/**
	 * The `enabled` value of each gateway {@see self::enable_gateways()} changed.
	 *
	 * @var array<string, string>
	 */
	private $gateways_enabled_before = [];

	/**
	 * Enable cash on delivery, which can charge renewals, and bank transfer, which cannot.
	 */
	private function enable_gateways(): void {
		foreach ( [ 'cod', 'bacs' ] as $gateway_id ) {
			$gateway = WC()->payment_gateways()->payment_gateways()[ $gateway_id ];

			$this->gateways_enabled_before[ $gateway_id ] = $gateway->enabled;
			$gateway->enabled                             = 'yes';
		}
		$this->support_engine_charges( 'cod' );
	}

	/**
	 * Ids of the gateways WooCommerce offers now.
	 *
	 * @return string[]
	 */
	private function get_offered_gateway_ids(): array {
		return array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
	}

	/**
	 * Assert that `$offered` includes cash on delivery (can renew) and, unless
	 * `$offers_bacs`, excludes bank transfer (cannot). Other enabled gateways do not matter.
	 *
	 * @param string[] $offered     Offered gateway ids.
	 * @param bool     $offers_bacs Whether bank transfer is offered too.
	 * @param string   $message     Failure message.
	 */
	private function assert_offers( array $offered, bool $offers_bacs, string $message = '' ): void {
		$this->assertContains( 'cod', $offered, $message );
		if ( $offers_bacs ) {
			$this->assertContains( 'bacs', $offered, $message );
		} else {
			$this->assertNotContains( 'bacs', $offered, $message );
		}
	}

	/**
	 * Put a virtual product in the cart (no shipping needed), on a plan or one-time.
	 *
	 * @param bool $on_plan Whether to pick a monthly plan.
	 */
	private function add_virtual_to_cart( bool $on_plan ): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee club' );
		$product->set_regular_price( '20.00' );
		$product->set_virtual( true );
		$this->add_to_cart( (int) $product->save(), $on_plan );
	}

	/**
	 * A saved simple product.
	 *
	 * @param string $price Regular price.
	 */
	private function make_product( string $price = '20.00' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( $price );

		return (int) $product->save();
	}

	/**
	 * Add a product to the cart, on a plan or one-time.
	 *
	 * @param int  $product_id Product.
	 * @param bool $on_plan    Whether to pick a monthly plan.
	 */
	private function add_to_cart( int $product_id, bool $on_plan ): void {
		if ( $on_plan ) {
			( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
			$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $this->make_plan()->get_id();
		} else {
			unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		}
		WC()->cart->add_to_cart( $product_id );
		WC()->cart->calculate_totals();
	}

	/**
	 * An order with one line, optionally stamped with a plan.
	 *
	 * @param bool   $on_plan Whether the line carries a plan.
	 * @param string $price   Product price.
	 * @param string $status  Order status.
	 */
	private function make_order( bool $on_plan, string $price = '19.99', string $status = 'pending' ): WC_Order {
		$order = $this->create_subscription_order( $this->create_customer(), [ 'price' => $price ] );
		if ( $on_plan ) {
			$this->stamp_plan( $order, $this->make_plan() );
		}
		$order->set_date_paid( null );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	public function test_a_plan_cart_requires_a_reusable_method(): void {
		$this->add_to_cart( $this->make_product(), true );

		$this->assertTrue( Payments::requires_reusable_payment_method() );
	}

	public function test_a_plain_cart_does_not(): void {
		$this->add_to_cart( $this->make_product(), false );

		$this->assertFalse( Payments::requires_reusable_payment_method() );
	}

	public function test_a_plan_order_requires_a_reusable_method_and_a_plain_order_does_not(): void {
		$this->assertTrue( Payments::requires_reusable_payment_method( $this->make_order( true ) ) );
		$this->assertFalse( Payments::requires_reusable_payment_method( $this->make_order( false ) ) );
	}

	public function test_a_renewal_order_requires_a_reusable_method(): void {
		$order = wc_create_order( [ 'created_via' => RenewalOrderBuilder::CREATED_VIA ] );

		$this->assertTrue( Payments::requires_reusable_payment_method( $order ) );
	}

	/**
	 * A plan order brought to a $0 total by a credit (a negative fee), so its subscription
	 * still renews for the plan line.
	 *
	 * @param string $status Order status.
	 */
	private function make_credited_plan_order( string $status = 'pending' ): WC_Order {
		$order = $this->make_order( true, '19.99', $status );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Store credit' );
		$fee->set_total( '-19.99' );
		$order->add_item( $fee );
		$order->set_total( '0' );
		$order->save();

		return $order;
	}

	public function test_a_free_plan_order_that_renews_for_a_charge_needs_payment(): void {
		$this->assertTrue( $this->make_credited_plan_order()->needs_payment() );
	}

	public function test_a_free_plan_order_that_renews_for_free_does_not_need_payment(): void {
		$this->assertFalse( $this->make_order( true, '0' )->needs_payment() );
	}

	public function test_a_free_plan_order_already_completed_does_not_need_payment(): void {
		$this->assertFalse( $this->make_credited_plan_order( 'completed' )->needs_payment() );
	}

	public function test_a_free_order_without_a_plan_is_untouched(): void {
		$this->assertFalse( $this->make_order( false, '0' )->needs_payment() );
	}

	public function test_a_paid_order_keeps_core_s_answer(): void {
		$this->assertTrue( $this->make_order( true )->needs_payment() );
		$this->assertTrue( $this->make_order( false )->needs_payment() );
	}

	public function test_a_free_renewal_order_does_not_need_payment(): void {
		$order = wc_create_order( [ 'created_via' => RenewalOrderBuilder::CREATED_VIA ] );
		$order->set_total( '0' );
		$order->save();

		$this->assertFalse( $order->needs_payment() );
	}

	public function test_a_free_plan_cart_that_renews_for_a_charge_needs_payment(): void {
		add_action(
			'woocommerce_cart_calculate_fees',
			static function ( $cart ): void {
				$cart->add_fee( 'Store credit', -20 );
			}
		);
		$this->add_to_cart( $this->make_product(), true );

		$this->assertSame( 0.0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertTrue( WC()->cart->needs_payment() );
	}

	public function test_free_plan_and_plain_carts_do_not_need_payment(): void {
		$this->add_to_cart( $this->make_product( '0' ), true );
		$this->assertFalse( WC()->cart->needs_payment(), 'A subscription that renews for free collects no method.' );

		WC()->cart->empty_cart();
		$this->add_to_cart( $this->make_product( '0' ), false );
		$this->assertFalse( WC()->cart->needs_payment() );
	}

	public function test_a_plan_cart_offers_only_gateways_that_can_charge_renewals(): void {
		$this->enable_gateways();
		$this->add_virtual_to_cart( true );

		$this->assert_offers( $this->get_offered_gateway_ids(), false );
	}

	public function test_a_plain_cart_offers_every_gateway(): void {
		$this->enable_gateways();
		$this->add_virtual_to_cart( false );

		$this->assert_offers( $this->get_offered_gateway_ids(), true );
	}

	public function test_a_gateway_that_speaks_the_engine_without_the_subscriptions_feature_is_hidden(): void {
		$this->enable_gateways();
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function ( $supported, $feature, $gateway ) {
				return Payments::GATEWAY_FEATURE === $feature && is_object( $gateway ) && 'bacs' === $gateway->id ? true : $supported;
			},
			10,
			3
		);
		$this->add_virtual_to_cart( true );

		$this->assert_offers( $this->get_offered_gateway_ids(), false );
	}

	public function test_a_plan_cart_with_no_gateway_that_can_charge_renewals_offers_none(): void {
		$this->enable_gateways();
		WC()->payment_gateways()->payment_gateways()['cod']->enabled = 'no';
		$this->add_virtual_to_cart( true );

		$offered = $this->get_offered_gateway_ids();
		$this->assertNotContains( 'cod', $offered );
		$this->assertNotContains( 'bacs', $offered );
	}

	public function test_the_store_api_cart_lists_only_gateways_that_can_charge_renewals(): void {
		$this->enable_gateways();
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( true );

		$response = $this->dispatch_store_api( 'GET', '/cart' );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_offers( $response->get_data()['payment_methods'], false );
	}

	public function test_the_store_api_cart_lists_every_gateway_for_a_plain_cart(): void {
		$this->enable_gateways();
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( false );

		$this->assert_offers( $this->dispatch_store_api( 'GET', '/cart' )->get_data()['payment_methods'], true );
	}

	/**
	 * Place the current cart through the Store API checkout route.
	 *
	 * @param string $payment_method Gateway id.
	 */
	private function place_store_api_order( string $payment_method ): \WP_REST_Response {
		$address = self::BILLING_ADDRESS + [
			'company'   => '',
			'address_2' => '',
			'state'     => '',
		];

		return $this->dispatch_store_api(
			'POST',
			'/checkout',
			[
				'billing_address'  => (object) $address,
				'shipping_address' => (object) array_diff_key( $address, [ 'email' => true ] ),
				'payment_method'   => $payment_method,
			]
		);
	}

	public function test_the_store_api_checkout_refuses_a_gateway_that_cannot_charge_renewals(): void {
		$this->enable_gateways();
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( true );

		$response = $this->place_store_api_order( 'bacs' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_checkout_payment_method_disabled', $response->get_data()['code'] );
	}

	public function test_the_store_api_checkout_accepts_a_gateway_that_can_charge_renewals(): void {
		$this->enable_gateways();
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( true );

		$response = $this->place_store_api_order( 'cod' );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'cod', wc_get_order( $response->get_data()['order_id'] )->get_payment_method() );
	}

	/**
	 * The classic checkout's validation errors for the current cart paid with `$payment_method`
	 * (core's own validation; other fields are left empty, so only the `payment` code matters).
	 *
	 * @param string $payment_method Gateway id.
	 */
	private function get_classic_checkout_errors( string $payment_method ): WP_Error {
		$_POST['payment_method'] = $payment_method;
		$data                    = WC()->checkout()->get_posted_data();
		unset( $_POST['payment_method'] );

		$errors   = new WP_Error();
		$validate = new ReflectionMethod( WC()->checkout(), 'validate_checkout' );
		$validate->setAccessible( true );
		$validate->invokeArgs( WC()->checkout(), [ &$data, &$errors ] );

		return $errors;
	}

	public function test_the_classic_checkout_refuses_a_gateway_that_cannot_charge_renewals(): void {
		$this->enable_gateways();
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( true );

		$this->assertContains( 'payment', $this->get_classic_checkout_errors( 'bacs' )->get_error_codes() );
		$this->assertNotContains( 'payment', $this->get_classic_checkout_errors( 'cod' )->get_error_codes() );
	}

	public function test_the_classic_checkout_accepts_any_gateway_for_a_plain_cart(): void {
		$this->enable_gateways();
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( false );

		$this->assertNotContains( 'payment', $this->get_classic_checkout_errors( 'bacs' )->get_error_codes() );
	}

	/**
	 * A pending order owned by `$customer_id` with one line, stamped with a plan or not, and
	 * optionally created as a Lite renewal order.
	 *
	 * @param int  $customer_id Owning customer.
	 * @param bool $on_plan     Whether the line carries a plan.
	 * @param bool $is_renewal  Whether Lite created it as a renewal order.
	 */
	private function make_order_to_pay( int $customer_id, bool $on_plan, bool $is_renewal ): WC_Order {
		$order = $this->create_subscription_order( $customer_id );
		if ( $on_plan ) {
			$this->stamp_plan( $order, $this->make_plan() );
		}
		if ( $is_renewal ) {
			$order->set_created_via( RenewalOrderBuilder::CREATED_VIA );
		}
		$order->set_date_paid( null );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/**
	 * Ids of the gateways WooCommerce offers on the classic order-pay page for `$order`. The
	 * session cart holds an item too, which must not count there.
	 *
	 * @param WC_Order $order        The order being paid.
	 * @param bool     $plan_in_cart Whether the cart item is on a plan.
	 * @return string[]
	 */
	private function get_order_pay_gateway_ids( WC_Order $order, bool $plan_in_cart ): array {
		$this->add_virtual_to_cart( $plan_in_cart );
		$GLOBALS['wp']->query_vars['order-pay'] = (string) $order->get_id();

		return $this->get_offered_gateway_ids();
	}

	public function test_order_pay_for_a_renewal_order_offers_every_gateway(): void {
		$this->enable_gateways();

		$order = $this->make_order_to_pay( $this->create_customer(), true, true );

		$this->assert_offers( $this->get_order_pay_gateway_ids( $order, true ), true );
	}

	public function test_order_pay_for_a_first_subscription_order_offers_only_gateways_that_can_charge_renewals(): void {
		$this->enable_gateways();

		$order = $this->make_order_to_pay( $this->create_customer(), true, false );

		$this->assert_offers( $this->get_order_pay_gateway_ids( $order, false ), false );
	}

	public function test_order_pay_for_a_plain_order_offers_every_gateway(): void {
		$this->enable_gateways();

		$order = $this->make_order_to_pay( $this->create_customer(), false, false );

		$this->assert_offers( $this->get_order_pay_gateway_ids( $order, true ), true );
	}

	/**
	 * Pay `$order` through the Store API pay-for-order route, as its owner. The session cart
	 * holds an item too, which must not count there.
	 *
	 * @param WC_Order $order          The order.
	 * @param string   $payment_method Gateway id.
	 * @param bool     $plan_in_cart   Whether the cart item is on a plan.
	 * @param string   $route          Route under `wc/store/v1`, before the order id.
	 */
	private function pay_order_through_store_api( WC_Order $order, string $payment_method, bool $plan_in_cart, string $route = '/checkout/' ): \WP_REST_Response {
		wp_set_current_user( $order->get_customer_id() );
		$this->add_virtual_to_cart( $plan_in_cart );

		return $this->dispatch_store_api(
			'POST',
			$route . $order->get_id(),
			[
				'billing_address' => (object) ( self::BILLING_ADDRESS + [
					'company'   => '',
					'address_2' => '',
					'state'     => '',
				] ),
				'payment_method'  => $payment_method,
			]
		);
	}

	public function test_the_store_api_pays_a_renewal_order_with_any_gateway(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), true, true );

		$response = $this->pay_order_through_store_api( $order, 'bacs', true );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'bacs', wc_get_order( $order->get_id() )->get_payment_method() );
	}

	public function test_the_store_api_refuses_a_first_subscription_order_paid_with_a_gateway_that_cannot_charge_renewals(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), true, false );

		$response = $this->pay_order_through_store_api( $order, 'bacs', false );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_checkout_payment_method_disabled', $response->get_data()['code'] );
	}

	public function test_the_store_api_refuses_a_first_subscription_order_through_a_mixed_case_route(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), true, false );

		$response = $this->pay_order_through_store_api( $order, 'bacs', false, '/CHECKOUT/' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_checkout_payment_method_disabled', $response->get_data()['code'] );
		$this->assertNotSame( 'bacs', wc_get_order( $order->get_id() )->get_payment_method() );
	}

	public function test_a_rest_request_nested_in_the_pay_for_order_route_keeps_the_paid_order(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), true, true );
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			function (): void {
				$this->dispatch_store_api( 'GET', '/cart' );
			}
		);

		$response = $this->pay_order_through_store_api( $order, 'bacs', true );

		$this->assertSame( 200, $response->get_status(), 'The renewal order still decides after the nested request, not the plan cart.' );
		$this->assert_offers( $this->get_offered_gateway_ids(), false, 'The plan cart decides again once the route has run.' );
	}

	public function test_the_store_api_forgets_the_paid_order_after_the_request(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), true, true );

		$response = $this->pay_order_through_store_api( $order, 'bacs', true );

		$this->assertSame( 200, $response->get_status() );
		$this->assert_offers( $this->get_offered_gateway_ids(), false, 'The plan cart decides again once the route has run.' );
	}

	public function test_the_store_api_forgets_the_paid_order_after_a_refusal(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), true, false );

		$response = $this->pay_order_through_store_api( $order, 'bacs', false );

		$this->assertSame( 400, $response->get_status() );
		$this->assert_offers( $this->get_offered_gateway_ids(), true, 'The plain cart decides again once the refused route has run.' );
	}

	public function test_the_store_api_pays_a_plain_order_with_any_gateway(): void {
		$this->enable_gateways();
		$order = $this->make_order_to_pay( $this->create_customer(), false, false );

		$response = $this->pay_order_through_store_api( $order, 'bacs', true );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'bacs', wc_get_order( $order->get_id() )->get_payment_method() );
	}

	/**
	 * The classic checkout's notice when no gateway is offered.
	 */
	private function get_no_payment_methods_message(): string {
		return (string) apply_filters( 'woocommerce_no_available_payment_methods_message', 'Core notice.' );
	}

	public function test_the_no_payment_methods_notice_points_a_store_manager_at_the_payments_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->add_virtual_to_cart( true );

		$message = $this->get_no_payment_methods_message();

		$this->assertStringContainsString( 'charge subscription renewals automatically', $message );
		$this->assertStringContainsString( 'tab=checkout', $message );
	}

	public function test_the_no_payment_methods_notice_tells_a_customer_none_is_available_for_subscriptions(): void {
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( true );

		$message = $this->get_no_payment_methods_message();

		$this->assertNotSame( 'Core notice.', $message );
		$this->assertStringContainsString( 'subscriptions', $message );
		$this->assertStringNotContainsString( 'tab=checkout', $message, 'A customer gets no settings link.' );
	}

	public function test_the_no_payment_methods_notice_is_core_s_for_a_plain_cart(): void {
		wp_set_current_user( $this->create_customer() );
		$this->add_virtual_to_cart( false );

		$this->assertSame( 'Core notice.', $this->get_no_payment_methods_message() );
	}

	public function test_the_no_payment_methods_notice_is_core_s_on_order_pay_for_a_renewal_order(): void {
		$customer_id = $this->create_customer();
		wp_set_current_user( $customer_id );
		$order = $this->make_order_to_pay( $customer_id, true, true );
		$this->add_virtual_to_cart( true );
		$GLOBALS['wp']->query_vars['order-pay'] = (string) $order->get_id();

		$this->assertSame( 'Core notice.', $this->get_no_payment_methods_message() );
	}
}
