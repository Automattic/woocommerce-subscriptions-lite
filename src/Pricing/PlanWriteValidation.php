<?php
/**
 * PlanWriteValidation - validates Lite's billing and pricing terms when the
 * engine asks a plan's owner to validate a write.
 *
 * Hooks the engine's plan validation action, which fires before every plan
 * create and update (PHP facade or REST) with an error collector and a view of
 * the would-be plan. Only Lite-owned plans are checked: the billing payload must
 * carry a cadence Lite can bill (see {@see self::billing_errors()}), and the
 * pricing terms (`policies`, `one_time_fees`; other keys are ignored) are
 * validated. Each problem is added to the collector; the plan is stored exactly
 * as sent.
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
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Write-path validation for Lite-owned plans.
 */
final class PlanWriteValidation {

	private const BILLING_MESSAGE = 'billing_policy must have a period (day, week, month or year) and a positive interval.';

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

		foreach ( self::billing_errors( $plan ) as $message ) {
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
	 * Problems with the plan's billing payload.
	 *
	 * The payload must read as {@see BillingTerms} (storefront display) and parse
	 * strictly through the engine's `BillingPolicy`, including a first renewal
	 * date, as contract creation does at checkout: an integer interval, a known
	 * trial unit, consistent cycle bounds.
	 *
	 * @param PlanView $plan Would-be plan.
	 * @return array<int, string> Error messages; empty when usable.
	 */
	private static function billing_errors( PlanView $plan ): array {
		$policy = $plan->get_billing_policy();
		if ( null === $policy || null === BillingTerms::from_plan( $plan ) || ! is_int( $policy['interval'] ?? null ) ) {
			return [ self::BILLING_MESSAGE ];
		}

		try {
			BillingPolicy::from_array( $policy )->compute_first_renewal_from( new DateTimeImmutable( '2000-01-01', new DateTimeZone( 'UTC' ) ) );
		} catch ( DomainException $e ) {
			return [ 'billing_policy: ' . $e->getMessage() ];
		}

		return [];
	}
}
