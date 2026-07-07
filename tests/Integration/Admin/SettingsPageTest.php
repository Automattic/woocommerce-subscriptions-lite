<?php
/**
 * Integration tests for the plans manager settings tab.
 *
 * Runs against the real admin registration APIs: the tab lands in the real
 * WooCommerce settings tabs filter, assets enqueue through the real
 * WP_Scripts/WP_Styles, and the capability gate rides the real current user.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\SettingsPage;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\SettingsPage
 */
final class SettingsPageTest extends LiteIntegrationTestCase {

	private const SCRIPT_HANDLE = 'wc-subscriptions-lite-admin-react';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		unset( $_GET['tab'], $GLOBALS['hide_save_button'] );
		parent::tear_down();
	}

	public function test_register_adds_subscriptions_settings_tab(): void {
		SettingsPage::register();

		$tabs = apply_filters( 'woocommerce_settings_tabs_array', [ 'general' => 'General' ] );

		$this->assertArrayHasKey( SettingsPage::TAB_SLUG, $tabs, 'The Subscriptions tab registers among the WooCommerce settings tabs.' );
		$this->assertSame( 'subscriptions', SettingsPage::TAB_SLUG );
		$this->assertArrayHasKey( 'general', $tabs, 'Existing tabs survive the filter.' );

		$this->assertNotFalse(
			has_action( 'woocommerce_settings_' . SettingsPage::TAB_SLUG ),
			'The tab body renders through the WooCommerce settings action.'
		);
	}

	public function test_enqueue_assets_only_runs_on_the_subscriptions_settings_tab(): void {
		$page = new SettingsPage();

		// Wrong screen entirely.
		$_GET['tab'] = SettingsPage::TAB_SLUG;
		$page->enqueue_assets( 'dashboard_page_elsewhere' );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ), 'Foreign screens get no plans assets.' );

		// Right screen, wrong tab.
		$_GET['tab'] = 'general';
		$page->enqueue_assets( 'woocommerce_page_wc-settings' );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ), 'Other settings tabs get no plans assets.' );

		// Right screen, right tab.
		$_GET['tab'] = SettingsPage::TAB_SLUG;
		$page->enqueue_assets( 'woocommerce_page_wc-settings' );

		$this->assertTrue( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ) );

		// The inline config seeds the React app with the engine REST base and
		// the extension slug.
		$inline = wp_scripts()->get_data( self::SCRIPT_HANDLE, 'before' );
		$config = null;
		foreach ( (array) $inline as $chunk ) {
			if ( is_string( $chunk ) && false !== strpos( $chunk, 'wcSubscriptionsLitePlans' ) ) {
				$config = json_decode( rtrim( str_replace( 'window.wcSubscriptionsLitePlans = ', '', $chunk ), ';' ), true );
			}
		}
		$this->assertIsArray( $config, 'The inline plans config is attached to the script.' );
		$this->assertSame( '/wc/v3/subscriptions-engine/plans', $config['restBase'] );
		$this->assertSame( 'woocommerce-subscriptions-lite', $config['extensionSlug'] );
		$this->assertSame( 'day', $config['definitions']['billing_units'][0]['value'] );
	}

	public function test_render_requires_manage_woocommerce(): void {
		wp_set_current_user( $this->create_customer() );

		$this->expectException( \WPDieException::class );

		( new SettingsPage() )->render();
	}

	public function test_render_outputs_react_mount_for_capable_user(): void {
		ob_start();
		( new SettingsPage() )->render();
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
