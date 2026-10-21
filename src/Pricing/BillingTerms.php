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
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ValidationErrorCode;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use DateTimeImmutable;
use DateTimeZone;
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
			self::add_required_error( $errors, self::FIELD );
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
			self::add_required_error( $errors, $field );
		} elseif ( ! is_string( $data[ $key ] ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::INVALID_TYPE,
				$field,
				__( 'Must be a string.', 'woocommerce-subscriptions-lite' ),
				[ 'type' => 'string' ]
			);
		} elseif ( ! in_array( $data[ $key ], self::PERIODS, true ) ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::NOT_IN_ENUM,
				$field,
				/* translators: %l: list of billing periods, such as "day, week, month, and year". */
				wp_sprintf( __( 'Must be one of %l.', 'woocommerce-subscriptions-lite' ), self::PERIODS ),
				[ 'allowed' => self::PERIODS ]
			);
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
			self::add_required_error( $errors, $field );
		} elseif ( ! is_int( $data[ $key ] ) ) {
			self::add_integer_error( $errors, $field );
		} elseif ( $data[ $key ] < 1 ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::OUT_OF_BOUNDS,
				$field,
				__( 'Must be 1 or greater.', 'woocommerce-subscriptions-lite' ),
				[ 'min' => 1 ]
			);
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
		$min       = self::int_like( $raw_min );
		$min_ok    = true;
		if ( null !== $raw_min && null === $min ) {
			self::add_integer_error( $errors, $min_field );
			$min_ok = false;
		} elseif ( null !== $min && $min < 0 ) {
			ValidationErrors::add(
				$errors,
				ValidationErrorCode::OUT_OF_BOUNDS,
				$min_field,
				__( 'Must not be negative.', 'woocommerce-subscriptions-lite' ),
				[ 'min' => 0 ]
			);
			$min_ok = false;
		}

		$raw_max = $policy['max_cycles'] ?? null;
		$max     = self::int_like( $raw_max );
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
	 * Add a `required` error.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  Field path.
	 */
	private static function add_required_error( WP_Error $errors, string $field ): void {
		ValidationErrors::add( $errors, ValidationErrorCode::REQUIRED, $field, __( 'This field is required.', 'woocommerce-subscriptions-lite' ) );
	}

	/**
	 * Add an `invalid_type` integer error.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  Field path.
	 */
	private static function add_integer_error( WP_Error $errors, string $field ): void {
		ValidationErrors::add(
			$errors,
			ValidationErrorCode::INVALID_TYPE,
			$field,
			__( 'Must be an integer.', 'woocommerce-subscriptions-lite' ),
			[ 'type' => 'integer' ]
		);
	}

	/**
	 * The first renewal moment of a contract on `$plan` starting at `$start`.
	 *
	 * @param PlanView          $plan  Plan.
	 * @param DateTimeImmutable $start Contract start.
	 * @throws \DomainException When the billing payload is not billable.
	 */
	public static function get_first_renewal_from( PlanView $plan, DateTimeImmutable $start ): DateTimeImmutable {
		return self::parse_first_renewal( $plan->get_billing_policy() ?? [], $start );
	}

	/**
	 * Parse a billing payload with the engine's `BillingPolicy` and compute the first renewal.
	 *
	 * @param array<string, mixed> $policy Billing payload.
	 * @param DateTimeImmutable    $start  Contract start.
	 * @throws \DomainException When the payload does not parse or has no usable cadence.
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
			self::int_like( $policy['max_cycles'] ?? null ),
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

	/**
	 * An integer from an int or an integer string, else null. Strings follow
	 * `FILTER_VALIDATE_INT`: `'5'` and `'+5'` pass, while `'007'`, `'1.5'` and `'1e2'` do not.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function int_like( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$validated = filter_var( $value, FILTER_VALIDATE_INT );

		return false === $validated ? null : $validated;
	}
}
