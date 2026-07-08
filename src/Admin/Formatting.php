<?php
/**
 * Formatting - date and money rendering for the admin screens.
 *
 * Wraps WooCommerce's `wc_price()` and WordPress's `date_i18n()` so the list
 * table and detail renderer format engine values (GMT timestamp strings,
 * decimal-safe money strings) the same way, with graceful fallbacks when the
 * value is empty or the WooCommerce helper is unavailable. Cadence and period
 * wording is delegated to the surface-neutral {@see PlanFormatter}, which also
 * owns the shared absent-value placeholder.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Utilities\PlanFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Date and money formatting helpers.
 */
final class Formatting {

	/**
	 * Format a GMT timestamp string into the site's date format and timezone.
	 *
	 * @param string|null $gmt Engine GMT timestamp (`Y-m-d H:i:s`), or null.
	 * @return string Localized date, or the placeholder when absent/unparseable.
	 */
	public static function date( ?string $gmt ): string {
		if ( null === $gmt || '' === $gmt ) {
			return PlanFormatter::PLACEHOLDER;
		}

		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return PlanFormatter::PLACEHOLDER;
		}

		return date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * Format a decimal-safe money string in the given currency.
	 *
	 * Returns `wc_price()` HTML when WooCommerce is loaded (escaped at source);
	 * otherwise an escaped plain amount.
	 *
	 * @param string $amount   Decimal-safe money string.
	 * @param string $currency ISO-4217 currency code.
	 * @return string Price markup safe to echo.
	 */
	public static function price( string $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $amount, [ 'currency' => $currency ] );
		}

		return esc_html( trim( $currency . ' ' . $amount ) );
	}

	/**
	 * Format a billing cadence as plain text (`Every 3 months`) for the detail
	 * Schedule box. Delegates to {@see PlanFormatter::explicit_cadence()}, so it
	 * reads identically to the admin product-panel Frequency column. Escape with
	 * esc_html() when rendering.
	 *
	 * @param string $period   Period unit: 'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Period count (coerced to a minimum of 1).
	 */
	public static function billing_cadence( string $period, int $interval ): string {
		return PlanFormatter::explicit_cadence( $period, $interval );
	}

	/**
	 * Format a native trial duration as plain text (`7 days`), or the placeholder
	 * for a non-positive length. Escape with esc_html() when rendering.
	 *
	 * @param int    $length Trial length.
	 * @param string $unit   Period unit: 'day' | 'week' | 'month' | 'year'.
	 */
	public static function trial_duration( int $length, string $unit ): string {
		if ( $length < 1 ) {
			return PlanFormatter::PLACEHOLDER;
		}

		return sprintf(
			/* translators: 1: trial length, 2: pluralized period (e.g. "day", "days"). */
			__( '%1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$length,
			PlanFormatter::period_label( $unit, $length )
		);
	}
}
