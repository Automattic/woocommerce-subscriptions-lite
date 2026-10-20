<?php
/**
 * Test helper: a validation `WP_Error` as a list of code, field and params.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Flattens validation errors so a test can assert every error at once.
 */
trait ListsValidationErrors {

	/**
	 * Every error as `[ code, field, params ]`, in `WP_Error` order (grouped by code, then
	 * in the order added).
	 *
	 * @param WP_Error $errors Validation errors.
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function get_validation_errors( WP_Error $errors ): array {
		$list = [];
		foreach ( $errors->get_error_codes() as $code ) {
			foreach ( $errors->get_all_error_data( $code ) as $data ) {
				$list[] = [ (string) $code, $data['field'], $data['params'] ];
			}
		}

		return $list;
	}
}
