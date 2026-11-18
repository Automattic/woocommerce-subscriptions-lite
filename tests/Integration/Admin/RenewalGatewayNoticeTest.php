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
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\RenewalGatewayNotice
 */
final class RenewalGatewayNoticeTest extends LiteIntegrationTestCase {

	/**
	 * The `enabled` value of every registered gateway before the test.
	 *
	 * @var array<string, string>
	 */
	private $gateways_enabled_before = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$this->gateways_enabled_before[ $gateway_id ] = $gateway->enabled;
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$this->support_engine_charges( 'cod' );
	}

	public function tear_down(): void {
		foreach ( $this->gateways_enabled_before as $gateway_id => $enabled ) {
			WC()->payment_gateways()->payment_gateways()[ $gateway_id ]->enabled = $enabled;
		}
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Enable exactly the given gateways and disable every other one. `cod` can renew.
	 *
	 * @param string[] $gateway_ids Gateway ids to enable.
	 */
	private function enable_only( array $gateway_ids ): void {
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$gateway->enabled = in_array( $gateway_id, $gateway_ids, true ) ? 'yes' : 'no';
		}
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

	public function test_register_hooks_admin_notices(): void {
		RenewalGatewayNotice::register();

		$this->assertTrue( has_action( 'admin_notices' ) );
	}

	public function test_a_store_selling_on_plans_without_a_renewal_gateway_is_warned(): void {
		$this->enable_only( [ 'bacs', 'cheque' ] );
		$this->publish_product_on_plans();

		$output = $this->render();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringNotContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'Customers can&#039;t buy subscriptions.', $output );
		$this->assertStringContainsString( 'charge subscription renewals automatically', $output );
		// wp_admin_notice() runs wp_kses_post(), which normalises the escaped ampersand.
		$this->assertStringContainsString( 'href="' . admin_url( 'admin.php?page=wc-settings&amp;tab=checkout' ) . '"', $output );
	}

	public function test_it_shows_on_the_plugins_screen_and_lite_screen(): void {
		$this->enable_only( [ 'bacs' ] );
		$this->publish_product_on_plans();

		foreach ( [ 'plugins', 'woocommerce_page_' . PageController::PAGE_SLUG, 'edit-product' ] as $screen_id ) {
			set_current_screen( $screen_id );
			$this->assertStringContainsString( 'notice-warning', $this->render(), $screen_id );
		}
	}

	public function test_it_does_not_show_on_other_admin_screens(): void {
		$this->enable_only( [ 'bacs' ] );
		$this->publish_product_on_plans();

		foreach ( [ 'dashboard', 'edit-post', 'options-general' ] as $screen_id ) {
			set_current_screen( $screen_id );
			$this->assertSame( '', $this->render(), $screen_id );
		}
	}

	public function test_it_does_not_show_without_a_screen(): void {
		$this->enable_only( [ 'bacs' ] );
		$this->publish_product_on_plans();
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- No screen set yet; tear_down() sets one again.

		$this->assertSame( '', $this->render() );
	}

	public function test_users_who_cannot_manage_woocommerce_do_not_see_it(): void {
		$this->enable_only( [ 'bacs' ] );
		$this->publish_product_on_plans();

		foreach ( [ 'editor', 'customer' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertSame( '', $this->render(), $role );
		}
	}

	public function test_a_shop_manager_sees_it(): void {
		$this->enable_only( [ 'bacs' ] );
		$this->publish_product_on_plans();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->assertStringContainsString( 'notice-warning', $this->render() );
	}

	public function test_an_enabled_renewal_gateway_hides_it_without_a_query(): void {
		global $wpdb;
		$this->enable_only( [ 'bacs', 'cod' ] );
		$this->publish_product_on_plans();
		// WooCommerce builds its screen ids (attribute taxonomies, cached) earlier on its own screens.
		wc_get_screen_ids();

		$queries_before = $wpdb->num_queries;
		$output         = $this->render();

		$this->assertSame( '', $output );
		$this->assertSame( $queries_before, $wpdb->num_queries, 'A store with a renewal gateway runs no query.' );
	}

	public function test_a_store_not_selling_on_plans_is_not_warned(): void {
		$this->enable_only( [ 'bacs' ] );
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->save();

		$this->assertSame( '', $this->render() );
	}
}
