<?php
/**
 * Integration tests for the admin status-label helper.
 *
 * The helper turns engine status slugs into merchant-facing labels and decides
 * which actions a status allows - asserted here with real translations loaded.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels
 */
final class StatusLabelsTest extends LiteIntegrationTestCase {

	public function test_contract_label_uses_merchant_wording(): void {
		$this->assertSame( 'Draft', StatusLabels::contract_label( ContractStatus::DRAFT ) );
		$this->assertSame( 'Active', StatusLabels::contract_label( ContractStatus::ACTIVE ) );
		$this->assertSame( 'Pending cancellation', StatusLabels::contract_label( ContractStatus::PENDING_CANCELLATION ) );
	}

	public function test_contract_label_humanizes_an_unknown_status(): void {
		$this->assertSame( 'Some future status', StatusLabels::contract_label( 'some-future-status' ) );
	}

	public function test_cycle_label_uses_merchant_wording(): void {
		$this->assertSame( 'Billed', StatusLabels::cycle_label( CycleStatus::BILLED ) );
		$this->assertSame( 'Failed', StatusLabels::cycle_label( CycleStatus::FAILED ) );
	}

	public function test_is_renewable_offers_active_lite_contracts(): void {
		$this->assertTrue( StatusLabels::is_renewable( $this->get_contract( $this->seed_contract() ) ) );
		$this->assertFalse( StatusLabels::is_renewable( $this->get_contract( $this->seed_contract( [ 'extension_slug' => 'another-extension' ] ) ) ), 'Lite renews only its own contracts.' );
	}

	public function test_is_renewable_excludes_drafts_terminal_statuses_and_a_plain_hold(): void {
		foreach ( [ ContractStatus::DRAFT, ContractStatus::CANCELLED, ContractStatus::EXPIRED, ContractStatus::PENDING_CANCELLATION, ContractStatus::ON_HOLD ] as $status ) {
			$this->assertFalse( StatusLabels::is_renewable( $this->get_contract( $this->seed_contract( [ 'status' => $status ] ) ) ), $status );
		}
	}

	public function test_is_renewable_offers_a_hold_with_an_unpaid_renewal(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id, [ 'status' => 'on-hold' ] );
		$this->create_renewal_order(
			$contract_id,
			$customer_id,
			[
				'status'       => 'failed',
				'cycle_status' => CycleStatus::FAILED,
			]
		);

		$this->assertTrue( StatusLabels::is_renewable( $this->get_contract( $contract_id ) ) );
	}

	public function test_contract_badge_html_carries_the_status_modifier_and_label(): void {
		$html = StatusLabels::contract_badge_html( ContractStatus::ACTIVE );

		// Reuses WooCommerce's order-status badge chrome and layers the Lite
		// status modifier on top.
		$this->assertStringContainsString( 'order-status', $html );
		$this->assertStringContainsString( 'wc-subs-lite-status-badge--active', $html );
		$this->assertStringContainsString( 'Active', $html );
	}
}
