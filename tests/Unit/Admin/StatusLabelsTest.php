<?php
/**
 * Unit tests for the admin status-label helper.
 *
 * The helper turns engine status slugs into merchant-facing labels and decides
 * which actions a status allows. Pure functions, so these assert the label
 * vocabulary, the humanizing fallback for unknown statuses, and the
 * action-gating predicates directly.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels
 */
final class StatusLabelsTest extends TestCase {

	public function test_contract_label_uses_merchant_wording(): void {
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

	public function test_is_cancellable_allows_non_terminal_statuses_only(): void {
		$this->assertTrue( StatusLabels::is_cancellable( ContractStatus::ACTIVE ) );
		$this->assertTrue( StatusLabels::is_cancellable( ContractStatus::ON_HOLD ) );
		$this->assertTrue( StatusLabels::is_cancellable( ContractStatus::PENDING_CANCELLATION ) );
		$this->assertFalse( StatusLabels::is_cancellable( ContractStatus::CANCELLED ) );
		$this->assertFalse( StatusLabels::is_cancellable( ContractStatus::EXPIRED ) );
	}

	public function test_is_renewable_excludes_terminal_statuses(): void {
		$this->assertTrue( StatusLabels::is_renewable( ContractStatus::ACTIVE ) );
		$this->assertFalse( StatusLabels::is_renewable( ContractStatus::CANCELLED ) );
		$this->assertFalse( StatusLabels::is_renewable( ContractStatus::EXPIRED ) );
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
