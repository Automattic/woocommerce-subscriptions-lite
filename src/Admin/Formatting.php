<?php
/**
 * Formatting - shared date and money rendering for the admin screens.
 *
 * Wraps WooCommerce's `wc_price()` and WordPress's `date_i18n()` so the list
 * table and detail renderer format engine values (GMT timestamp strings,
 * decimal-safe money strings) the same way, with graceful fallbacks when the
 * value is empty or the WooCommerce helper is unavailable.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Date and money formatting helpers.
 */
final class Formatting {

	/**
	 * The em-dash-free placeholder shown when a value is absent.
	 */
	const PLACEHOLDER = '-';

	/**
	 * Format a GMT timestamp string into the site's date format and timezone.
	 *
	 * @param string|null $gmt Engine GMT timestamp (`Y-m-d H:i:s`), or null.
	 * @return string Localized date, or the placeholder when absent/unparseable.
	 */
	public static function date( ?string $gmt ): string {
		if ( null === $gmt || '' === $gmt ) {
			return self::PLACEHOLDER;
		}

		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return self::PLACEHOLDER;
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
	 * Format a billing cadence as plain text (`Every 3 months`).
	 *
	 * Matches the wording of the admin product-panel Frequency column so a
	 * plan reads the same on the product screen and the subscription detail
	 * screen. Escape with esc_html() when rendering.
	 *
	 * @param string $period   Period unit: 'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Period count (coerced to a minimum of 1).
	 */
	public static function billing_cadence( string $period, int $interval ): string {
		$interval = max( 1, $interval );

		return sprintf(
			/* translators: 1: billing interval count, 2: pluralized billing period (e.g. "month", "months"). */
			__( 'Every %1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$interval,
			self::period_label( $period, $interval )
		);
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
			return self::PLACEHOLDER;
		}

		return sprintf(
			/* translators: 1: trial length, 2: pluralized period (e.g. "day", "days"). */
			__( '%1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$length,
			self::period_label( $unit, $length )
		);
	}

	/**
	 * Pluralized period label via _n(), so locales with non-trivial plural rules
	 * translate each unit independently. Unknown periods fall through to the raw
	 * value.
	 *
	 * @param string $period   One of 'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Interval count driving pluralization.
	 */
	private static function period_label( string $period, int $interval ): string {
		switch ( $period ) {
			case 'day':
				return _n( 'day', 'days', $interval, 'woocommerce-subscriptions-lite' );
			case 'week':
				return _n( 'week', 'weeks', $interval, 'woocommerce-subscriptions-lite' );
			case 'month':
				return _n( 'month', 'months', $interval, 'woocommerce-subscriptions-lite' );
			case 'year':
				return _n( 'year', 'years', $interval, 'woocommerce-subscriptions-lite' );
			default:
				return $period;
		}
	}
}
