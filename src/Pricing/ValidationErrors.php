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
 * plans REST route reports Lite's errors and the engine's the same way.
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
}
