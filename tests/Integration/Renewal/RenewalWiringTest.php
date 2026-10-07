<?php
/**
 * Integration tests for the renewal wiring.
 *
 * The wiring delegates first-renewal scheduling to the engine's scheduler seam
 * and logs when the recurring-capability gate turns a schedule down. The gate
 * outcome is driven through the wiring's own constructor seam; the log
 * assertion captures the REAL WooCommerce logger through its message filter.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalWiring
 */
final class RenewalWiringTest extends LiteIntegrationTestCase {

	/**
	 * Messages the real WooCommerce logger received during the test.
	 *
	 * @var array<int, string>
	 */
	private $logged = [];

	public function set_up(): void {
		parent::set_up();
		$this->logged = [];
		add_filter(
			'woocommerce_logger_log_message',
			function ( $message ) {
				$this->logged[] = (string) $message;
				return $message;
			}
		);
	}

	/**
	 * Create a contract through the checkout path and read it back as a view.
	 *
	 * @param string $gateway Gateway id to set as the payment method.
	 */
	private function make_contract( string $gateway = 'dummy' ): ContractView {
		$contract = Contracts::get( $this->create_contract( $this->create_customer(), [ 'payment_method' => $gateway ] ) );
		$this->assertInstanceOf( ContractView::class, $contract );

		return $contract;
	}

	public function test_delegates_to_the_engine_scheduler(): void {
		$received = null;
		$wiring   = new RenewalWiring(
			static function ( ContractView $contract ) use ( &$received ): bool {
				$received = $contract->get_id();
				return true;
			}
		);
		$contract = $this->make_contract();

		$result = $wiring->schedule_first_renewal( $contract );

		$this->assertTrue( $result, 'Returns the engine scheduler result.' );
		$this->assertSame( $contract->get_id(), $received, 'The contract is handed to the engine scheduler.' );
	}

	public function test_logs_when_scheduling_is_gated_off(): void {
		$wiring = new RenewalWiring(
			// The engine returns false when the gateway does not declare recurring.
			static fn ( ContractView $contract ): bool => false
		);

		$contract     = $this->make_contract( 'no-recurring-gateway' );
		$this->logged = [];

		$result = $wiring->schedule_first_renewal( $contract );

		$this->assertFalse( $result, 'A gated-off schedule reports false.' );
		$this->assertNotEmpty( $this->logged, 'A skipped schedule is logged for the merchant signal.' );
	}

	public function test_does_not_log_on_a_successful_schedule(): void {
		$wiring = new RenewalWiring(
			static fn ( ContractView $contract ): bool => true
		);

		$contract     = $this->make_contract();
		$this->logged = [];

		$wiring->schedule_first_renewal( $contract );

		$this->assertSame( [], $this->logged, 'A successful schedule does not log a warning.' );
	}
}
