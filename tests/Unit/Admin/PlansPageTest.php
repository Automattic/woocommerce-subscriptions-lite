<?php
/**
 * Unit tests for the plans admin page.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\PlansPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\PlansPage
 */
final class PlansPageTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		$GLOBALS['woocommerce_subscriptions_lite_test_submenus']               = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts']       = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles']        = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_inline_scripts']         = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_can_manage_woocommerce'] = false;
	}

	public function test_register_menu_adds_woocommerce_submenu(): void {
		$page = new PlansPage();

		$page->register_menu();

		$this->assertCount( 1, $GLOBALS['woocommerce_subscriptions_lite_test_submenus'] );
		$submenu = $GLOBALS['woocommerce_subscriptions_lite_test_submenus'][0];
		$this->assertSame( 'woocommerce', $submenu['parent_slug'] );
		$this->assertSame( PlansPage::CAPABILITY, $submenu['capability'] );
		$this->assertSame( PlansPage::MENU_SLUG, $submenu['menu_slug'] );
	}

	public function test_enqueue_assets_only_runs_on_registered_hook_suffix(): void {
		$page = new PlansPage();
		$page->register_menu();

		$page->enqueue_assets( 'dashboard_page_elsewhere' );
		$this->assertSame( [], $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts'] );

		$page->enqueue_assets( 'woocommerce_page_' . PlansPage::MENU_SLUG );

		$this->assertArrayHasKey( 'wc-subscriptions-lite-admin', $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts'] );
		$this->assertArrayHasKey( 'wp-components', $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles'] );
		$this->assertArrayHasKey( 'wp-dataviews', $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles'] );
		$inline_scripts = $GLOBALS['woocommerce_subscriptions_lite_test_inline_scripts']['wc-subscriptions-lite-admin'];
		$inline_script  = $inline_scripts[0]['data'];
		$config         = json_decode(
			rtrim( str_replace( 'window.wcSubscriptionsLitePlans = ', '', $inline_script ), ';' ),
			true
		);

		$this->assertIsArray( $config );
		$this->assertSame(
			'/wc/v3/subscriptions-engine/plans',
			$config['restBase']
		);
		$this->assertSame(
			'woocommerce-subscriptions-lite',
			$config['extensionSlug']
		);
		$this->assertSame(
			'active',
			$config['defaultStatus']
		);
		$this->assertIsArray( $config['definitions'] );
		$this->assertArrayHasKey( 'statuses', $config['definitions'] );
		$this->assertIsArray( $config['definitions']['statuses'] );
		$this->assertArrayHasKey( 'billing_units', $config['definitions'] );
		$this->assertIsArray( $config['definitions']['billing_units'] );
		$this->assertArrayHasKey( 'pricing_types', $config['definitions'] );
		$this->assertIsArray( $config['definitions']['pricing_types'] );
		$this->assertArrayHasKey( 'pricing_scopes', $config['definitions'] );
		$this->assertIsArray( $config['definitions']['pricing_scopes'] );
	}

	public function test_render_requires_manage_woocommerce(): void {
		$this->expectException( RuntimeException::class );

		( new PlansPage() )->render();
	}

	public function test_render_outputs_react_mount_for_capable_user(): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_can_manage_woocommerce'] = true;

		ob_start();
		( new PlansPage() )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wc-subscriptions-lite-plan-manager', $output );
	}
}
