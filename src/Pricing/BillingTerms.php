<?php
/**
 * BillingTerms - Lite's reader of the billing payload it writes on its plans.
 *
 * The engine stores plan policies as opaque extension payloads; Lite writes
 * `billing_policy` in the array shape the engine's opt-in `BillingPolicy` parser reads:
 *   { period: day|week|month|year, interval: int, min_cycles: ?int, max_cycles: ?int,
 *     trial_duration: { length: int, unit: day|week|month|year } | null }
 *
 * A live plan is read strictly: {@see self::from_plan()} returns terms only when the
 * payload is billable, meaning contract creation can parse it through `BillingPolicy`
 * and compute a first renewal ({@see self::validate()}). The storefront, the cart and
 * checkout all use that one rule. A missing plan reads as no terms (null). Reads never throw.
 *
 * It also turns the cadence into dates (UTC): the first renewal of a new contract, the period
 * a renewal bills and the next due moment after it. Catch-up rule: a contract that comes back
 * due late (after a hold, an owner absence or a cron outage) bills one period - the one
 * containing now - and its next due moment is the end of that period, always in the future.
 * Periods follow the plan's cadence from the due anchor, never from the previous cycle's end.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable billing terms.
 */
final class BillingTerms {

	private const PERIODS = [ 'day', 'week', 'month', 'year' ];

	private const CADENCE_MESSAGE = 'billing_policy must have a period (day, week, month or year) and a positive interval.';

	private const MAX_CYCLES_MESSAGE = 'billing_policy max_cycles must be a positive integer, or null for no limit.';

	/**
	 * Bound on the forward roll, so a fine-grained cadence far behind cannot loop unboundedly.
	 */
	private const MAX_ROLLS = 1000;

	/**
	 * Billing period.
	 *
	 * @var string
	 */
	private $period;

	/**
	 * Periods per billing cycle.
	 *
	 * @var int
	 */
	private $interval;

	/**
	 * Maximum billing cycles, or null when open-ended.
	 *
	 * @var int|null
	 */
	private $max_cycles;

	/**
	 * Free trial, or null.
	 *
	 * @var array{length: int, unit: string}|null
	 */
	private $trial_duration;

	/**
	 * Build from parsed values; use {@see self::from_plan()}.
	 *
	 * @param string                                $period         Billing period.
	 * @param int                                   $interval       Periods per cycle.
	 * @param int|null                              $max_cycles     Maximum cycles.
	 * @param array{length: int, unit: string}|null $trial_duration Free trial.
	 */
	private function __construct( string $period, int $interval, ?int $max_cycles, ?array $trial_duration ) {
		$this->period         = $period;
		$this->interval       = $interval;
		$this->max_cycles     = $max_cycles;
		$this->trial_duration = $trial_duration;
	}

	/**
	 * Terms from a plan's `billing_policy`; null when there is no plan or it is absent or not billable
	 * (see {@see self::validate()}).
	 *
	 * @param PlanView|null $plan Plan.
	 */
	public static function from_plan( ?PlanView $plan ): ?self {
		if ( null === $plan ) {
			return null;
		}

		$policy = $plan->get_billing_policy();

		return [] === self::validate( $policy ) ? self::from_policy( $policy ) : null;
	}

	/**
	 * Problems that keep a `billing_policy` payload from being billed: it needs a
	 * known period and a positive interval, a `max_cycles` that is null (no limit) or
	 * a positive integer, and it must parse through the engine's `BillingPolicy` with
	 * a first renewal date, exactly as contract creation reads it (an integer
	 * interval, a known trial unit, consistent cycle bounds).
	 *
	 * @param array<string, mixed>|null $policy Billing payload.
	 * @return array<int, string> Error messages; empty when billable.
	 */
	public static function validate( ?array $policy ): array {
		if ( null === $policy || null === self::from_policy( $policy ) ) {
			return [ self::CADENCE_MESSAGE ];
		}

		// The engine counts cycles against max_cycles, so 0 would read as already reached.
		if ( null !== ( $policy['max_cycles'] ?? null ) && null === self::positive_int( $policy['max_cycles'] ) ) {
			return [ self::MAX_CYCLES_MESSAGE ];
		}

		try {
			self::parse_first_renewal( $policy, new DateTimeImmutable( '2000-01-01', new DateTimeZone( 'UTC' ) ) );
		} catch ( DomainException $e ) {
			return [ 'billing_policy: ' . $e->getMessage() ];
		}

		return [];
	}

