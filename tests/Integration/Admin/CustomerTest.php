<?php
/**
 * Integration tests for the Customer meta box.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Customer;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Customer
 */
final class CustomerTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		// A merchant viewing the screen can edit users, so the name links.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_output_shows_customer_name_and_email(): void {
		$customer = $this->create_customer(
			[
				'user_email' => 'grace@example.com',
				'first_name' => 'Grace',
				'last_name'  => 'Hopper',
			]
		);
		$id       = $this->create_contract( $customer );

		$html = $this->render( $id );

		$this->assertStringContainsString( 'Grace Hopper', $html );
		$this->assertStringContainsString( 'grace@example.com', $html );
		$this->assertStringContainsString( 'mailto:grace@example.com', $html );
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
		Customer::output( $contract );
		return (string) ob_get_clean();
	}
}
