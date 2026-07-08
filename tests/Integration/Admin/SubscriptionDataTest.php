<?php
/**
 * Integration tests for the Subscription details meta box.
 *
 * Contracts are seeded through the real checkout path. The box is the compact
 * status summary: status, recurring total, payment method and origin order. The
 * cadence and the schedule dates live in the side Schedule box, so they are
 * asserted absent here and present in {@see ScheduleTest}.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\SubscriptionData;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\SubscriptionData
 */
final class SubscriptionDataTest extends LiteIntegrationTestCase {

	public function test_output_renders_the_summary_rows(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract( $customer );

		$html = $this->render( $id );

		$this->assertStringContainsString( 'Recurring total', $html );
		$this->assertStringContainsString( 'Payment method', $html );
		$this->assertStringContainsString( 'Original order', $html );
	}

	public function test_cadence_and_schedule_dates_are_not_in_the_data_box(): void {
		// The cadence and the schedule dates moved to the side Schedule box; the
		// data box must no longer render them.
		$customer = $this->create_customer();
		$id       = $this->create_contract(
			$customer,
			[
				'period'   => 'month',
				'interval' => 3,
			]
		);

		$html = $this->render( $id );

		$this->assertStringNotContainsString( 'Every 3 months', $html );
		$this->assertStringNotContainsString( 'Start date', $html );
		$this->assertStringNotContainsString( 'Next payment', $html );
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
		SubscriptionData::output( $contract );
		return (string) ob_get_clean();
	}
}
