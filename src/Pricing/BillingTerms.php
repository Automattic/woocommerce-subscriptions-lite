<?php
/**
 * BillingTerms - Lite's reader of the billing payload it writes on its plans.
 *
 * The engine stores plan policies as opaque extension payloads; Lite writes
 * `billing_policy` in the array shape the engine's opt-in `BillingPolicy` parser reads:
 *   { period: day|week|month|year, interval: int, min_cycles: ?int, max_cycles: ?int,
 *     trial_duration: { length: int, unit: day|week|month|year } | null }
 *
 * Reads are tolerant and never throw: a missing plan, or a payload without a usable
 * period and positive interval, reads as no terms (null); malformed optional fields
 * read as absent.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable billing terms.
 */
final class BillingTerms {

	private const PERIODS = [ 'day', 'week', 'month', 'year' ];

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
	 * Terms from a plan's `billing_policy`; null when there is no plan or the policy is absent or unusable.
	 *
	 * @param PlanView|null $plan Plan.
	 */
	public static function from_plan( ?PlanView $plan ): ?self {
		$policy = null !== $plan ? $plan->get_billing_policy() : null;
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
	 * A positive integer from an int or digit string, else null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function positive_int( $value ): ?int {
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value > 0 ? $value : null;
	}
}
