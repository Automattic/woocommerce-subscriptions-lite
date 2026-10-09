<?php
/**
 * Integration tests for Reactivation: on-hold -> active with the next payment recomputed
 * forward from the hold anchor on the plan's cadence.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\LifecycleNotAllowedException;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation
 */
final class ReactivationTest extends LiteIntegrationTestCase {

	/**
	 * Selling plan id that resolves to no plan, for the no-cadence floor.
	 */
	private const MISSING_PLAN_ID = 999999;

	/**
	 * Seed an on-hold contract on a plan, optionally with a stored hold anchor.
	 *
	 * @param string|null $next_payment_gmt Next-payment GMT string, or null.
	 * @param int         $selling_plan_id  Selling plan id.
	 * @param string|null $anchor           Hold anchor meta value, or null for none.
	 * @param string      $status           Contract status.
	 */
	private function seed_held_contract( ?string $next_payment_gmt, int $selling_plan_id, ?string $anchor = null, string $status = ContractStatus::ON_HOLD ): int {
		$contract_id = $this->seed_contract(
			[
				'status'           => $status,
				'selling_plan_id'  => $selling_plan_id,
				'next_payment_gmt' => $next_payment_gmt,
			]
		);
		if ( null !== $anchor ) {
			Contracts::update_meta( $contract_id, HoldAnchor::META_KEY, $anchor );
		}

		return $contract_id;
	}

	/**
	 * A monthly Lite plan id.
	 */
	private function make_monthly_plan_id(): int {
		return (int) $this->make_plan( 'month' )->get_id();
	}