	/**
	 * The first renewal moment of a contract on `$plan` starting at `$start`.
	 *
	 * @param PlanView          $plan  Plan.
	 * @param DateTimeImmutable $start Contract start.
	 * @throws DomainException When the billing payload is not billable.
	 */
	public static function get_first_renewal_from( PlanView $plan, DateTimeImmutable $start ): DateTimeImmutable {
		return self::parse_first_renewal( $plan->get_billing_policy() ?? [], $start );
	}

	/**
	 * Parse a billing payload with the engine's `BillingPolicy` and compute the first renewal.
	 *
	 * @param array<string, mixed> $policy Billing payload.
	 * @param DateTimeImmutable    $start  Contract start.
	 * @throws DomainException When the payload does not parse or has no usable cadence.
	 */
	private static function parse_first_renewal( array $policy, DateTimeImmutable $start ): DateTimeImmutable {
		return BillingPolicy::from_array( $policy )->compute_first_renewal_from( $start );
	}

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
	public static function get_period_to_bill( DateTimeImmutable $anchor, DateTimeImmutable $now, BillingPolicy $policy ): array {
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
			self::log_cap( 'get_period_to_bill', $anchor );
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
	public static function get_next_due_after( DateTimeImmutable $from, DateTimeImmutable $now, BillingPolicy $policy ): DateTimeImmutable {
		$utc  = new DateTimeZone( 'UTC' );
		$next = $from->setTimezone( $utc );
		$now  = $now->setTimezone( $utc );

		$rolls_left = self::MAX_ROLLS;
		while ( $next <= $now && $rolls_left-- > 0 ) {
			$next = $policy->compute_next_renewal_from( $next );
		}

		if ( $next <= $now ) {
			self::log_cap( 'get_next_due_after', $from );
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
			sprintf( 'BillingTerms::%s(): rolling forward from %s exhausted the roll cap; floored at now.', $method, $from->format( 'Y-m-d H:i:s' ) ),
			[ 'source' => Package::EXTENSION_SLUG ]
		);
	}

	/**
	 * Tolerant parse of a `billing_policy` payload; null when it has no usable cadence.
	 *
	 * @param mixed $policy Raw billing_policy payload.
	 */
	private static function from_policy( $policy ): ?self {
		if ( ! is_array( $policy ) ) {
			return null;
		}

		$period   = $policy['period'] ?? null;
		$interval = self::positive_int( $policy['interval'] ?? null );
		if ( ! is_string( $period ) || ! in_array( $period, self::PERIODS, true ) || null === $interval ) {
			return null;
		}

		$trial  = $policy['trial_duration'] ?? null;
		$length = is_array( $trial ) ? self::positive_int( $trial['length'] ?? null ) : null;
		$unit   = is_array( $trial ) ? ( $trial['unit'] ?? null ) : null;
		$trial  = null !== $length && is_string( $unit ) && in_array( $unit, self::PERIODS, true )
			? [
				'length' => $length,
				'unit'   => $unit,
			]
			: null;

		return new self( $period, $interval, self::positive_int( $policy['max_cycles'] ?? null ), $trial );
	}

	/**
	 * Billing period (day / week / month / year).
	 */
	public function get_period(): string {
		return $this->period;
	}

	/**
	 * Periods per billing cycle.
	 */
	public function get_interval(): int {
		return $this->interval;
	}

	/**
	 * Maximum billing cycles, or null when open-ended.
	 */
	public function get_max_cycles(): ?int {
		return $this->max_cycles;
	}

	/**
	 * Free trial (`length` + `unit`), or null.
	 *
	 * @return array{length: int, unit: string}|null
	 */
	public function get_trial_duration(): ?array {
		return $this->trial_duration;
	}

	/**
	 * A positive integer from an int or an integer string, else null.
	 *
	 * Strings follow the engine's integer rule (`FILTER_VALIDATE_INT`, as in its
	 * `Coercion`), so a cycle limit reads the same here as in the engine's parse:
	 * `'5'` and `'+5'` pass, while `'007'`, `'1.5'` and `'1e2'` do not.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function positive_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$validated = filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );

		return false === $validated ? null : $validated;
	}
}
