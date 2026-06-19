<?php
/**
 * Unit tests for the renewal wiring.
 *
 * The wiring delegates first-renewal scheduling to the engine's RenewalEngine,
 * whose schedule() applies the recurring-capability gate. These tests inject a
 * fake scheduler seam to assert the wiring hands the contract over and reports
 * the gated / scheduled outcome, without booting Action Scheduler.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\Renewal;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring
 */
final class RenewalWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wc_subscriptions_lite_test_logs'] = [];
	}

	/**
	 * Build a contract double with an id and a gateway payment method.
	 *
	 * @param string $gateway Gateway id to set as the payment method.
	 */
	private function make_contract( string $gateway = 'dummy' ): Contract {
		$contract = Contract::create(
			[
				'customer_id'      => 1,
				'currency'         => 'USD',
				'selling_plan_id'  => 7,
				'origin_order_id'  => 55,
				'payment_method'   => $gateway,
				'billing_total'    => '19.99',
				'start_gmt'        => '2026-01-01 00:00:00',
				'next_payment_gmt' => '2026-02-01 00:00:00',
				'schedule_source'  => Contract::SCHEDULE_SOURCE_PRIMITIVE,
				'items'            => [],
				'addresses'        => [],
				'meta'             => [],
			]
		);
		$contract->set_id( 100 );
		return $contract;
	}

	public function test_delegates_to_the_engine_scheduler(): void {
		$received = null;
		$wiring   = new RenewalWiring(
			static function ( Contract $contract ) use ( &$received ): bool {
				$received = $contract->get_id();
				return true;
			}
		);

		$result = $wiring->schedule_first_renewal( $this->make_contract() );

		$this->assertTrue( $result, 'Returns the engine scheduler result.' );
		$this->assertSame( 100, $received, 'The contract is handed to the engine scheduler.' );
	}

	public function test_logs_when_scheduling_is_gated_off(): void {
		$wiring = new RenewalWiring(
			// The engine returns false when the gateway does not declare recurring.
			static fn ( Contract $contract ): bool => false
		);

		$result = $wiring->schedule_first_renewal( $this->make_contract( 'no-recurring-gateway' ) );

		$this->assertFalse( $result, 'A gated-off schedule reports false.' );
		$this->assertNotEmpty( $GLOBALS['wc_subscriptions_lite_test_logs'], 'A skipped schedule is logged for the merchant signal.' );
	}

	public function test_does_not_log_on_a_successful_schedule(): void {
		$wiring = new RenewalWiring(
			static fn ( Contract $contract ): bool => true
		);

		$wiring->schedule_first_renewal( $this->make_contract() );

		$this->assertSame( [], $GLOBALS['wc_subscriptions_lite_test_logs'], 'A successful schedule does not log a warning.' );
	}
}
