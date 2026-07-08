<?php
/**
 * Integration tests for the Addresses meta box.
 *
 * Addresses originate from a real order run through the checkout path, so the
 * billing/shipping data the box renders is shaped exactly like production data.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Addresses;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Addresses
 */
final class AddressesTest extends LiteIntegrationTestCase {

	public function test_output_renders_billing_and_shipping_blocks(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract( $customer );

		$html = $this->render( $id );

		$this->assertStringContainsString( 'Billing', $html );
		$this->assertStringContainsString( 'Shipping', $html );

		// The billing block carries its postal address and the contact email/phone.
		$this->assertStringContainsString( '12 Analytical Row', $html );
		$this->assertStringContainsString( 'ada@example.com', $html );
		$this->assertStringContainsString( 'mailto:ada@example.com', $html );

		// The shipping block carries its own (different) street.
		$this->assertStringContainsString( '1 Engine Court', $html );
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
		Addresses::output( $contract );
		return (string) ob_get_clean();
	}
}
