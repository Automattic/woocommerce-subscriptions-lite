<?php
/**
 * Integration tests for the package bootstrap.
 *
 * The plugin booted through its real plugin file during the suite bootstrap,
 * so these assert against the REAL hook table: the feature modules' hooks are
 * registered, the admin module stays out of a front-end request, and the
 * one-shot guard holds on a second init.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration;

use Automattic\WooCommerce\SubscriptionsLite\Admin\ProductPlansPanel;
use Automattic\WooCommerce\SubscriptionsLite\Bootstrap;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanPicker;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\VariationPlanData;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Bootstrap
 */
final class BootstrapTest extends LiteIntegrationTestCase {

	public function test_the_checkout_module_is_wired(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_changed' ),
			'Contract creation is bound to the order reaching a paid status.'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_checkout_registration_required' ),
			'Subscription carts require a customer account.'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_thankyou' ),
			'The order-received subscription summary is wired.'
		);
	}

	public function test_the_customer_portal_is_wired(): void {
		$this->assertNotFalse( has_filter( 'woocommerce_account_menu_items' ) );
		$this->assertNotFalse( has_action( 'woocommerce_account_' . Endpoints::LIST_ENDPOINT . '_endpoint' ) );
		$this->assertNotFalse( has_action( 'woocommerce_account_' . Endpoints::DETAIL_ENDPOINT . '_endpoint' ) );
	}

	public function test_the_admin_module_stays_out_of_a_front_end_request(): void {
		$this->assertFalse( is_admin(), 'The suite runs as a front-end request.' );
		$this->assertFalse(
			has_action( 'admin_post_woocommerce_subscriptions_lite_renew_now' ),
			'The admin action handlers are not registered outside wp-admin.'
		);
	}

	public function test_the_product_page_modules_are_wired(): void {
		$this->assertTrue(
			$this->hook_has_callback_on( 'woocommerce_before_add_to_cart_button', PlanPicker::class ),
			'The PDP picker renders on the add-to-cart form hook.'
		);
		$this->assertTrue(
			$this->hook_has_callback_on( 'wp_enqueue_scripts', PlanPicker::class ),
			'The picker enqueues its view module and stylesheet.'
		);
		$this->assertTrue(
			$this->hook_has_callback_on( 'woocommerce_available_variation', VariationPlanData::class ),
			'Variation payloads carry the per-plan option HTML.'
		);
	}

	public function test_the_product_plans_panel_stays_out_of_a_front_end_request(): void {
		$this->assertFalse(
			$this->hook_has_callback_on( 'woocommerce_product_data_tabs', ProductPlansPanel::class ),
			'The product-data panel registers in wp-admin only.'
		);
	}

	/**
	 * Whether any callback of the given class is bound to the hook.
	 *
	 * The product-page hooks are generic WooCommerce hooks other code may bind
	 * to, so the assertions match on the callback's owning class instead of
	 * plain has_action()/has_filter().
	 *
	 * @param string $hook  Hook name to scan.
	 * @param string $class Class the callback instance must be of.
	 */
	private function hook_has_callback_on( string $hook, string $class ): bool {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof $class ) {
					return true;
				}
			}
		}

		return false;
	}

	public function test_init_is_idempotent(): void {
		global $wp_filter;

		$before = count( $wp_filter['woocommerce_account_menu_items']->callbacks, COUNT_RECURSIVE );

		Bootstrap::init();

		$after = count( $wp_filter['woocommerce_account_menu_items']->callbacks, COUNT_RECURSIVE );
		$this->assertSame( $before, $after, 'A second init() does not re-register hooks.' );
	}
}
