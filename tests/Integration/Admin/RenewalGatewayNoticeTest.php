<?php
/**
 * Integration tests for the no-renewal-gateway admin notice.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RenewalGatewayNotice;
use Automattic\WooCommerce\SubscriptionsLite\Payments\RenewalGateways;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\RenewalGatewayNotice
 */
final class RenewalGatewayNoticeTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$this->support_engine_charges( 'cod' );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Publish a simple product that sells on every Lite plan.
	 */
	private function publish_product_on_plans(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee beans' );
		$product->set_regular_price( '24.00' );
		$product_id = (int) $product->save();

		( new ApplicabilityStore() )->set( $product_id, new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
	}

	/**
	 * Run the notice's admin_notices callback and return what it prints.
	 */
	private function render(): string {
		ob_start();
		( new RenewalGatewayNotice() )->render();

		return (string) ob_get_clean();
	}

	public function test_register_prints_the_warning_on_admin_notices(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$this->publish_product_on_plans();
		remove_all_actions( 'admin_notices' );

		RenewalGatewayNotice::register();
		ob_start();
		do_action( 'admin_notices' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
	}

	public function test_a_store_selling_on_plans_without_a_renewal_gateway_is_warned(): void {
		$this->enable_only_gateways( [ 'bacs', 'cheque' ] );
		$this->publish_product_on_plans();

		$output = $this->render();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringNotContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( esc_html__( 'Customers can\'t buy subscriptions.', 'woocommerce-subscriptions-lite' ), $output );
		// wp_admin_notice() runs the message through wp_kses_post().
		$this->assertStringContainsString( wp_kses_post( RenewalGateways::get_setup_message() ), $output );
	}

	public function test_it_shows_on_the_plugins_screen_and_lite_screen(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$this->publish_product_on_plans();

		foreach ( [ 'plugins', 'woocommerce_page_' . PageController::PAGE_SLUG, 'edit-product' ] as $screen_id ) {
			set_current_screen( $screen_id );
			$this->assertStringContainsString( 'notice-warning', $this->render(), $screen_id );
		}
	}

	public function test_it_does_not_show_on_other_admin_screens(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$this->publish_product_on_plans();

		foreach ( [ 'dashboard', 'edit-post', 'options-general' ] as $screen_id ) {
			set_current_screen( $screen_id );
			$this->assertSame( '', $this->render(), $screen_id );
		}
	}

	public function test_it_does_not_show_without_a_screen(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$this->publish_product_on_plans();
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- No screen set yet; tear_down() sets one again.

		$this->assertSame( '', $this->render() );
	}

	public function test_users_who_cannot_manage_woocommerce_do_not_see_it(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$this->publish_product_on_plans();

		foreach ( [ 'editor', 'customer' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertSame( '', $this->render(), $role );
		}
	}

	public function test_a_shop_manager_sees_it(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$this->publish_product_on_plans();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertStringContainsString( 'notice-warning', $this->render() );
	}

	public function test_with_gateways_loaded_an_enabled_renewal_gateway_hides_it_without_a_query_of_its_own(): void {
		global $wpdb;
		$this->enable_only_gateways( [ 'bacs', 'cod' ] );
		$this->publish_product_on_plans();
		// WooCommerce builds its screen ids (attribute taxonomies, cached) earlier on its own screens.
		wc_get_screen_ids();

		$queries_before = $wpdb->num_queries;
		$output         = $this->render();

		$this->assertSame( '', $output );
		$this->assertSame( $queries_before, $wpdb->num_queries, 'With the gateways loaded, the notice runs no query of its own.' );
	}

	public function test_a_store_not_selling_on_plans_is_not_warned(): void {
		$this->enable_only_gateways( [ 'bacs' ] );
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->save();

		$this->assertSame( '', $this->render() );
	}
}
