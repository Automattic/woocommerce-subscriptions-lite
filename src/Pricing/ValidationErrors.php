<?php
/**
 * ValidationErrors - builds Lite's own plan validation errors in the engine's error shape.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Pricing
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ValidationErrorCode;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an error with the `field` and `params` data the engine's errors carry, so the
 * plans REST route reports Lite's errors and the engine's the same way. The errors the
 * billing and pricing checks share have one builder each, so each message has one string.
 */
final class ValidationErrors {

	/**
	 * Add one error with its `field` and `params` data.
	 *
	 * @param WP_Error             $errors  Error collector.
	 * @param string               $code    One of {@see ValidationErrorCode}.
	 * @param string               $field   Field path, in dot notation (`pricing_policy.policies.0.value`).
	 * @param string               $message Translated, field-free message.
	 * @param array<string, mixed> $params  Values the code's message needs.
	 */
	public static function add( WP_Error $errors, string $code, string $field, string $message, array $params = [] ): void {
		$errors->add(
			$code,
			$message,
			[
				'field'  => $field,
				'params' => $params,
			]
		);
	}

	/**
	 * Add a `required` error.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  Field path.
	 */
	public static function add_required( WP_Error $errors, string $field ): void {
		self::add( $errors, ValidationErrorCode::REQUIRED, $field, __( 'This field is required.', 'woocommerce-subscriptions-lite' ) );
	}

	/**
	 * Add an `invalid_type` error for a value that is not an integer.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  Field path.
	 */
	public static function add_not_integer( WP_Error $errors, string $field ): void {
		self::add(
			$errors,
			ValidationErrorCode::INVALID_TYPE,
			$field,
			__( 'Must be an integer.', 'woocommerce-subscriptions-lite' ),
			[ 'type' => 'integer' ]
		);
	}

	/**
	 * Add an `out_of_bounds` error for a number below `$min`.
	 *
	 * @param WP_Error $errors Error collector.
	 * @param string   $field  Field path.
	 * @param int      $min    Smallest allowed value.
	 */
	public static function add_below_minimum( WP_Error $errors, string $field, int $min ): void {
		self::add( $errors, ValidationErrorCode::OUT_OF_BOUNDS, $field, self::get_below_minimum_message( $min ), [ 'min' => $min ] );
	}

	/**
	 * Message for a number below `$min`: "Must not be negative." for 0.
	 *
	 * @param int $min Smallest allowed value.
	 */
	public static function get_below_minimum_message( int $min ): string {
		if ( 0 === $min ) {
			return __( 'Must not be negative.', 'woocommerce-subscriptions-lite' );
		}

		/* translators: %d: the smallest allowed value. */
		return sprintf( __( 'Must be %d or greater.', 'woocommerce-subscriptions-lite' ), $min );
	}

	/**
	 * Add a `not_in_enum` error.
	 *
	 * @param WP_Error $errors  Error collector.
	 * @param string   $field   Field path.
	 * @param string[] $allowed The allowed values.
	 */
	public static function add_not_in_enum( WP_Error $errors, string $field, array $allowed ): void {
		self::add(
			$errors,
			ValidationErrorCode::NOT_IN_ENUM,
			$field,
			/* translators: %l: list of allowed values, such as "day, week, month, and year". */
			wp_sprintf( __( 'Must be one of %l.', 'woocommerce-subscriptions-lite' ), $allowed ),
			[ 'allowed' => $allowed ]
		);
	}
}
