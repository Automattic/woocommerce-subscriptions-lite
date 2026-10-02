<?php
/**
 * PlanWriteValidation - validates and normalizes Lite's pricing terms on plan
 * writes through the engine's plans REST controller.
 *
 * Hooks core's `rest_dispatch_request`, which fires after the route's
 * permission callback, and acts only when the matched handler is the engine
 * `PlansController` create or update callback, the request's `extension_slug`
 * is Lite's (the controller scopes updates by that slug, so a Lite-slug write
 * only ever touches a Lite plan) and it carries a `pricing_policy` object. Only
 * the provided top-level keys (`policies`, `one_time_fees`) are validated and
 * normalized; omitted keys keep their stored value through the controller's
 * merge. A non-object payload is left for the controller to reject.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Rest\PlansController;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * REST write-path validation for Lite-owned plans.
 */
final class PlanWriteValidation {

	private const WRITE_CALLBACKS = [ 'create_item', 'update_item' ];

	private const TERM_KEYS = [ 'policies', 'one_time_fees' ];

	/**
	 * Register the REST filter.
	 */
	public static function register(): void {
		add_filter( 'rest_dispatch_request', [ new self(), 'validate_plan_write' ], 10, 4 );
	}

	/**
	 * Validate and normalize a Lite plan write's pricing terms.
	 *
	 * @param mixed                $result  Earlier dispatch result: null, or a value that short-circuits.
	 * @param WP_REST_Request      $request Request.
	 * @param string               $route   Matched route pattern.
	 * @param array<string, mixed> $handler Matched route handler.
	 * @return mixed The earlier result, or a 400 WP_Error for invalid terms.
	 */
	public function validate_plan_write( $result, WP_REST_Request $request, string $route, array $handler ) {
		if ( null !== $result || ! self::is_plan_write_handler( $handler ) ) {
			return $result;
		}

		$slug = $request->get_param( 'extension_slug' );
		if ( ! is_string( $slug ) || Package::EXTENSION_SLUG !== trim( $slug ) ) {
			return $result;
		}

		$pricing_policy = $request->get_param( 'pricing_policy' );
		if ( ! is_array( $pricing_policy ) || ! self::is_object_shaped( $pricing_policy ) ) {
			return $result;
		}

		$provided = array_intersect_key( $pricing_policy, array_flip( self::TERM_KEYS ) );
		$errors   = PricingTerms::validate( $provided );
		if ( ! empty( $errors ) ) {
			return new WP_Error( 'rest_invalid_param', $errors[0], [ 'status' => 400 ] );
		}

		$normalized = PricingTerms::from_array( $provided )->to_array();
		foreach ( array_keys( $provided ) as $key ) {
			$pricing_policy[ $key ] = $normalized[ $key ];
		}
		$request->set_param( 'pricing_policy', $pricing_policy );

		return $result;
	}

	/**
	 * Whether the handler is the engine plans controller's create or update callback.
	 *
	 * @param array<string, mixed> $handler Matched route handler.
	 */
	private static function is_plan_write_handler( array $handler ): bool {
		$callback = $handler['callback'] ?? null;

		return is_array( $callback )
			&& isset( $callback[0], $callback[1] )
			&& $callback[0] instanceof PlansController
			&& in_array( $callback[1], self::WRITE_CALLBACKS, true );
	}

	/**
	 * Whether an array is object-shaped: empty, or with no integer keys.
	 *
	 * @param array<array-key, mixed> $value Value.
	 */
	private static function is_object_shaped( array $value ): bool {
		foreach ( array_keys( $value ) as $key ) {
			if ( is_int( $key ) ) {
				return false;
			}
		}

		return true;
	}
}
