<?php
/**
 * Integers - strict integer parsing of raw payload and storage values.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Utilities
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an integer without truncating or guessing.
 */
final class Integers {

	/**
	 * An integer from an int or an integer string, else null. Strings follow `FILTER_VALIDATE_INT`:
	 * `'5'` and `'+5'` pass, while `'007'`, `'1.5'` and `'1e2'` do not. Stricter than `absint()`
	 * (truncates) and `rest_is_integer()` (accepts `'1e2'` and `1.0`), so neither is used.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function parse( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$validated = filter_var( $value, FILTER_VALIDATE_INT );

		return false === $validated ? null : $validated;
	}
}
