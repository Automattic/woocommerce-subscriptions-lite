<?php
/**
 * PlanWriteValidation - validates Lite's pricing terms when the engine asks a
 * plan's owner to validate a write.
 *
 * Hooks the engine's plan validation action, which fires on every plan create
 * and update with an error collector and a copy of the plan. Only Lite-owned
 * plans are checked: the pricing terms (`policies`, `one_time_fees`; other keys
 * are ignored) are validated and each problem is added to the collector. The
 * plan is stored exactly as sent.
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

	/**
	 * Register the engine plan validation action.
	 */
	public static function register(): void {
		add_action( 'woocommerce_subscriptions_engine_validate_plan', [ new self(), 'validate_plan' ], 10, 3 );
	}

	/**
	 * Add an error for each invalid pricing term of a Lite plan.
	 *
	 * @param WP_Error $errors         Error collector.
	 * @param Plan     $plan           Copy of the plan about to be written.
	 * @param string   $extension_slug Owning extension slug.
	 */
	public function validate_plan( WP_Error $errors, Plan $plan, string $extension_slug ): void {
		if ( Package::EXTENSION_SLUG !== $extension_slug ) {
			return;
		}

		$pricing_policy = $plan->get_pricing_policy();
		if ( null === $pricing_policy ) {
			return;
		}

		foreach ( PricingTerms::validate( $pricing_policy ) as $message ) {
			$errors->add( 'rest_invalid_param', $message, [ 'status' => 400 ] );
		}
	}
}
