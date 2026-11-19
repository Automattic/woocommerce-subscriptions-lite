<?php
/**
 * BillingTerms - Lite's reader of the billing payload it writes on its plans.
 *
 * The engine stores plan policies as opaque extension payloads; Lite owns the shape of
 * `billing_policy`:
 *   { period: day|week|month|year, interval: int, min_cycles: ?int, max_cycles: ?int,
 *     trial_duration: { length: int, unit: day|week|month|year } | null }
 *
 * {@see self::validate()} is the one rule for that payload and reports every problem at once;
 * {@see self::is_billable()} is true exactly when it reports no errors. A live plan is read
 * strictly: {@see self::from_plan()} returns terms only when the payload is billable. The
 * storefront, the cart, checkout, contract creation and renewal all use that one rule. A
 * missing plan reads as no terms (null). Reads never throw.
 *
 * It also turns the cadence into dates: the first renewal of a new contract, the period a
 * renewal bills and the next due moment after it. Day and week steps are UTC instant
 * arithmetic. Month and year steps (a year is 12 months) are read in the store calendar
 * ({@see wp_timezone()}), keep the local time of day and land on the billing day, clamped to
 * the last day of a shorter month: billing day 31 runs 31 Jan, 28 Feb, 31 Mar, 30 Apr. Results
 * are in UTC. Catch-up rule: a contract that comes back
 * due late (after a hold, an owner absence or a cron outage) bills one period - the one
 * containing now - and its next due moment is the end of that period, always in the future.
 * Periods follow the plan's cadence from the due anchor, never from the previous cycle's end.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ValidationErrorCode;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Integers;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable billing terms.
 */
final class BillingTerms {

	private const PERIODS = [ 'day', 'week', 'month', 'year' ];

