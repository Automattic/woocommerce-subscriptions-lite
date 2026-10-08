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
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
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
	public static function first_renewal_from( PlanView $plan, DateTimeImmutable $start ): DateTimeImmutable {
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
