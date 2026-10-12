<?php
/**
 * Integration tests for DueHandler.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\DueHandler;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\DueHandler
 */
final class DueHandlerTest extends LiteIntegrationTestCase {

	/**
	 * The dispatch context for a due moment.
	 *
	 * @param string $due_at GMT due moment.
	 * @return array<string, DateTimeImmutable>
	 */
	private function context( string $due_at = '2026-02-15 00:00:00' ): array {
		return [ 'due_at' => new DateTimeImmutable( $due_at, new DateTimeZone( 'UTC' ) ) ];
	}

	public function test_an_active_contract_is_renewed(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		( new DueHandler() )->handle( $this->get_contract( $contract_id ), $this->context() );

		$this->assertSame( 1, $this->charge_count() );
		$head = Contracts::get_cycles( $contract_id, 1 )[0];
		$this->assertSame( 2, $head->get_count() );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertNotNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_a_held_contract_with_a_due_moment_has_it_cleared_without_an_order(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->seed_contract(
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => '2026-02-15 00:00:00',
			]
		);
		$this->capture_log();

		( new DueHandler() )->handle( $this->get_contract( $contract_id ), $this->context() );

		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->assertSame( [], Contracts::get_cycles( $contract_id ) );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertTrue( $this->has_logged( 'only active contracts renew' ) );
	}

	public function test_a_malformed_notification_is_ignored(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$contract    = $this->get_contract( $contract_id );
		$this->capture_log();

		( new DueHandler() )->handle( $contract, [] );
		( new DueHandler() )->handle( $contract, [ 'due_at' => '2026-02-15 00:00:00' ] );
		( new DueHandler() )->handle( $contract_id, $this->context() );

		$this->assertSame( 0, $this->charge_count() );
		$this->assertCount( 1, Contracts::get_cycles( $contract_id ) );
		$this->assertSame( $contract->get_next_payment_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertTrue( $this->has_logged( 'ignored a due notification' ) );
	}
}
