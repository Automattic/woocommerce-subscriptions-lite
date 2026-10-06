<?php
/**
 * PriceCalculator - Lite's single source of plan price math.
 *
 * Applies a plan's {@see PricingTerms} policy chain per billing cycle:
 *  - `percentage`   -> `price * (100 - value) / 100`
 *  - `fixed_amount` -> `max(0, price - value)`
 *  - `price`        -> `value` (replaces the price)
 *  - `bogo`         -> no price change; grants one bonus unit per paid unit
 *    ({@see self::bonus_quantity()})
 *
 * Entries apply in order, each on the previous result. `starting_cycle` skips
 * an entry before that cycle; `duration_cycles` ends it after that many cycles.
 * One-time fees are not applied here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Price calculator over a set of pricing terms.
 */
final class PriceCalculator {

	/**
	 * Terms the calculator applies.
	 *
	 * @var PricingTerms
	 */
	private $terms;

	/**
	 * Build a calculator for the given terms.
	 *
	 * @param PricingTerms $terms Pricing terms.
	 */
	public function __construct( PricingTerms $terms ) {
		$this->terms = $terms;
	}

	/**
	 * Calculator for a live plan's stored terms.
	 *
	 * @param Plan $plan Plan.
	 */
	public static function for_plan( Plan $plan ): self {
		return new self( PricingTerms::from_plan( $plan ) );
	}

	/**
	 * The terms the calculator applies.
	 */
	public function get_terms(): PricingTerms {
		return $this->terms;
	}

	/**
	 * Unit price after the policy chain for a cycle.
	 *
	 * @param float $base_price Base unit price.
	 * @param int   $cycle      1-indexed billing cycle.
	 */
	public function unit_price( float $base_price, int $cycle = 1 ): float {
		$price = $base_price;

		foreach ( $this->terms->get_policies() as $policy ) {
			if ( ! self::applies_to_cycle( $policy, $cycle ) ) {
				continue;
			}

			switch ( $policy['type'] ) {
				case PricingTerms::TYPE_PERCENTAGE:
					$price = $price * ( 100 - $policy['value'] ) / 100;
					break;
				case PricingTerms::TYPE_FIXED_AMOUNT:
					$price = max( 0.0, $price - $policy['value'] );
					break;
				case PricingTerms::TYPE_PRICE:
					$price = $policy['value'];
					break;
			}
		}

		return $price;
	}

	/**
	 * Line total from the effective unit price, clamped at zero.
	 *
	 * @param float $base_price Base unit price, before the plan's adjustments.
	 * @param float $quantity   Line quantity.
	 * @param int   $cycle      1-indexed billing cycle.
	 */
	public function line_total( float $base_price, float $quantity, int $cycle = 1 ): float {
		return max( 0.0, $this->unit_price( $base_price, $cycle ) * $quantity );
	}

	/**
	 * Bonus units for a cycle: the paid quantity once per in-scope `bogo` entry.
	 * Zero when no `bogo` entry applies or the paid quantity is not positive.
	 *
	 * @param float $paid_quantity Paid units on the line.
	 * @param int   $cycle         1-indexed billing cycle.
	 */
	public function bonus_quantity( float $paid_quantity, int $cycle = 1 ): float {
		if ( $paid_quantity <= 0 ) {
			return 0.0;
		}

		$bonus = 0.0;
		foreach ( $this->terms->get_policies() as $policy ) {
			if ( PricingTerms::TYPE_BOGO === $policy['type'] && self::applies_to_cycle( $policy, $cycle ) ) {
				$bonus += $paid_quantity;
			}
		}

		return $bonus;
	}

	/**
	 * Whether a policy entry's cycle gates include the cycle.
	 *
	 * @param array{starting_cycle?: int, duration_cycles?: int} $policy Policy entry.
	 * @param int                                                $cycle  1-indexed billing cycle.
	 */
	private static function applies_to_cycle( array $policy, int $cycle ): bool {
		$starting_cycle = $policy['starting_cycle'] ?? 1;
		if ( $cycle < $starting_cycle ) {
			return false;
		}

		return ! isset( $policy['duration_cycles'] ) || $cycle <= $starting_cycle + $policy['duration_cycles'] - 1;
	}
}
