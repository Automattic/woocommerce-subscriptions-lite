<?php
/**
 * Integration tests for the admin status-label helper.
 *
 * The helper turns engine status slugs into merchant-facing labels - asserted here
 * with real translations loaded.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
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

	public function test_lock_operation_label_reads_a_scheduled_renewal_as_a_renewal(): void {
		$this->assertSame( 'renewal', StatusLabels::get_lock_operation_label( 'engine_due_dispatch' ) );
		$this->assertSame( 'renewal', StatusLabels::get_lock_operation_label( RenewalProcessor::OPERATION ) );
	}

	public function test_lock_operation_label_falls_back_to_a_generic_label(): void {
		$this->assertSame( 'update', StatusLabels::get_lock_operation_label( 'other_extension_sync' ) );
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
