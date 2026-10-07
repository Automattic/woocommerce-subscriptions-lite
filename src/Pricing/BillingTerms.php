<?php
/**
 * BillingTerms - Lite's reader of a plan's billing terms.
 *
 * Reads are tolerant and never throw: a missing plan, or a billing policy without a
 * usable period and positive interval, reads as no terms (null); malformed optional
 * fields read as absent.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;

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
	 * Terms from a plan's billing policy; null when there is no plan or the policy is unusable.
	 *
	 * @param Plan|null $plan Plan.
	 */
	public static function from_plan( ?Plan $plan ): ?self {
		if ( null === $plan ) {
			return null;
		}

		$policy   = $plan->get_billing_policy();
		$period   = $policy->get_period();
		$interval = $policy->get_interval();
		if ( ! in_array( $period, self::PERIODS, true ) || $interval <= 0 ) {
			return null;
		}

		$trial = $policy->get_trial_duration();
		if ( null !== $trial && ( $trial['length'] <= 0 || ! in_array( $trial['unit'], self::PERIODS, true ) ) ) {
			$trial = null;
		}

		$max_cycles = $policy->get_max_cycles();

		return new self( $period, $interval, null !== $max_cycles && $max_cycles > 0 ? $max_cycles : null, $trial );
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
