<?php
/**
 * Integration tests for the cart-side selling-plan round-trip.
 *
 * The capture, validation, and pricing paths run END TO END through the real
 * WooCommerce cart: a real product with real Lite applicability, the plan read
 * back through the engine catalog, and the hooks firing as a live add-to-cart
 * would fire them. The order-line-item write is exercised directly (the hook
 * WooCommerce fires only during checkout order creation), mirroring the
 * checkout-handler test's approach.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Cart;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Error;
use Exception;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks
 */
final class CartPlanHooksTest extends LiteIntegrationTestCase {

	/**
	 * Initialize the cart, session, and customer for out-of-frontend use, and
	 * start each test from an empty cart with no lingering notices.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( did_action( 'wp_loaded' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	/**
	 * Empty the cart and clear POST/notice state between tests.
	 */
	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		parent::tear_down();
	}

	/**
	 * Create a saved simple product.
	 *
	 * @param string $regular_price Regular price.
	 * @param string $sale_price    Optional sale price.
	 */
	private function make_product( string $regular_price = '20.00', string $sale_price = '' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( $regular_price );
		if ( '' !== $sale_price ) {
			$product->set_sale_price( $sale_price );
		}

		return (int) $product->save();
	}

	/**
	 * Persist a plan carrying a percentage discount.
	 *
	 * @param float $percent Percentage off.
	 */
	private function make_percentage_plan( float $percent ): PlanView {
		return $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => [
					'policies'      => [
						[
							'type'  => 'percentage',
							'value' => $percent,
						],
					],
					'one_time_fees' => [],
				],
			]
		);
	}

	/**
	 * Add a product to the cart with the selected plan in `$_POST`, as the PDP
	 * form submit carries it. This is a trusted programmatic add: it exercises
	 * capture + pricing but not the `woocommerce_add_to_cart_validation` filter
	 * (which only the customer-facing entry points run).
	 *
	 * @param int      $product_id Product to add.
	 * @param int|null $plan_id    Plan id to post, or null for a one-time add.
	 * @return string Cart item key.
	 */
	private function add_to_cart( int $product_id, ?int $plan_id ): string {
		if ( null === $plan_id ) {
			unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
		} else {
			$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $plan_id;
		}

		return (string) WC()->cart->add_to_cart( $product_id );
	}

	public function test_add_to_cart_with_a_valid_plan_captures_the_plan_id(): void {
		$product_id = $this->make_product();
		$plan_id    = (int) $this->make_plan()->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$key  = $this->add_to_cart( $product_id, $plan_id );
		$item = WC()->cart->get_cart_item( $key );

		$this->assertSame( $plan_id, $item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
	}

	public function test_the_same_product_on_two_plans_is_two_cart_rows(): void {
		$product_id = $this->make_product();
		$plan_a     = (int) $this->make_plan( 'month' )->get_id();
		$plan_b     = (int) $this->make_plan( 'week' )->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_a );
		$this->add_to_cart( $product_id, $plan_b );

		$this->assertCount( 2, WC()->cart->get_cart() );
	}

	public function test_a_one_time_add_carries_no_plan(): void {
		$product_id = $this->make_product();
		$this->make_plan();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$key  = $this->add_to_cart( $product_id, null );
		$item = WC()->cart->get_cart_item( $key );

		$this->assertArrayNotHasKey( CartPlanHooks::SELLING_PLAN_ID_KEY, $item );
	}

	public function test_validation_rejects_a_plan_that_does_not_apply(): void {
		// The customer-facing add-to-cart paths (form handler, AJAX, Store API,
		// session restore) run this filter; `WC_Cart::add_to_cart()` itself does
		// not, so validation is exercised through the filter as those paths do.
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		$other_id    = (int) $this->make_plan( 'week' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);

		$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $other_id;
		$passed                                      = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

		$this->assertFalse( $passed, 'A non-applicable plan fails add-to-cart validation.' );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_validation_allows_an_applicable_plan(): void {
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);

		$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $attached_id;
		$passed                                      = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

		$this->assertTrue( $passed );
		$this->assertEmpty( wc_get_notices( 'error' ) );
	}

	public function test_defensive_capture_drops_a_non_applicable_plan(): void {
		// A trusted programmatic add bypasses the validation filter, so
		// add_cart_item_data re-checks applicability: the item is added, but the
		// non-applicable plan id is not attached (defense-in-depth).
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		$other_id    = (int) $this->make_plan( 'week' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);

		$key  = $this->add_to_cart( $product_id, $other_id );
		$item = WC()->cart->get_cart_item( $key );

		$this->assertNotSame( '', $key );
		$this->assertArrayNotHasKey( CartPlanHooks::SELLING_PLAN_ID_KEY, $item, 'The non-applicable plan is not attached.' );
	}

	public function test_validation_rejects_a_store_api_injected_plan(): void {
		// The Store API add-item endpoint carries the selection in the
		// cart_item_data body (NOT $_POST); validation must read it there too.
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		$other_id    = (int) $this->make_plan( 'week' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );

		$passed = apply_filters(
			'woocommerce_add_to_cart_validation',
			true,
			$product_id,
			1,
			0,
			[],
			[ CartPlanHooks::SELLING_PLAN_ID_KEY => $other_id ]
		);

		$this->assertFalse( $passed, 'A plan injected via cart_item_data fails validation.' );
	}

	public function test_capture_strips_a_store_api_injected_non_applicable_plan(): void {
		// Even if validation is bypassed, add_cart_item_data must not trust a
		// client-supplied key: it strips it and only re-adds a validated plan.
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		$other_id    = (int) $this->make_plan( 'week' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );

		$result = ( new CartPlanHooks() )->add_cart_item_data(
			[ CartPlanHooks::SELLING_PLAN_ID_KEY => $other_id ],
			$product_id
		);

		$this->assertArrayNotHasKey( CartPlanHooks::SELLING_PLAN_ID_KEY, $result, 'The injected non-applicable plan is stripped.' );
	}

	public function test_capture_keeps_a_valid_store_api_plan(): void {
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);
		unset( $_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );

		$result = ( new CartPlanHooks() )->add_cart_item_data(
			[ CartPlanHooks::SELLING_PLAN_ID_KEY => $attached_id ],
			$product_id
		);

		$this->assertSame( $attached_id, $result[ CartPlanHooks::SELLING_PLAN_ID_KEY ] );
	}

	public function test_an_array_shaped_plan_id_is_treated_as_one_time_not_fatal(): void {
		$product_id = $this->make_product();
		$this->make_plan();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulating a tampered add-to-cart POST.
		$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = [ '99' ];
		$key  = (string) WC()->cart->add_to_cart( $product_id );
		$item = WC()->cart->get_cart_item( $key );

		$this->assertNotSame( '', $key, 'The add still succeeds as a one-time purchase.' );
		$this->assertArrayNotHasKey( CartPlanHooks::SELLING_PLAN_ID_KEY, $item );
	}

	public function test_the_line_price_reflects_the_recurring_amount(): void {
		$product_id = $this->make_product( '20.00' );
		$plan_id    = (int) $this->make_percentage_plan( 10.0 )->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();

		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( 18.0, (float) $item['data']->get_price(), 'Line price is 10% off $20.' );
	}

	public function test_pricing_is_idempotent_across_recalculations(): void {
		$product_id = $this->make_product( '20.00' );
		$plan_id    = (int) $this->make_percentage_plan( 10.0 )->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();
		WC()->cart->calculate_totals();
		WC()->cart->calculate_totals();

		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( 18.0, (float) $item['data']->get_price(), 'The discount does not compound.' );
	}

	public function test_pricing_reads_the_regular_price_not_the_sale_price(): void {
		$product_id = $this->make_product( '20.00', '12.00' );
		$plan_id    = (int) $this->make_percentage_plan( 10.0 )->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();

		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( 18.0, (float) $item['data']->get_price(), 'Plan discount applies to the regular price, not the sale price.' );
	}

	public function test_line_price_reflects_a_fixed_amount_discount(): void {
		$product_id = $this->make_product( '20.00' );
		$plan       = $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => [
					'policies'      => [
						[
							'type'  => 'fixed_amount',
							'value' => 5.0,
						],
					],
					'one_time_fees' => [],
				],
			]
		);
		$plan_id    = $plan->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();

		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( 15.0, (float) $item['data']->get_price(), '$5 off $20.' );
	}

	public function test_line_price_reflects_a_price_replacement(): void {
		$product_id = $this->make_product( '20.00' );
		$plan       = $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => [
					'policies'      => [
						[
							'type'  => 'price',
							'value' => 15.0,
						],
					],
					'one_time_fees' => [],
				],
			]
		);
		$plan_id    = $plan->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();

		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( 15.0, (float) $item['data']->get_price(), 'Replacement price of $15.' );
	}

	public function test_copy_to_line_item_meta_skips_an_unresolvable_plan(): void {
		// Money-path safety: a plan id that no longer resolves is priced as
		// one-time, so no plan meta is written (no phantom subscription).
		$item = new WC_Order_Item_Product();

		( new CartPlanHooks() )->copy_to_line_item_meta(
			$item,
			'cart-key',
			[ CartPlanHooks::SELLING_PLAN_ID_KEY => 999999 ]
		);

		$this->assertSame( '', $item->get_meta( CartPlanHooks::SELLING_PLAN_ID_KEY ) );
	}

	public function test_get_item_data_adds_a_plan_label_row(): void {
		// Post the name/description drop, a plan's customer-facing label is its
		// cadence read as an adjective.
		$plan      = $this->make_plan( 'month', 1 );
		$cart_item = [ CartPlanHooks::SELLING_PLAN_ID_KEY => (int) $plan->get_id() ];

		$rows = ( new CartPlanHooks() )->get_item_data( [], $cart_item );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Monthly', $rows[0]['value'] );
	}

	public function test_a_line_on_an_archived_plan_still_resolves(): void {
		$plan = $this->make_plan( 'month', 1 );
		$this->set_plan_status( $plan->get_id(), PlanStatus::ARCHIVED );

		$rows = ( new CartPlanHooks() )->get_item_data( [], [ CartPlanHooks::SELLING_PLAN_ID_KEY => $plan->get_id() ] );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Monthly', $rows[0]['value'] );
	}

	public function test_a_line_on_a_plan_without_usable_billing_reads_as_one_time(): void {
		$plan = $this->make_unvalidated_plan( 'month', 1, null, [ 'billing_policy' => null ] );

		$this->assertSame( [], ( new CartPlanHooks() )->get_item_data( [], [ CartPlanHooks::SELLING_PLAN_ID_KEY => $plan->get_id() ] ) );
	}

	public function test_get_item_data_leaves_one_time_lines_untouched(): void {
		$rows = ( new CartPlanHooks() )->get_item_data( [], [] );

		$this->assertSame( [], $rows );
	}

	public function test_cart_item_price_appends_the_cadence(): void {
		$plan      = $this->make_plan( 'month', 1 );
		$cart_item = [ CartPlanHooks::SELLING_PLAN_ID_KEY => (int) $plan->get_id() ];

		$html = ( new CartPlanHooks() )->cart_item_price( '<span>$18.00</span>', $cart_item );

		$this->assertStringContainsString( '<span class="wc-subscriptions-lite-cadence">/ month</span>', $html, 'The cadence is wrapped so it can be styled.' );
	}

	public function test_copy_to_line_item_meta_writes_the_plan_id(): void {
		$plan  = $this->make_plan();
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_order_id( $order->get_id() );

		( new CartPlanHooks() )->copy_to_line_item_meta(
			$item,
			'cart-key',
			[ CartPlanHooks::SELLING_PLAN_ID_KEY => (int) $plan->get_id() ]
		);

		$this->assertSame( (int) $plan->get_id(), (int) $item->get_meta( CartPlanHooks::SELLING_PLAN_ID_KEY ) );
	}

	public function test_copy_to_line_item_meta_skips_one_time_lines(): void {
		$item = new WC_Order_Item_Product();

		( new CartPlanHooks() )->copy_to_line_item_meta( $item, 'cart-key', [] );

		$this->assertSame( '', $item->get_meta( CartPlanHooks::SELLING_PLAN_ID_KEY ) );
	}

	public function test_register_wires_the_default_hooks(): void {
		// The module registers with its real seams (new self()); asserting the
		// hooks are bound verifies the production wiring, not a mocked seam.
		remove_all_filters( 'woocommerce_add_to_cart_validation' );
		remove_all_filters( 'woocommerce_add_cart_item_data' );
		remove_all_actions( 'woocommerce_checkout_create_order_line_item' );

		CartPlanHooks::register();

		$this->assertNotFalse( has_filter( 'woocommerce_add_to_cart_validation' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_add_cart_item_data' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_before_calculate_totals' ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_create_order_line_item' ) );
	}

	public function test_the_written_meta_is_read_back_by_the_contract_handler(): void {
		// The 1774 bridge: a plan id written here is exactly what
		// ContractCreationHandler reads off the order line item.
		$plan  = $this->make_plan();
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_order_id( $order->get_id() );

		( new CartPlanHooks() )->copy_to_line_item_meta(
			$item,
			'cart-key',
			[ CartPlanHooks::SELLING_PLAN_ID_KEY => (int) $plan->get_id() ]
		);

		$this->assertSame(
			(int) $plan->get_id(),
			(int) $item->get_meta( '_wcsl_selling_plan_id' ),
			'The order line item carries the key the contract handler reads.'
		);
	}

	/**
	 * Every error notice, as one string.
	 */
	private static function error_notices(): string {
		return implode( ' ', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * Make a saved product virtual, so checkout needs no shipping.
	 *
	 * @param int $product_id Product id.
	 */
	private function make_virtual( int $product_id ): void {
		$product = wc_get_product( $product_id );
		$product->set_virtual( true );
		$product->save();
	}

	/**
	 * Submit the classic checkout form as its AJAX request does, paying by cash on delivery.
	 *
	 * @return array<string, mixed> The JSON response.
	 */
	private function submit_classic_checkout(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- The test builds the request; checkout verifies its nonce.
		$post_before    = $_POST;
		$request_before = $_REQUEST;
		$_POST          = [
			'payment_method'                     => 'cod',
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		];
		foreach ( self::BILLING_ADDRESS as $field => $value ) {
			$_POST[ 'billing_' . $field ] = $value;
		}
		$_REQUEST = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification

		// wp_send_json() ends the request through wp_die(); an Error escapes checkout's catch.
		$stop = static function (): callable {
			return static function (): void {
				throw new Error( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $stop );
		ob_start();
		try {
			WC()->checkout()->process_checkout();
			$this->fail( 'Checkout must answer with JSON.' );
		} catch ( Error $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $stop );
			$_POST    = $post_before;
			$_REQUEST = $request_before;
		}

		return (array) json_decode( $output, true );
	}

	/**
	 * A product with a plan that applies to it, and the plan id.
	 *
	 * @return array{0: int, 1: int} Product id, plan id.
	 */
	private function make_product_on_plan(): array {
		$product_id = $this->make_product( '20.00' );
		$plan_id    = (int) $this->make_percentage_plan( 10.0 )->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		return [ $product_id, $plan_id ];
	}

	public function test_validation_refuses_the_add_when_the_plans_cannot_be_read(): void {
		[ $product_id, $plan_id ] = $this->make_product_on_plan();

		$_POST[ CartPlanHooks::SELLING_PLAN_ID_KEY ] = (string) $plan_id;
		$this->fail_next_query( self::plan_read() );
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

		$this->assertFalse( $passed );
		$this->assertStringContainsString( 'The subscription could not be added right now.', self::error_notices() );
		$this->assertStringNotContainsString( 'table_that_does_not_exist', self::error_notices(), 'The database error is not shown.' );
	}

	public function test_an_add_whose_plans_cannot_be_read_adds_nothing(): void {
		[ $product_id, $plan_id ] = $this->make_product_on_plan();

		$this->fail_next_query( self::plan_read() );
		$key = $this->add_to_cart( $product_id, $plan_id );

		$this->assertSame( '', $key, 'Not added as a one-time purchase either.' );
		$this->assertSame( [], WC()->cart->get_cart() );
		$this->assertStringContainsString( 'The subscription could not be added right now.', self::error_notices() );
		$this->assertStringNotContainsString( 'table_that_does_not_exist', self::error_notices() );
	}

	public function test_checkout_line_item_creation_stops_when_the_plan_cannot_be_read(): void {
		$plan = $this->make_plan();
		$item = new WC_Order_Item_Product();
		$this->capture_log();

		$this->fail_next_query( self::plan_read() );
		try {
			( new CartPlanHooks() )->copy_to_line_item_meta( $item, 'cart-key', [ CartPlanHooks::SELLING_PLAN_ID_KEY => (int) $plan->get_id() ] );
			$this->fail( 'Checkout must stop.' );
		} catch ( Exception $e ) {
			$this->assertSame( 'Your subscription could not be processed right now. Please try again.', $e->getMessage() );
		}

		$this->assertSame( '', $item->get_meta( CartPlanHooks::SELLING_PLAN_ID_KEY ) );
		$this->assertTrue( $this->has_logged( 'table_that_does_not_exist' ), 'The engine message is logged.' );
	}

	/**
	 * Classic checkout submission calculates the totals before it runs the cart check, which
	 * then refuses it. The cart and checkout page render run the check before their totals.
	 */
	public function test_a_failed_pricing_read_fails_the_cart_check_run_after_the_totals(): void {
		[ $product_id, $plan_id ] = $this->make_product_on_plan();
		$this->add_to_cart( $product_id, $plan_id );
		wc_clear_notices();

		$this->fail_next_query( self::plan_read() );
		WC()->cart->calculate_totals(); // The pricing pass does not throw.

		$this->assertCount( 1, WC()->cart->get_cart() );

		do_action( 'woocommerce_check_cart_items' );

		$this->assertStringContainsString( 'Your subscription could not be processed right now.', self::error_notices() );
	}

	public function test_a_cart_priced_without_a_failure_passes_the_cart_check(): void {
		[ $product_id, $plan_id ] = $this->make_product_on_plan();
		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();
		wc_clear_notices();

		do_action( 'woocommerce_check_cart_items' );

		$this->assertStringNotContainsString( 'could not be processed', self::error_notices() );
	}

	public function test_a_later_pricing_pass_that_reads_the_plan_clears_the_refusal(): void {
		[ $product_id, $plan_id ] = $this->make_product_on_plan();
		$key                      = $this->add_to_cart( $product_id, $plan_id );
		wc_clear_notices();

		$this->fail_next_query( self::plan_read() );
		WC()->cart->calculate_totals();
		WC()->cart->calculate_totals(); // The plan reads again in the same request.

		do_action( 'woocommerce_check_cart_items' );

		$this->assertStringNotContainsString( 'could not be processed', self::error_notices() );
		$this->assertSame( 18.0, (float) WC()->cart->get_cart()[ $key ]['data']->get_price(), 'Priced at the plan price.' );
	}

	public function test_a_failed_pricing_read_flags_only_its_own_line(): void {
		[ $failed_product_id, $failed_plan_id ]   = $this->make_product_on_plan();
		[ $healthy_product_id, $healthy_plan_id ] = $this->make_product_on_plan();
		$failed_key                               = $this->add_to_cart( $failed_product_id, $failed_plan_id );
		$healthy_key                              = $this->add_to_cart( $healthy_product_id, $healthy_plan_id );
		$this->assertSame( [ $failed_key, $healthy_key ], array_keys( WC()->cart->get_cart() ), 'The failing line is priced first.' );

		$this->fail_next_query( self::plan_read() );
		WC()->cart->calculate_totals();
		$cart = WC()->cart->get_cart();

		do_action( 'woocommerce_store_api_validate_cart_item', $cart[ $healthy_key ]['data'], $cart[ $healthy_key ] );
		$this->assertSame( 18.0, (float) $cart[ $healthy_key ]['data']->get_price(), 'The healthy line is priced.' );

		$this->expectException( RouteException::class );
		do_action( 'woocommerce_store_api_validate_cart_item', $cart[ $failed_key ]['data'], $cart[ $failed_key ] );
	}

	public function test_classic_checkout_is_refused_while_a_plan_cannot_be_read_and_goes_through_once_it_can(): void {
		$this->enable_only_gateways( [ 'cod' ] );
		$this->support_engine_charges( 'cod' );
		wp_set_current_user( $this->create_customer() );
		[ $product_id, $plan_id ] = $this->make_product_on_plan();
		$this->make_virtual( $product_id );
		$this->add_to_cart( $product_id, $plan_id );
		wc_clear_notices();
		$created = [];
		add_action(
			'woocommerce_checkout_order_created',
			static function ( $order ) use ( &$created ): void {
				$created[] = $order->get_id();
			}
		);

		$this->fail_next_query( self::plan_read() );
		$refused = $this->submit_classic_checkout();

		$this->assertSame( 'failure', $refused['result'] ?? null, (string) wp_json_encode( $refused ) );
		$this->assertStringContainsString( 'Your subscription could not be processed right now.', (string) ( $refused['messages'] ?? '' ) );
		$this->assertStringNotContainsString( 'table_that_does_not_exist', (string) ( $refused['messages'] ?? '' ) );
		$this->assertSame( [], $created, 'No order is created, so nothing is charged.' );

		wc_clear_notices();
		$accepted = $this->submit_classic_checkout();

		$this->assertSame( 'success', $accepted['result'] ?? null, (string) wp_json_encode( $accepted ) );
		$this->assertCount( 1, $created );
		$order = wc_get_order( $created[0] );
		$this->assertSame( 18.0, (float) $order->get_total(), 'The order carries the plan price.' );

		// Checkout saved the session, which the test's rollback undoes: start the next test afresh.
		WC()->session->forget_session();
	}

	public function test_a_failed_pricing_read_refuses_store_api_checkout(): void {
		$this->enable_only_gateways( [ 'cod' ] );
		$this->support_engine_charges( 'cod' );
		wp_set_current_user( $this->create_customer() );
		[ $product_id, $plan_id ] = $this->make_product_on_plan();
		$this->add_to_cart( $product_id, $plan_id );
		$address = self::BILLING_ADDRESS + [
			'company'   => '',
			'address_2' => '',
			'state'     => '',
		];

		$this->fail_next_query( self::plan_read() );
		$response = $this->dispatch_store_api(
			'POST',
			'/checkout',
			[
				'billing_address'  => (object) $address,
				'shipping_address' => (object) array_diff_key( $address, [ 'email' => true ] ),
				'payment_method'   => 'cod',
			]
		);

		$this->assertSame( 409, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( 'woocommerce_subscriptions_lite_plan_read_failed', (string) wp_json_encode( $response->get_data() ), 'Refused by the cart item validation.' );
		$this->assertStringContainsString( 'Your subscription could not be processed right now.', (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'table_that_does_not_exist', (string) wp_json_encode( $response->get_data() ) );
	}
}
