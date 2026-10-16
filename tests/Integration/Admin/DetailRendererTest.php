<?php
/**
 * Integration tests for the detail-screen meta-box registration.
 *
 * Asserts the screen registers the section boxes in the order-edit arrangement:
 * details -> addresses -> items -> billing history in the main column, actions +
 * schedule + customer on the side.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\DetailRenderer;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
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
		$this->assertContains( 'wc-subs-lite-addresses', $normal_high );

		$data_pos      = array_search( 'wc-subs-lite-data', $normal_high, true );
		$items_pos     = array_search( 'wc-subs-lite-items', $normal_high, true );
		$addresses_pos = array_search( 'wc-subs-lite-addresses', $normal_high, true );
		$this->assertLessThan( $addresses_pos, $data_pos, 'Details register before addresses.' );
		$this->assertLessThan( $items_pos, $addresses_pos, 'Addresses register before items, so the column reads details -> addresses -> items.' );

		$this->assertArrayHasKey( 'wc-subs-lite-history', $boxes['normal']['default'] ?? [] );
		$this->assertArrayHasKey( 'wc-subs-lite-actions', $boxes['side']['high'] ?? [] );
		$this->assertArrayHasKey( 'wc-subs-lite-schedule', $boxes['side']['default'] ?? [] );
		$this->assertArrayHasKey( 'wc-subs-lite-customer', $boxes['side']['default'] ?? [] );
	}

	public function test_locked_contract_renders_the_in_progress_notice(): void {
		$id   = $this->create_contract( $this->create_customer() );
		$lock = $this->hold_lock( $id, RenewalProcessor::OPERATION );

		try {
			$html = $this->render_detail( $id );
		} finally {
			$lock->release();
		}

		$this->assertStringContainsString( 'wc-subs-lite-lock-notice', $html );
		$this->assertStringContainsString( sprintf( 'Another change to subscription #%d is in progress (renewal). Try again after ', $id ), $html );
	}

	public function test_unlocked_contract_renders_no_in_progress_notice(): void {
		$id = $this->create_contract( $this->create_customer() );

		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'Subscription #' . $id, $html );
		$this->assertStringNotContainsString( 'wc-subs-lite-lock-notice', $html );
	}

	/**
	 * Render the detail screen for a contract as an administrator.
	 *
	 * @param int $id Contract id.
	 */
	private function render_detail( int $id ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );

		ob_start();
		DetailRenderer::render( $id );
		return (string) ob_get_clean();
	}
}