	/**
	 * A UTC moment.
	 *
	 * @param string $datetime GMT datetime string.
	 */
	private function utc( string $datetime ): DateTimeImmutable {
		return new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The stored hold anchor ('' when absent).
	 *
	 * @param int $contract_id Contract id.
	 * @return mixed
	 */
	private function get_stored_anchor( int $contract_id ) {
		return Contracts::get_meta( $contract_id, HoldAnchor::META_KEY, true );
	}

	public function test_reactivate_resumes_and_keeps_a_future_next_payment(): void {
		$contract_id = $this->seed_held_contract( '2026-07-01 00:00:00', $this->make_monthly_plan_id() );

		$reactivated = ( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-15 00:00:00' ) );

		$this->assertInstanceOf( ContractView::class, $reactivated );
		$this->assertSame( ContractStatus::ACTIVE, $reactivated->get_status() );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
		$this->assertSame( '2026-07-01 00:00:00', $stored->get_next_payment_gmt() );
	}

	public function test_reactivate_rolls_a_past_due_date_forward_by_whole_cadences(): void {
		// 2026-02-01 -> 03-01 -> 04-01 -> 05-01, the first date after 2026-04-15.
		$contract_id = $this->seed_held_contract( '2026-02-01 00:00:00', $this->make_monthly_plan_id() );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 00:00:00' ) );

		$this->assertSame( '2026-05-01 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_reactivate_rearms_from_the_hold_anchor(): void {
		$contract_id = $this->seed_held_contract( '2099-01-01 00:00:00', $this->make_monthly_plan_id(), null, ContractStatus::ACTIVE );
		( new Hold() )->hold( $contract_id );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt() );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_reactivate_rolls_a_past_due_anchor_forward(): void {
		$contract_id = $this->seed_held_contract( null, $this->make_monthly_plan_id(), '2026-02-01 00:00:00' );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 00:00:00' ) );

		$this->assertSame( '2026-05-01 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	/**
	 * Hold clears the next payment, so one set while held was re-armed deliberately and wins.
	 */
	public function test_reactivate_prefers_a_next_payment_set_while_held_over_the_anchor(): void {
		$contract_id = $this->seed_held_contract( '2099-06-01 00:00:00', $this->make_monthly_plan_id(), '2099-01-01 00:00:00' );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );

		$this->assertSame( '2099-06-01 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_reactivate_ignores_a_malformed_anchor(): void {
		$contract_id = $this->seed_held_contract( null, $this->make_monthly_plan_id(), 'not-a-date' );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
		$this->assertNull( $stored->get_next_payment_gmt(), 'A malformed anchor counts as absent.' );
		$this->assertSame( '', $this->get_stored_anchor( $contract_id ) );
	}

	public function test_reactivate_without_an_anchor_leaves_the_contract_unscheduled(): void {
		$contract_id = $this->seed_held_contract( null, $this->make_monthly_plan_id() );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 00:00:00' ) );

		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
		$this->assertNull( $stored->get_next_payment_gmt() );
	}

	public function test_reactivate_floors_past_due_at_now_when_the_roll_cap_exhausts(): void {
		// Daily cadence, about 6.5 years past due: more rolls than the cap allows.
		$contract_id = $this->seed_held_contract( '2020-01-01 00:00:00', (int) $this->make_plan( 'day' )->get_id() );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-07-06 00:00:00' ) );

		$this->assertSame( '2026-07-06 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_reactivate_floors_past_due_at_now_without_a_plan(): void {
		$contract_id = $this->seed_held_contract( '2026-02-01 00:00:00', self::MISSING_PLAN_ID );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 09:30:00' ) );

		$this->assertSame( '2026-04-15 09:30:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_reactivate_rolls_past_due_by_the_cadence_of_an_archived_plan(): void {
		$plan        = $this->make_plan( 'month', 1, null, [ 'status' => PlanStatus::ARCHIVED ] );
		$contract_id = $this->seed_held_contract( '2026-02-01 00:00:00', $plan->get_id() );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 09:30:00' ) );

		$this->assertSame( '2026-05-01 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_reactivate_floors_past_due_at_now_without_a_usable_cadence(): void {
		$plan        = $this->make_unvalidated_plan( 'fortnight' );
		$contract_id = $this->seed_held_contract( '2026-02-01 00:00:00', $plan->get_id() );

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 09:30:00' ) );

		$this->assertSame( '2026-04-15 09:30:00', $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_reactivate_fires_the_reactivated_action_with_the_resulting_view(): void {
		$contract_id = $this->seed_held_contract( '2099-01-01 00:00:00', $this->make_monthly_plan_id() );
		$received    = [];
		add_action(
			'woocommerce_subscriptions_lite_contract_reactivated',
			static function ( ContractView $contract ) use ( &$received ): void {
				$received[] = $contract;
			}
		);

		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );

		$this->assertCount( 1, $received );
		$this->assertSame( ContractStatus::ACTIVE, $received[0]->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $received[0]->get_next_payment_gmt() );
	}

	/**
	 * The anchor is cleared after the status write, so a failed delete must not abort a
	 * reactivation that could not be retried on an active contract.
	 */
	public function test_a_failed_anchor_clear_does_not_abort_the_reactivation(): void {
		$contract_id = $this->seed_held_contract( null, $this->make_monthly_plan_id(), '2099-01-01 00:00:00' );
		$fired       = 0;
		add_action(
			'woocommerce_subscriptions_lite_contract_reactivated',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$meta_table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACT_META );
		$break      = static function ( string $query ) use ( $meta_table ): string {
			return 0 === strpos( $query, "DELETE FROM `{$meta_table}`" ) ? 'SELECT broken syntax (' : $query;
		};
		add_filter( 'query', $break );

		try {
			$reactivated = ( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );
		} finally {
			remove_filter( 'query', $break );
		}

		$this->assertInstanceOf( ContractView::class, $reactivated );
		$this->assertSame( 1, $fired, 'The reactivated action fires.' );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
		$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt() );
	}

	/**
	 * An active contract past its due date must not reach the forward roll: rolling it
	 * would skip the charge the renewal owes.
	 */
	public function test_reactivate_rejects_an_active_contract_and_leaves_its_date(): void {
		$contract_id = $this->seed_held_contract( '2026-02-01 00:00:00', $this->make_monthly_plan_id(), null, ContractStatus::ACTIVE );

		try {
			( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-04-15 00:00:00' ) );
			$this->fail( 'Expected LifecycleNotAllowedException for an active contract.' );
		} catch ( LifecycleNotAllowedException $e ) {
			$stored = $this->get_contract( $contract_id );
			$this->assertSame( ContractStatus::ACTIVE, $stored->get_status() );
			$this->assertSame( '2026-02-01 00:00:00', $stored->get_next_payment_gmt(), 'The past-due date is untouched.' );
		}
	}

	/**
	 * @dataProvider provide_rejected_statuses
	 *
	 * @param string $status Stored status reactivation must reject.
	 */
	public function test_reactivate_rejects_a_contract_that_is_not_on_hold( string $status ): void {
		$contract_id = $this->seed_held_contract( '2099-01-01 00:00:00', $this->make_monthly_plan_id(), null, $status );

		try {
			( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );
			$this->fail( 'Expected LifecycleNotAllowedException.' );
		} catch ( LifecycleNotAllowedException $e ) {
			$stored = $this->get_contract( $contract_id );
			$this->assertSame( $status, $stored->get_status() );
			$this->assertSame( '2099-01-01 00:00:00', $stored->get_next_payment_gmt(), 'Nothing was written.' );
		}
	}

	/**
	 * Statuses reactivation rejects (besides active, covered above).
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_rejected_statuses(): array {
		return [
			'cancelled'            => [ ContractStatus::CANCELLED ],
			'pending cancellation' => [ ContractStatus::PENDING_CANCELLATION ],
			'draft'                => [ ContractStatus::DRAFT ],
		];
	}

	public function test_reactivate_rejects_an_unregistered_stored_status(): void {
		$contract_id = $this->seed_held_contract( '2099-01-01 00:00:00', $this->make_monthly_plan_id() );
		$this->store_raw_status( $contract_id, 'legacy-paused' );

		$this->expectException( LifecycleNotAllowedException::class );
		( new Reactivation() )->reactivate( $contract_id, $this->utc( '2026-06-01 00:00:00' ) );
	}

	public function test_reactivate_returns_null_for_a_missing_contract(): void {
		$this->assertNull( ( new Reactivation() )->reactivate( 4242424 ) );
	}

	public function test_reactivate_leaves_a_contract_of_another_extension_untouched(): void {
		$contract_id = $this->seed_contract(
			[
				'extension_slug'   => 'another-extension',
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => null,
			]
		);

		$this->assertNull( ( new Reactivation() )->reactivate( $contract_id ) );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}
}
