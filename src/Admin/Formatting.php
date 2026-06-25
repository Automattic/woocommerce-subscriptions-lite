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
}
