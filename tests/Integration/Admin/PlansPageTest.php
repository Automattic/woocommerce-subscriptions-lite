<?php
/**
 * Integration tests for the plans admin page.
 *
 * Runs against the real admin registration APIs: the submenu lands in the real
 * $submenu global, assets enqueue through the real WP_Scripts/WP_Styles, and
 * the capability gate rides the real current user.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\PlansPage;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\PlansPage
 */
final class PlansPageTest extends LiteIntegrationTestCase {

	private const SCRIPT_HANDLE = 'wc-subscriptions-lite-admin-react';

	public function set_up(): void {
		parent::set_up();
		// add_submenu_page() lives in the admin includes.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_register_menu_adds_woocommerce_submenu(): void {
		global $submenu;

		( new PlansPage() )->register_menu();

		$slugs = array_column( $submenu['woocommerce'] ?? [], 2 );
		$this->assertContains( PlansPage::MENU_SLUG, $slugs, 'The plans page registers under the WooCommerce menu.' );
	}

	public function test_enqueue_assets_only_runs_on_the_registered_hook_suffix(): void {
		$page = new PlansPage();
		$page->register_menu();

		$page->enqueue_assets( 'dashboard_page_elsewhere' );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ), 'Foreign screens get no plans assets.' );

		// The real suffix add_submenu_page() registered for this page.
		$page->enqueue_assets( get_plugin_page_hookname( PlansPage::MENU_SLUG, 'woocommerce' ) );

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

		( new PlansPage() )->render();
	}

	public function test_render_outputs_react_mount_for_capable_user(): void {
		ob_start();
		( new PlansPage() )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wc-subscriptions-lite-plan-manager', $output );
	}
}
