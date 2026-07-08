<?php
/**
 * Integration tests for the Items meta box.
 *
 * Line items originate from a real order run through the checkout path, so the
 * item rows the box renders are shaped exactly like production data.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Items;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Items
 */
final class ItemsTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		// The detail screen is manage_woocommerce-gated, so a merchant viewing it
		// can edit products; get_edit_post_link() only returns a URL for such a user.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_output_lists_line_items_with_price_quantity_and_total(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract(
			$customer,
			[
				'product_name' => 'Monthly Coffee Box',
				'price'        => '19.99',
				'quantity'     => 2,
			]
		);

		$html = $this->render( $id );

		$this->assertStringContainsString( 'wc-subs-lite-items-table', $html );
		$this->assertStringContainsString( 'Monthly Coffee Box', $html );
		// The Price column carries the per-unit price (19.99), separate from the
		// line total (2 x 19.99 = 39.98).
		$this->assertStringContainsString( 'wc-subs-lite-col-price', $html );
		$this->assertStringContainsString( '19.99', $html );
		$this->assertStringContainsString( '39.98', $html );
	}

	public function test_output_links_item_to_its_product(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract( $customer, [ 'product_name' => 'Linked Product' ] );

		$html = $this->render( $id );

		// The item name is wrapped in an edit link when the product resolves.
		$this->assertStringContainsString( 'Linked Product</a>', $html );
	}

	/**
	 * Capture the meta box output for a seeded contract.
	 *
	 * @param int $id Contract id.
	 */
	private function render( int $id ): string {
		$contract = Subscriptions::get( $id );
		$this->assertNotNull( $contract );

		ob_start();
		Items::output( $contract );
		return (string) ob_get_clean();
	}
}
