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

use Automattic\WooCommerce\SubscriptionsLite\Bootstrap;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Bootstrap
 */
final class BootstrapTest extends LiteIntegrationTestCase {

	public function test_the_checkout_module_is_wired(): void {
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_processed' ) );
	}

	public function test_the_customer_portal_is_wired(): void {
		$this->assertNotFalse( has_filter( 'woocommerce_account_menu_items' ) );
		$this->assertNotFalse( has_action( 'woocommerce_account_' . Endpoints::LIST_ENDPOINT . '_endpoint' ) );
		$this->assertNotFalse( has_action( 'woocommerce_account_' . Endpoints::DETAIL_ENDPOINT . '_endpoint' ) );
	}

	public function test_the_admin_module_stays_out_of_a_front_end_request(): void {
		$this->assertFalse( is_admin(), 'The suite runs as a front-end request.' );
		$this->assertFalse(
			has_action( 'admin_post_wc_subscriptions_lite_renew_now' ),
			'The admin action handlers are not registered outside wp-admin.'
		);
	}

	public function test_init_is_idempotent(): void {
		global $wp_filter;

		$before = count( $wp_filter['woocommerce_account_menu_items']->callbacks, COUNT_RECURSIVE );

		Bootstrap::init();

		$after = count( $wp_filter['woocommerce_account_menu_items']->callbacks, COUNT_RECURSIVE );
		$this->assertSame( $before, $after, 'A second init() does not re-register hooks.' );
	}
}
