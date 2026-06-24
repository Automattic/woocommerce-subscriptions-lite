<?php
/**
 * Unit tests for the customer-portal view-model builder.
 *
 * Covers the per-status presentation logic the templates and the iAPI seed
 * rely on: status labels, the dynamic detail date-row, the recurring summary,
 * the next-payment dash rule, and the action-visibility flags - across all five
 * statuses plus the on-hold-needs-payment variant.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\CustomerPortal;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\ViewModel;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\ViewModel
 */
final class ViewModelTest extends TestCase {

	/**
	 * A base contract array, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function contract( array $overrides = [] ): array {
		return array_merge(
			[
				'id'               => 7,
				'status'           => ContractStatus::ACTIVE,
				'billing_total'    => '19.99',
				'currency'         => 'USD',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'next_payment_gmt' => '2099-01-15 00:00:00',
				'start_gmt'        => '2020-01-01 00:00:00',
				'end_gmt'          => null,
				'last_payment_gmt' => '2098-12-15 00:00:00',
				'last_updated_gmt' => '2098-12-15 00:00:00',
				'payment_method'   => [
					'title'   => 'Visa ending in 4242',
					'expires' => '(expires 12/30)',
				],
			],
			$overrides
		);
	}

	public function test_active_status_label_and_action_flags(): void {
		$detail = ( new ViewModel() )->build_detail( $this->contract() );

		$this->assertSame( 'Active', $detail['status_label'] );
		$this->assertSame( 'Next payment date', $detail['date_row_label'] );
		$this->assertSame( '2099-01-15', $detail['date_row_value'] );
		$this->assertTrue( $detail['cancel_visible'] );
		$this->assertTrue( $detail['hold_visible'] );
		$this->assertFalse( $detail['reactivate_visible'] );
		$this->assertFalse( $detail['needs_payment_notice'] );
		$this->assertTrue( $detail['at_period_end'], 'Active cancels at period end.' );
	}

	public function test_pending_cancellation_label_date_row_and_flags(): void {
		$detail = ( new ViewModel() )->build_detail(
			$this->contract(
				[
					'status'           => ContractStatus::PENDING_CANCELLATION,
					'end_gmt'          => '2099-02-01 00:00:00',
					'next_payment_gmt' => '2099-02-01 00:00:00',
				]
			)
		);

		$this->assertSame( 'Cancels soon', $detail['status_label'] );
		$this->assertSame( 'Cancels on', $detail['date_row_label'] );
		$this->assertSame( '2099-02-01', $detail['date_row_value'] );
		$this->assertFalse( $detail['cancel_visible'], 'Pending-cancellation hides cancel.' );
		$this->assertFalse( $detail['hold_visible'] );
		$this->assertFalse( $detail['reactivate_visible'] );
	}

	public function test_cancelled_status_uses_end_date_row(): void {
		$detail = ( new ViewModel() )->build_detail(
			$this->contract(
				[
					'status'           => ContractStatus::CANCELLED,
					'next_payment_gmt' => null,
					'end_gmt'          => '2098-11-01 00:00:00',
				]
			)
		);

		$this->assertSame( 'Cancelled', $detail['status_label'] );
		$this->assertSame( 'End date', $detail['date_row_label'] );
		$this->assertSame( '2098-11-01', $detail['date_row_value'] );
		$this->assertFalse( $detail['cancel_visible'] );
	}

	public function test_expired_status_uses_end_date_row(): void {
		$detail = ( new ViewModel() )->build_detail(
			$this->contract(
				[
					'status'           => ContractStatus::EXPIRED,
					'next_payment_gmt' => null,
					'end_gmt'          => '2098-10-01 00:00:00',
				]
			)
		);

		$this->assertSame( 'Expired', $detail['status_label'] );
		$this->assertSame( 'End date', $detail['date_row_label'] );
		$this->assertSame( '2098-10-01', $detail['date_row_value'] );
	}

	public function test_on_hold_admin_action_path_allows_reactivate(): void {
		// On hold with NO next payment = admin-action path: reactivate is safe,
		// no needs-payment notice, the date-row falls back to "On-hold since".
		$detail = ( new ViewModel() )->build_detail(
			$this->contract(
				[
					'status'           => ContractStatus::ON_HOLD,
					'next_payment_gmt' => null,
					'last_updated_gmt' => '2098-12-20 00:00:00',
				]
			)
		);

		$this->assertSame( 'On hold', $detail['status_label'] );
		$this->assertSame( 'On-hold since', $detail['date_row_label'] );
		$this->assertSame( '2098-12-20', $detail['date_row_value'] );
		$this->assertTrue( $detail['cancel_visible'] );
		$this->assertFalse( $detail['hold_visible'], 'On-hold does not show pause.' );
		$this->assertTrue( $detail['reactivate_visible'] );
		$this->assertFalse( $detail['needs_payment_notice'] );
		$this->assertFalse( $detail['at_period_end'], 'On-hold cancels immediately.' );
	}

	public function test_on_hold_needs_payment_variant_hides_reactivate(): void {
		// On hold WITH a scheduled next payment = failed-payment retry path:
		// the needs-payment notice shows and reactivate is hidden.
		$detail = ( new ViewModel() )->build_detail(
			$this->contract(
				[
					'status'           => ContractStatus::ON_HOLD,
					'next_payment_gmt' => '2099-03-01 00:00:00',
				]
			)
		);

		$this->assertTrue( $detail['needs_payment_notice'] );
		$this->assertFalse( $detail['reactivate_visible'] );
		$this->assertSame( 'Next payment date', $detail['date_row_label'] );
		$this->assertSame( '2099-03-01', $detail['date_row_value'] );
	}

	public function test_recurring_summary_interval_one_and_many(): void {
		$one = ( new ViewModel() )->build_detail( $this->contract() );
		$this->assertSame( 'USD19.99 / month', $one['recurring_summary'] );

		$many = ( new ViewModel() )->build_detail(
			$this->contract(
				[
					'billing_period'   => 'week',
					'billing_interval' => 2,
				]
			)
		);
		$this->assertSame( 'USD19.99 every 2 weeks', $many['recurring_summary'] );
	}

	public function test_list_row_dashes_next_payment_for_terminal_status(): void {
		$row = ( new ViewModel() )->build_row(
			$this->contract(
				[
					'status'           => ContractStatus::CANCELLED,
					'next_payment_gmt' => '2099-01-15 00:00:00',
				]
			)
		);

		$this->assertSame( '', $row['next_payment'], 'Cancelled rows never show a next-payment date.' );
		$this->assertSame( 'Cancelled', $row['status_label'] );
	}

	public function test_build_list_shapes_each_contract(): void {
		$rows = ( new ViewModel() )->build_list(
			[
				$this->contract( [ 'id' => 1 ] ),
				$this->contract(
					[
						'id'     => 2,
						'status' => ContractStatus::ON_HOLD,
					]
				),
			]
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 1, $rows[0]['id'] );
		$this->assertSame( 'On hold', $rows[1]['status_label'] );
	}

	public function test_cancel_modal_copy_is_state_aware(): void {
		$view = new ViewModel();

		$active = $view->build_detail( $this->contract( [ 'next_payment_gmt' => '2099-01-15 00:00:00' ] ) );
		$this->assertStringContainsString( 'end of your current billing cycle', $active['cancel_modal_copy'] );
		$this->assertStringContainsString( '2099-01-15', $active['cancel_modal_copy'] );

		$on_hold = $view->build_detail(
			$this->contract(
				[
					'status'           => ContractStatus::ON_HOLD,
					'next_payment_gmt' => null,
				]
			)
		);
		$this->assertStringContainsString( 'cancelled immediately', $on_hold['cancel_modal_copy'] );
	}
}
