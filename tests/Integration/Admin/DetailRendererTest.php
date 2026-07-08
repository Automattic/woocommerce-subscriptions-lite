<?php
/**
 * Integration tests for the detail-screen meta-box registration.
 *
 * Asserts the screen registers the section boxes in the order-edit arrangement:
 * data -> items -> billing history in the main column, actions + customer on the
 * side.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\DetailRenderer;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\DetailRenderer
 */
final class DetailRendererTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function test_setup_registers_boxes_like_the_order_edit_screen(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract( $customer );

		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );
		DetailRenderer::setup( $id );

		$screen = get_current_screen();
		$this->assertNotNull( $screen );

		global $wp_meta_boxes;
		$boxes = $wp_meta_boxes[ $screen->id ] ?? [];

		$normal_high = array_keys( $boxes['normal']['high'] ?? [] );
		$this->assertContains( 'wc-subs-lite-data', $normal_high );
		$this->assertContains( 'wc-subs-lite-items', $normal_high );
		$this->assertLessThan(
			array_search( 'wc-subs-lite-items', $normal_high, true ),
			array_search( 'wc-subs-lite-data', $normal_high, true ),
			'Subscription data box should register before the items box so the main column reads data -> items.'
		);

		$this->assertArrayHasKey( 'wc-subs-lite-history', $boxes['normal']['default'] ?? [] );
		$this->assertArrayHasKey( 'wc-subs-lite-actions', $boxes['side']['high'] ?? [] );
		$this->assertArrayHasKey( 'wc-subs-lite-customer', $boxes['side']['default'] ?? [] );
	}
}
