<?php
/**
 * Integration tests for the Schedule meta box.
 *
 * Contracts are seeded through the real checkout path, so the plan snapshot the
 * box reads (cadence, length, trial) is frozen exactly as production freezes it,
 * and the schedule dates come from the real contract.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Schedule;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Schedule
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting::billing_cadence
 */
final class ScheduleTest extends LiteIntegrationTestCase {

	public function test_output_renders_cadence_length_and_dates(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract(
			$customer,
			[
				'period'     => 'month',
				'interval'   => 3,
				'max_cycles' => 12,
			]
		);

		$html = $this->render( $id );

		$this->assertStringContainsString( 'Every 3 months', $html );
		$this->assertStringContainsString( '12 cycles', $html );
		$this->assertStringContainsString( 'Start date', $html );
		$this->assertStringContainsString( 'Next payment', $html );
	}

	public function test_output_renders_singular_cadence(): void {
		$customer = $this->create_customer();
		$id       = $this->create_contract(
			$customer,
			[
				'period'   => 'month',
				'interval' => 1,
			]
		);

		$this->assertStringContainsString( 'Every 1 month', $this->render( $id ) );
	}

	public function test_open_ended_plan_shows_no_cycles_row(): void {
		$customer = $this->create_customer();
		// No max_cycles -> open-ended, so no "cycles" length row is rendered.
		$id   = $this->create_contract( $customer, [ 'period' => 'week' ] );
		$html = $this->render( $id );

		$this->assertStringContainsString( 'Every 1 week', $html );
		$this->assertStringNotContainsString( 'cycles', $html );
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
		Schedule::output( $contract );
		return (string) ob_get_clean();
	}
}
