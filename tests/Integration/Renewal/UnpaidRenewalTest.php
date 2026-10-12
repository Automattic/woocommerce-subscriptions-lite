<?php
/**
 * Integration tests for UnpaidRenewal.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\UnpaidRenewal;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\UnpaidRenewal
 */
final class UnpaidRenewalTest extends LiteIntegrationTestCase {

	/**
	 * A contract whose newest cycle is a renewal in `$cycle_status`.
	 *
	 * @param string $cycle_status Cycle status.
	 * @param string $order_status Renewal order status.
	 */
	private function contract_with_renewal( string $cycle_status, string $order_status = 'pending' ): int {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id, [ 'payment_method' => 'cod' ] );
		$this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => $order_status,
				'cycle_status' => $cycle_status,
			]
		);

		return $contract_id;
	}

	public function test_a_failed_renewal_is_unpaid(): void {
		$contract_id = $this->contract_with_renewal( CycleStatus::FAILED, 'failed' );

		$unpaid = UnpaidRenewal::find( $this->get_contract( $contract_id ) );

		$this->assertInstanceOf( CycleView::class, $unpaid );
		$this->assertSame( 2, $unpaid->get_count() );
	}

	public function test_a_pending_renewal_with_an_order_is_unpaid(): void {
		$this->assertInstanceOf( CycleView::class, UnpaidRenewal::find( $this->get_contract( $this->contract_with_renewal( CycleStatus::PENDING ) ) ) );
	}

	public function test_a_processing_renewal_with_an_order_is_unpaid(): void {
		$this->assertInstanceOf( CycleView::class, UnpaidRenewal::find( $this->get_contract( $this->contract_with_renewal( CycleStatus::PROCESSING, 'on-hold' ) ) ) );
	}

	public function test_a_pending_renewal_without_an_order_is_not_unpaid(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => '2026-02-15 00:00:00',
				'ends_at_gmt'    => '2026-03-15 00:00:00',
				'expected_total' => '19.99',
				'currency'       => 'USD',
			]
		);

		$this->assertNull( UnpaidRenewal::find( $this->get_contract( $contract_id ) ) );
	}

	public function test_a_billed_renewal_is_not_unpaid(): void {
		$this->assertNull( UnpaidRenewal::find( $this->get_contract( $this->contract_with_renewal( CycleStatus::BILLED, 'completed' ) ) ) );
	}

	public function test_a_contract_without_cycles_has_no_unpaid_renewal(): void {
		$this->assertNull( UnpaidRenewal::find( $this->get_contract( $this->seed_contract() ) ) );
	}
}
