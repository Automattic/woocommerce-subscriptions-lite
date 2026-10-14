<?php
/**
 * Integration tests for BillingPeriod.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\BillingPeriod;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\BillingPeriod
 */
final class BillingPeriodTest extends LiteIntegrationTestCase {

	/**
	 * A UTC moment.
	 *
	 * @param string $datetime GMT datetime string.
	 */
	private function utc( string $datetime ): DateTimeImmutable {
		return new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A billing policy.
	 *
	 * @param string $period   Period.
	 * @param int    $interval Interval.
	 */
	private function policy( string $period = 'month', int $interval = 1 ): BillingPolicy {
		return new BillingPolicy( $period, $interval, null, null, null );
	}

	/**
	 * Format a period as GMT strings.
	 *
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $period Period.
	 * @return array<int, string>
	 */
	private function format( array $period ): array {
		return [ $period[0]->format( 'Y-m-d H:i:s' ), $period[1]->format( 'Y-m-d H:i:s' ) ];
	}

	public function test_a_future_anchor_starts_the_period(): void {
		$period = BillingPeriod::get_period_to_bill( $this->utc( '2026-05-01 00:00:00' ), $this->utc( '2026-04-15 00:00:00' ), $this->policy() );

		$this->assertSame( [ '2026-05-01 00:00:00', '2026-06-01 00:00:00' ], $this->format( $period ) );
	}

	public function test_an_anchor_due_now_bills_the_period_starting_at_it(): void {
		$period = BillingPeriod::get_period_to_bill( $this->utc( '2026-05-01 00:00:00' ), $this->utc( '2026-05-01 00:00:00' ), $this->policy() );

		$this->assertSame( [ '2026-05-01 00:00:00', '2026-06-01 00:00:00' ], $this->format( $period ) );
	}

	public function test_an_anchor_several_periods_behind_bills_the_one_period_containing_now(): void {
		$now    = $this->utc( '2026-04-15 12:00:00' );
		$period = BillingPeriod::get_period_to_bill( $this->utc( '2026-01-01 00:00:00' ), $now, $this->policy() );

		$this->assertSame( [ '2026-04-01 00:00:00', '2026-05-01 00:00:00' ], $this->format( $period ) );
		$this->assertLessThanOrEqual( $now, $period[0] );
		$this->assertGreaterThan( $now, $period[1] );
	}

	public function test_a_boundary_equal_to_now_rolls_into_the_next_period(): void {
		$period = BillingPeriod::get_period_to_bill( $this->utc( '2026-03-01 00:00:00' ), $this->utc( '2026-04-01 00:00:00' ), $this->policy() );

		$this->assertSame( [ '2026-04-01 00:00:00', '2026-05-01 00:00:00' ], $this->format( $period ) );
	}

	public function test_get_period_to_bill_floors_at_now_and_logs_when_the_roll_cap_runs_out(): void {
		$this->capture_log();

		$period = BillingPeriod::get_period_to_bill( $this->utc( '2020-01-01 00:00:00' ), $this->utc( '2026-07-06 00:00:00' ), $this->policy( 'day' ) );

		$this->assertSame( [ '2026-07-06 00:00:00', '2026-07-07 00:00:00' ], $this->format( $period ) );
		$this->assertTrue( $this->has_logged( 'exhausted the roll cap' ) );
	}

	public function test_get_next_due_after_keeps_a_future_moment(): void {
		$next = BillingPeriod::get_next_due_after( $this->utc( '2026-06-01 00:00:00' ), $this->utc( '2026-05-15 00:00:00' ), $this->policy() );

		$this->assertSame( '2026-06-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_rolls_a_past_moment_past_now(): void {
		$next = BillingPeriod::get_next_due_after( $this->utc( '2026-02-01 00:00:00' ), $this->utc( '2026-04-15 09:30:00' ), $this->policy() );

		$this->assertSame( '2026-05-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_rolls_a_moment_equal_to_now(): void {
		$next = BillingPeriod::get_next_due_after( $this->utc( '2026-04-01 00:00:00' ), $this->utc( '2026-04-01 00:00:00' ), $this->policy( 'week', 2 ) );

		$this->assertSame( '2026-04-15 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_floors_one_period_after_now_when_the_roll_cap_runs_out(): void {
		$this->capture_log();

		$next = BillingPeriod::get_next_due_after( $this->utc( '2020-01-01 00:00:00' ), $this->utc( '2026-07-06 00:00:00' ), $this->policy( 'day' ) );

		$this->assertSame( '2026-07-07 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $this->has_logged( 'exhausted the roll cap' ) );
	}
}
