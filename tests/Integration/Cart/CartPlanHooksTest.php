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

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\PricingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
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
		unset( $_POST[ CartPlanHooks::CART_ITEM_KEY ] );
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
	private function make_percentage_plan( float $percent ): Plan {
		return $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => new PricingPolicy(
					[
						[
							'type'  => 'percentage',
							'value' => $percent,
						],
					],
					[]
				),
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
			unset( $_POST[ CartPlanHooks::CART_ITEM_KEY ] );
		} else {
			$_POST[ CartPlanHooks::CART_ITEM_KEY ] = (string) $plan_id;
		}

		return (string) WC()->cart->add_to_cart( $product_id );
	}

	public function test_add_to_cart_with_a_valid_plan_captures_the_plan_id(): void {
		$product_id = $this->make_product();
		$plan_id    = (int) $this->make_plan()->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$key  = $this->add_to_cart( $product_id, $plan_id );
		$item = WC()->cart->get_cart_item( $key );

		$this->assertSame( $plan_id, $item[ CartPlanHooks::CART_ITEM_KEY ] );
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

		$this->assertArrayNotHasKey( CartPlanHooks::CART_ITEM_KEY, $item );
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

		$_POST[ CartPlanHooks::CART_ITEM_KEY ] = (string) $other_id;
		$passed                                = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

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

		$_POST[ CartPlanHooks::CART_ITEM_KEY ] = (string) $attached_id;
		$passed                                = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

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
		$this->assertArrayNotHasKey( CartPlanHooks::CART_ITEM_KEY, $item, 'The non-applicable plan is not attached.' );
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
		unset( $_POST[ CartPlanHooks::CART_ITEM_KEY ] );

		$passed = apply_filters(
			'woocommerce_add_to_cart_validation',
			true,
			$product_id,
			1,
			0,
			[],
			[ CartPlanHooks::CART_ITEM_KEY => $other_id ]
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
		unset( $_POST[ CartPlanHooks::CART_ITEM_KEY ] );

		$result = ( new CartPlanHooks() )->add_cart_item_data(
			[ CartPlanHooks::CART_ITEM_KEY => $other_id ],
			$product_id
		);

		$this->assertArrayNotHasKey( CartPlanHooks::CART_ITEM_KEY, $result, 'The injected non-applicable plan is stripped.' );
	}

	public function test_capture_keeps_a_valid_store_api_plan(): void {
		$product_id  = $this->make_product();
		$attached_id = (int) $this->make_plan( 'month' )->get_id();
		( new ApplicabilityStore() )->set(
			$product_id,
			new ProductApplicability( ProductApplicability::MODE_INHERIT_SELECT, [ $attached_id ] )
		);
		unset( $_POST[ CartPlanHooks::CART_ITEM_KEY ] );

		$result = ( new CartPlanHooks() )->add_cart_item_data(
			[ CartPlanHooks::CART_ITEM_KEY => $attached_id ],
			$product_id
		);

		$this->assertSame( $attached_id, $result[ CartPlanHooks::CART_ITEM_KEY ] );
	}

	public function test_an_array_shaped_plan_id_is_treated_as_one_time_not_fatal(): void {
		$product_id = $this->make_product();
		$this->make_plan();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulating a tampered add-to-cart POST.
		$_POST[ CartPlanHooks::CART_ITEM_KEY ] = [ '99' ];
		$key                                   = (string) WC()->cart->add_to_cart( $product_id );
		$item                                  = WC()->cart->get_cart_item( $key );

		$this->assertNotSame( '', $key, 'The add still succeeds as a one-time purchase.' );
		$this->assertArrayNotHasKey( CartPlanHooks::CART_ITEM_KEY, $item );
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
		$plan_id    = (int) $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => new PricingPolicy(
					[
						[
							'type'  => 'fixed_amount',
							'value' => 5.0,
						],
					],
					[]
				),
			]
		)->get_id();
		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );

		$this->add_to_cart( $product_id, $plan_id );
		WC()->cart->calculate_totals();

		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( 15.0, (float) $item['data']->get_price(), '$5 off $20.' );
	}

	public function test_line_price_reflects_a_price_replacement(): void {
		$product_id = $this->make_product( '20.00' );
		$plan_id    = (int) $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => new PricingPolicy(
					[
						[
							'type'  => 'price',
							'value' => 15.0,
						],
					],
					[]
				),
			]
		)->get_id();
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
			[ CartPlanHooks::CART_ITEM_KEY => 999999 ]
		);

		$this->assertSame( '', $item->get_meta( CartPlanHooks::CART_ITEM_KEY ) );
	}

	public function test_get_item_data_adds_a_plan_label_row(): void {
		// Post the name/description drop, a plan's customer-facing label is its
		// cadence read as an adjective.
		$plan      = $this->make_plan( 'month', 1 );
		$cart_item = [ CartPlanHooks::CART_ITEM_KEY => (int) $plan->get_id() ];

		$rows = ( new CartPlanHooks() )->get_item_data( [], $cart_item );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Monthly', $rows[0]['value'] );
	}

	public function test_get_item_data_leaves_one_time_lines_untouched(): void {
		$rows = ( new CartPlanHooks() )->get_item_data( [], [] );

		$this->assertSame( [], $rows );
	}

	public function test_cart_item_price_appends_the_cadence(): void {
		$plan      = $this->make_plan( 'month', 1 );
		$cart_item = [ CartPlanHooks::CART_ITEM_KEY => (int) $plan->get_id() ];

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
			[ CartPlanHooks::CART_ITEM_KEY => (int) $plan->get_id() ]
		);

		$this->assertSame( (int) $plan->get_id(), (int) $item->get_meta( CartPlanHooks::CART_ITEM_KEY ) );
	}

	public function test_copy_to_line_item_meta_skips_one_time_lines(): void {
		$item = new WC_Order_Item_Product();

		( new CartPlanHooks() )->copy_to_line_item_meta( $item, 'cart-key', [] );

		$this->assertSame( '', $item->get_meta( CartPlanHooks::CART_ITEM_KEY ) );
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
			[ CartPlanHooks::CART_ITEM_KEY => (int) $plan->get_id() ]
		);

		$this->assertSame(
			(int) $plan->get_id(),
			(int) $item->get_meta( '_selling_plan_id' ),
			'The order line item carries the key the contract handler reads.'
		);
	}
}
