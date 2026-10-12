<?php
/**
 * BillingPeriod - the period a renewal bills and the next due moment after it.
 *
 * Catch-up rule: a contract that comes back due late (after a hold, an owner absence or a
 * cron outage) bills one period - the one containing now - and its next due moment is the
 * end of that period, always in the future. Periods follow the plan's cadence from the
 * due anchor, never from the previous cycle's end.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Billing period arithmetic (UTC).
 */
final class BillingPeriod {

	/**
	 * Bound on the forward roll, so a fine-grained cadence far behind cannot loop unboundedly.
	 */
	private const MAX_ROLLS = 1000;

	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * The period to bill: it starts at `$anchor` rolled forward by whole cadences while the
	 * following boundary is not after `$now`, so it contains `$now` (or starts at a future
	 * anchor). When the roll cap runs out the period starts at `$now` (logged).
	 *
	 * @param DateTimeImmutable $anchor The due moment the renewal was anchored on.
	 * @param DateTimeImmutable $now    The current moment.
	 * @param BillingPolicy     $policy The plan's billing policy.
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Period start and end (UTC).
	 */
	public static function containing( DateTimeImmutable $anchor, DateTimeImmutable $now, BillingPolicy $policy ): array {
		$utc   = new DateTimeZone( 'UTC' );
		$start = $anchor->setTimezone( $utc );
		$now   = $now->setTimezone( $utc );
		$end   = $policy->compute_next_renewal_from( $start );

		$rolls_left = self::MAX_ROLLS;
		while ( $end <= $now && $rolls_left-- > 0 ) {
			$start = $end;
			$end   = $policy->compute_next_renewal_from( $start );
		}

		if ( $end <= $now ) {
			self::log_cap( 'containing', $anchor );
			$start = $now;
			$end   = $policy->compute_next_renewal_from( $start );
		}

		return [ $start, $end ];
	}

	/**
	 * The next due moment from `$from`: kept when it is after `$now`, else rolled forward by
	 * whole cadences until it is. When the roll cap runs out it is one cadence after `$now`
	 * (logged).
	 *
	 * @param DateTimeImmutable $from   The moment to start from (typically a period end).
	 * @param DateTimeImmutable $now    The current moment.
	 * @param BillingPolicy     $policy The plan's billing policy.
	 */
	public static function next_due_after( DateTimeImmutable $from, DateTimeImmutable $now, BillingPolicy $policy ): DateTimeImmutable {
		$utc  = new DateTimeZone( 'UTC' );
		$next = $from->setTimezone( $utc );
		$now  = $now->setTimezone( $utc );

		$rolls_left = self::MAX_ROLLS;
		while ( $next <= $now && $rolls_left-- > 0 ) {
			$next = $policy->compute_next_renewal_from( $next );
		}

		if ( $next <= $now ) {
			self::log_cap( 'next_due_after', $from );
			$next = $policy->compute_next_renewal_from( $now );
		}

		return $next;
	}

	/**
	 * Log a roll that ran out of the cap.
	 *
	 * @param string            $method The method that hit the cap.
	 * @param DateTimeImmutable $from   The moment it rolled from.
	 */
	private static function log_cap( string $method, DateTimeImmutable $from ): void {
		wc_get_logger()->warning(
			sprintf( 'BillingPeriod::%s(): rolling forward from %s exhausted the roll cap; floored at now.', $method, $from->format( 'Y-m-d H:i:s' ) ),
			[ 'source' => self::LOG_SOURCE ]
		);
	}
}
