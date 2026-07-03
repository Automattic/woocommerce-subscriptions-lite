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

	private const SCRIPT_HANDLE = 'wc-subscriptions-lite-admin-react';

	public function setUp(): void {
		parent::setUp();
		$GLOBALS['woocommerce_subscriptions_lite_test_submenus']               = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts']       = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles']        = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_inline_scripts']         = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_script_translations']    = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_style_data']             = [];
		$GLOBALS['woocommerce_subscriptions_lite_test_can_manage_woocommerce'] = false;
	}

	public function tearDown(): void {
		unset( $_GET['tab'], $GLOBALS['hide_save_button'] );
		parent::tearDown();
	}

	public function test_add_settings_tab_registers_subscriptions_tab(): void {
		$page = new PlansPage();

		$tabs = $page->add_settings_tab( [ 'general' => 'General' ] );

		$this->assertArrayHasKey( PlansPage::TAB_SLUG, $tabs );
		$this->assertSame( 'subscriptions', PlansPage::TAB_SLUG );
		$this->assertArrayHasKey( 'general', $tabs );
	}

	public function test_enqueue_assets_only_runs_on_subscriptions_settings_tab(): void {
		$page = new PlansPage();

		// Wrong screen entirely.
		$_GET['tab'] = PlansPage::TAB_SLUG;
		$page->enqueue_assets( 'dashboard_page_elsewhere' );
		$this->assertSame( [], $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts'] );

		// Right screen, wrong tab.
		$_GET['tab'] = 'general';
		$page->enqueue_assets( 'woocommerce_page_wc-settings' );
		$this->assertSame( [], $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts'] );

		// Right screen, right tab.
		$_GET['tab'] = PlansPage::TAB_SLUG;
		$page->enqueue_assets( 'woocommerce_page_wc-settings' );

		$this->assertArrayHasKey( self::SCRIPT_HANDLE, $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts'] );
		$this->assertSame(
			'woocommerce-subscriptions-lite',
			$GLOBALS['woocommerce_subscriptions_lite_test_script_translations'][ self::SCRIPT_HANDLE ]['domain']
		);
		$this->assertStringEndsWith(
			'/languages',
			$GLOBALS['woocommerce_subscriptions_lite_test_script_translations'][ self::SCRIPT_HANDLE ]['path']
		);
		$this->assertArrayHasKey( 'wp-components', $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles'] );
		$this->assertArrayHasKey( 'wp-dataviews', $GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles'] );
		$inline_scripts = $GLOBALS['woocommerce_subscriptions_lite_test_inline_scripts'][ self::SCRIPT_HANDLE ];
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
		$this->assertSame( 'day', $config['definitions']['billing_units'][0]['value'] );
		$this->assertSame( 'day', $config['definitions']['billing_units'][0]['singular'] );
		$this->assertSame( 'days', $config['definitions']['billing_units'][0]['plural'] );
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

		// Two-column form-table layout: description in the left label cell,
		// React table mount in the right control cell.
		$this->assertStringContainsString( 'class="form-table"', $output );
		$this->assertStringContainsString( 'wc-subscriptions-lite-settings-tab', $output );
		$this->assertStringContainsString( 'titledesc', $output );
		$this->assertStringContainsString( 'Storewide subscription plans', $output );
		$this->assertStringContainsString( 'Create a set of subscription plans', $output );

		// The tab has no form settings, so the default Save button is hidden.
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}
}