	private const FIELD = 'billing_policy';

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
	 * Day of the month (1-31) month and year steps keep, or null to keep the day of the moment
	 * each roll starts from.
	 *
	 * @var int|null
	 */
	private $billing_day;

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
		$this->billing_day    = null;
	}

	/**
	 * A copy of these terms whose month and year steps land on `$billing_day`, clamped to the
	 * last day of a shorter month.
	 *
	 * @param int $billing_day Day of the month, 1-31.
	 * @throws InvalidArgumentException When the day is outside 1-31.
	 */
	public function with_billing_day( int $billing_day ): self {
		if ( $billing_day < 1 || $billing_day > 31 ) {
			throw new InvalidArgumentException( sprintf( 'The billing day must be between 1 and 31, %d given.', $billing_day ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an integer, not output.
		}

		$terms              = clone $this;
		$terms->billing_day = $billing_day;

		return $terms;
	}

	/**
	 * The billing day a contract starting at `$start` keeps, in the store calendar: the day of
	 * the start, or of the trial end for a trial in days or weeks. A trial in months or years
	 * keeps the start's day (31 Jan with a 1 month trial renews 28 Feb, then 31 Mar).
	 *
	 * @param DateTimeImmutable $start Contract start.
	 */
	public function get_billing_day_from( DateTimeImmutable $start ): int {
		$trial = $this->trial_duration;
		if ( null !== $trial && in_array( $trial['unit'], [ 'day', 'week' ], true ) ) {
			return self::get_store_day( self::add_instant( $start, $trial['length'], $trial['unit'] ) );
		}

		return self::get_store_day( $start );
	}

	/**
	 * Terms from a plan's `billing_policy`; null when there is no plan or it is absent or not billable
	 * (see {@see self::is_billable()}).
	 *
	 * @param PlanView|null $plan Plan.
	 */
	public static function from_plan( ?PlanView $plan ): ?self {
		if ( null === $plan ) {
			return null;
		}

		$policy = $plan->get_billing_policy();

		return self::is_billable( $policy ) ? self::from_policy( $policy ) : null;
	}

	/**
	 * Whether a `billing_policy` payload can be billed: {@see self::validate()} reports no errors.
	 *
	 * @param array<string, mixed>|null $policy Billing payload.
	 */
	public static function is_billable( ?array $policy ): bool {
		return ! self::validate( $policy )->has_errors();
	}

	/**
	 * Problems that keep a `billing_policy` payload from being billed, every one at once, in
	 * field order. Each error carries a validation error code, a translated field-free
	 * message and `field` (dot path, such as `billing_policy.trial_duration.unit`) / `params` data.
	 *
	 * @param array<string, mixed>|null $policy Billing payload.
	 * @return WP_Error Validation errors; none when billable.
	 */
	public static function validate( ?array $policy ): WP_Error {
		$errors = new WP_Error();

		if ( null === $policy ) {
			ValidationErrors::add_required( $errors, self::FIELD );
			return $errors;
		}

		self::validate_unit( $errors, $policy, 'period', self::FIELD . '.period' );
		self::validate_count( $errors, $policy, 'interval', self::FIELD . '.interval' );
		self::validate_cycle_bounds( $errors, $policy );

		$trial = $policy['trial_duration'] ?? null;
		if ( null !== $trial && ! is_array( $trial ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::INVALID_TYPE,
				self::FIELD . '.trial_duration',
				__( 'Must be an object or null.', 'woocommerce-subscriptions-lite' ),
				[ 'type' => 'object' ]
			);
		} elseif ( is_array( $trial ) ) {
			self::validate_count( $errors, $trial, 'length', self::FIELD . '.trial_duration.length' );
			self::validate_unit( $errors, $trial, 'unit', self::FIELD . '.trial_duration.unit' );
		}

		return $errors;
	}

	/**
	 * Add the error of a unit field (period, trial unit): required, a string, one of {@see self::PERIODS}.
	 *
	 * @param WP_Error                $errors Error collector.
	 * @param array<array-key, mixed> $data   Payload holding the field.
	 * @param string                  $key    Key in `$data`.
	 * @param string                  $field  Field path.
	 */
	private static function validate_unit( WP_Error $errors, array $data, string $key, string $field ): void {
		if ( ! array_key_exists( $key, $data ) ) {
			ValidationErrors::add_required( $errors, $field );
		} elseif ( ! is_string( $data[ $key ] ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::INVALID_TYPE,
				$field,
				__( 'Must be a string.', 'woocommerce-subscriptions-lite' ),
				[ 'type' => 'string' ]
			);
		} elseif ( ! in_array( $data[ $key ], self::PERIODS, true ) ) {
			ValidationErrors::add_not_in_enum( $errors, $field, self::PERIODS );
		}
	}

	/**
	 * Add the error of a count field (interval, trial length): required, a real integer, 1 or greater.
	 *
	 * @param WP_Error                $errors Error collector.
	 * @param array<array-key, mixed> $data   Payload holding the field.
	 * @param string                  $key    Key in `$data`.
	 * @param string                  $field  Field path.
	 */
	private static function validate_count( WP_Error $errors, array $data, string $key, string $field ): void {
		if ( ! array_key_exists( $key, $data ) ) {
			ValidationErrors::add_required( $errors, $field );
		} elseif ( ! is_int( $data[ $key ] ) ) {
			ValidationErrors::add_not_integer( $errors, $field );
		} elseif ( $data[ $key ] < 1 ) {
			ValidationErrors::add_below_minimum( $errors, $field, 1 );
		}
	}

	/**
	 * Add the cycle bound errors: each bound's own, then `min_cycles` above `max_cycles` only
	 * when both are set and passed their own checks.
	 *
	 * @param WP_Error             $errors Error collector.
	 * @param array<string, mixed> $policy Billing payload.
	 */
	private static function validate_cycle_bounds( WP_Error $errors, array $policy ): void {
		$min_field = self::FIELD . '.min_cycles';
		$raw_min   = $policy['min_cycles'] ?? null;
		$min       = Integers::parse( $raw_min );
		$min_ok    = true;
		if ( null !== $raw_min && null === $min ) {
			ValidationErrors::add_not_integer( $errors, $min_field );
			$min_ok = false;
		} elseif ( null !== $min && $min < 0 ) {
			ValidationErrors::add_below_minimum( $errors, $min_field, 0 );
			$min_ok = false;
		}

		$raw_max = $policy['max_cycles'] ?? null;
		$max     = Integers::parse( $raw_max );
		$max_ok  = null === $raw_max || ( null !== $max && $max >= 1 );
		if ( ! $max_ok ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::OUT_OF_BOUNDS,
				self::FIELD . '.max_cycles',
				__( 'Must be a positive integer, or empty for no limit.', 'woocommerce-subscriptions-lite' ),
				[ 'min' => 1 ]
			);
		}

		if ( $min_ok && $max_ok && null !== $min && null !== $max && $min > $max ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::OUT_OF_BOUNDS,
				$min_field,
				/* translators: %d: the maximum number of billing cycles. */
				sprintf( __( 'Must not exceed %d.', 'woocommerce-subscriptions-lite' ), $max ),
				[ 'max' => $max ]
			);
		}
	}

	/**
	 * The first renewal of a contract starting at `$start` (UTC): the end of the trial when the
	 * terms have one, else one cadence after the start, on {@see self::get_billing_day_from()}.
	 *
	 * @param DateTimeImmutable $start Contract start.
	 */
	public function get_first_renewal_from( DateTimeImmutable $start ): DateTimeImmutable {
		$billing_day = $this->get_billing_day_from( $start );
		$trial       = $this->trial_duration;
		if ( null === $trial ) {
			return $this->get_next_renewal_from( $start, $billing_day );
		}

		return self::add( $start, $trial['length'], $trial['unit'], $billing_day );
	}

	/**
	 * One cadence after `$anchor`, in UTC. A month or year step lands on `$billing_day`, clamped
	 * to the last day of a shorter month.
	 *
	 * @param DateTimeImmutable $anchor      The moment to add the cadence to.
	 * @param int               $billing_day Day of the month month and year steps keep.
	 */
	private function get_next_renewal_from( DateTimeImmutable $anchor, int $billing_day ): DateTimeImmutable {
		return self::add( $anchor, $this->interval, $this->period, $billing_day );
	}

	/**
	 * The billing day a roll from `$from` keeps: the terms' own, else the day of `$from` in the
	 * store calendar.
	 *
	 * @param DateTimeImmutable $from The moment the roll starts from.
	 */
	private function get_billing_day_for_roll( DateTimeImmutable $from ): int {
		return $this->billing_day ?? self::get_store_day( $from );
	}

	/**
	 * `$from` plus `$length` units, in UTC: days and weeks as instant arithmetic, months and
	 * years (12 months each) in the store calendar on `$billing_day`.
	 *
	 * @param DateTimeImmutable $from        The moment to add to.
	 * @param int               $length      Number of units.
	 * @param string            $unit        day, week, month or year.
	 * @param int               $billing_day Day of the month month and year steps keep.
	 */
	private static function add( DateTimeImmutable $from, int $length, string $unit, int $billing_day ): DateTimeImmutable {
		if ( 'month' === $unit ) {
			return self::add_months( $from, $length, $billing_day );
		}
		if ( 'year' === $unit ) {
			return self::add_months( $from, 12 * $length, $billing_day );
		}

		return self::add_instant( $from, $length, $unit );
	}

	/**
	 * `$from` plus `$length` days or weeks, as UTC instant arithmetic.
	 *
	 * @param DateTimeImmutable $from   The moment to add to.
	 * @param int               $length Number of units.
	 * @param string            $unit   day or week.
	 */
	private static function add_instant( DateTimeImmutable $from, int $length, string $unit ): DateTimeImmutable {
		return $from->setTimezone( new DateTimeZone( 'UTC' ) )->modify( sprintf( '+%d %s', $length, $unit ) );
	}

	/**
	 * `$from` plus `$months` in the store calendar, on `$billing_day` or the target month's last
	 * day when it is shorter, at the same local time of day; returned in UTC. Custom because PHP's
	 * `+N month` overflows a short month (31 Jan + 1 month = 3 Mar) and neither WordPress nor
	 * WooCommerce has a clamped month helper.
	 *
	 * @param DateTimeImmutable $from        The moment to add to.
	 * @param int               $months      Number of months.
	 * @param int               $billing_day Day of the month to land on, 1-31.
	 */
	private static function add_months( DateTimeImmutable $from, int $months, int $billing_day ): DateTimeImmutable {
		$local  = $from->setTimezone( wp_timezone() );
		$index  = (int) $local->format( 'Y' ) * 12 + (int) $local->format( 'n' ) - 1 + $months;
		$year   = intdiv( $index, 12 );
		$month  = $index % 12 + 1;
		$target = $local->setDate( $year, $month, 1 );
		$target = $target->setDate( $year, $month, min( $billing_day, (int) $target->format( 't' ) ) );

		return $target->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The day of the month of `$moment` in the store calendar.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 */
	private static function get_store_day( DateTimeImmutable $moment ): int {
		return (int) $moment->setTimezone( wp_timezone() )->format( 'j' );
	}

	/**
	 * The period to bill: it starts at `$anchor` rolled forward by whole cadences while the
	 * following boundary is not after `$now`, so it contains `$now` (or starts at a future
	 * anchor). Month and year steps keep the terms' billing day, else the anchor's day. When the
	 * roll cap runs out the period starts at `$now`, on now's own day (logged).
	 *
	 * @param DateTimeImmutable $anchor The due moment the renewal was anchored on.
	 * @param DateTimeImmutable $now    The current moment.
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Period start and end (UTC).
	 */
	public function get_period_to_bill( DateTimeImmutable $anchor, DateTimeImmutable $now ): array {
		$utc         = new DateTimeZone( 'UTC' );
		$start       = $anchor->setTimezone( $utc );
		$now         = $now->setTimezone( $utc );
		$billing_day = $this->get_billing_day_for_roll( $start );
		$end         = $this->get_next_renewal_from( $start, $billing_day );

		$rolls_left = self::MAX_ROLLS;
		while ( $end <= $now && $rolls_left-- > 0 ) {
			$start = $end;
			$end   = $this->get_next_renewal_from( $start, $billing_day );
		}

		if ( $end <= $now ) {
			self::log_cap( 'get_period_to_bill', $anchor );
			$start = $now;
			$end   = $this->get_next_renewal_from( $start, self::get_store_day( $now ) );
		}

		return [ $start, $end ];
	}

	/**
	 * The next due moment from `$from`: kept when it is after `$now`, else rolled forward by
	 * whole cadences until it is. Month and year steps keep the terms' billing day, else the day
	 * of `$from`. When the roll cap runs out it is one cadence after `$now`, on now's own day
	 * (logged).
	 *
	 * @param DateTimeImmutable $from The moment to start from (typically a period end).
	 * @param DateTimeImmutable $now  The current moment.
	 */
	public function get_next_due_after( DateTimeImmutable $from, DateTimeImmutable $now ): DateTimeImmutable {
		$utc         = new DateTimeZone( 'UTC' );
		$next        = $from->setTimezone( $utc );
		$now         = $now->setTimezone( $utc );
		$billing_day = $this->get_billing_day_for_roll( $next );

		$rolls_left = self::MAX_ROLLS;
		while ( $next <= $now && $rolls_left-- > 0 ) {
			$next = $this->get_next_renewal_from( $next, $billing_day );
		}

		if ( $next <= $now ) {
			self::log_cap( 'get_next_due_after', $from );
			$next = $this->get_next_renewal_from( $now, self::get_store_day( $now ) );
		}

		return $next;
	}

	/**
	 * Log a roll that ran out of the cap; the caller restarts the cadence from now.
	 *
	 * @param string            $method The method that hit the cap.
	 * @param DateTimeImmutable $from   The moment it rolled from.
	 */
	private static function log_cap( string $method, DateTimeImmutable $from ): void {
		wc_get_logger()->warning(
			sprintf( 'BillingTerms::%s(): rolling forward from %s exhausted the roll cap; restarted from now, one cadence ahead.', $method, $from->format( 'Y-m-d H:i:s' ) ),
			[ 'source' => Package::EXTENSION_SLUG ]
		);
	}

	/**
	 * Terms from a payload {@see self::validate()} accepted; no checks of its own.
	 *
	 * @param array<string, mixed> $policy Billing payload.
	 */
	private static function from_policy( array $policy ): self {
		$trial = $policy['trial_duration'] ?? null;

		return new self(
			$policy['period'],
			$policy['interval'],
			Integers::parse( $policy['max_cycles'] ?? null ),
			is_array( $trial ) ? [
				'length' => $trial['length'],
				'unit'   => $trial['unit'],
			] : null
		);
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
}
