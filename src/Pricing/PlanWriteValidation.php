<?php
/**
 * PlanWriteValidation - validates and normalizes Lite's pricing terms when the
 * engine asks a Lite-owned plan's owner to validate a write.
 *
 * Hooks the engine's plan validation filter, which passes the plan about to be
 * written on every create and update. Only Lite-owned plans are touched: the
 * present pricing term keys (`policies`, `one_time_fees`) are validated and
 * normalized; other top-level pricing keys pass through unchanged.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Write-path validation for Lite-owned plans.
 */
final class PlanWriteValidation {

	private const TERM_KEYS = [ 'policies', 'one_time_fees' ];

	/**
	 * Register the engine plan validation filter.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_subscriptions_engine_validate_plan', [ new self(), 'validate_plan' ], 10, 2 );
	}

	/**
	 * Validate and normalize a Lite plan's pricing terms.
	 *
	 * @param mixed  $plan           Plan about to be written, or an earlier handler's WP_Error.
	 * @param string $extension_slug Owning extension slug.
	 * @return mixed The plan with normalized terms, a 400 WP_Error for invalid terms, or the input untouched.
	 */
	public function validate_plan( $plan, string $extension_slug ) {
		if ( ! $plan instanceof Plan || Package::EXTENSION_SLUG !== $extension_slug ) {
			return $plan;
		}

		$pricing_policy = $plan->get_pricing_policy();
		if ( null === $pricing_policy ) {
			return $plan;
		}

		$provided = array_intersect_key( $pricing_policy, array_flip( self::TERM_KEYS ) );
		$errors   = PricingTerms::validate( $provided );
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'rest_invalid_param', $errors[0], [ 'status' => 400 ] );
		}

		$normalized = array_intersect_key( PricingTerms::from_array( $provided )->to_array(), $provided );
		$plan->set_pricing_policy( array_merge( $pricing_policy, $normalized ) );

		return $plan;
	}
}
