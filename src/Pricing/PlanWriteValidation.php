<?php
/**
 * PlanWriteValidation - validates Lite's billing and pricing terms when the
 * engine asks a plan's owner to validate a write.
 *
 * Hooks the engine's plan validation action, which fires before every plan
 * create and update (PHP facade or REST) with an error collector and a view of
 * the would-be plan. Only Lite-owned plans are checked: the billing payload must be
 * billable ({@see BillingTerms::validate()}), and the pricing terms (`policies`,
 * `one_time_fees`; other keys are ignored) are validated. Billing is checked on
 * every create, on every write that leaves the plan active, and on any write that
 * changes the stored billing payload, whatever the status (an archived plan still
 * prices the carts and orders that carry it). Only a write to a plan that is not
 * active and keeps its stored billing skips it, so a plan with unusable stored
 * billing can still be archived; restoring it checks billing again. Each problem is
 * added to the collector; the plan is stored exactly as sent.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
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
	 * Add an error for each invalid billing or pricing term of a Lite plan.
	 *
	 * @param WP_Error $errors         Error collector.
	 * @param PlanView $plan           View of the plan about to be written.
	 * @param string   $extension_slug Owning extension slug.
	 */
	public function validate_plan( WP_Error $errors, PlanView $plan, string $extension_slug ): void {
		if ( Package::EXTENSION_SLUG !== $extension_slug ) {
			return;
		}

		$billing_errors = $this->billing_needs_validation( $plan ) ? BillingTerms::validate( $plan->get_billing_policy() ) : [];
		foreach ( $billing_errors as $message ) {
			$errors->add( 'rest_invalid_param', $message, [ 'status' => 400 ] );
		}

		$pricing_policy = $plan->get_pricing_policy();
		if ( null === $pricing_policy ) {
			return;
		}

		foreach ( PricingTerms::validate( $pricing_policy ) as $message ) {
			$errors->add( 'rest_invalid_param', $message, [ 'status' => 400 ] );
		}
	}

	/**
	 * Whether the write must pass the billing check: a create, an active plan, or a
	 * billing payload that differs from the stored one.
	 *
	 * @param PlanView $plan View of the plan about to be written.
	 */
	private function billing_needs_validation( PlanView $plan ): bool {
		if ( 0 === $plan->get_id() || PlanStatus::ACTIVE === $plan->get_status() ) {
			return true;
		}

		$stored = Plans::get( $plan->get_id() );

		return null === $stored || $stored->get_billing_policy() !== $plan->get_billing_policy();
	}
}
