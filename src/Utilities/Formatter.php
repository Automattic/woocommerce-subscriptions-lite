<?php
/**
 * Formatter - shared, surface-neutral string formatting.
 *
 * A single home for formatting logic that more than one surface renders, so the
 * output reads the same everywhere and each phrase carries one translatable
 * string. It currently owns the billing-period wording (cadence and period
 * labels) plus the absent-value placeholder; other cross-surface formatting can
 * live here as it arises. Every member takes primitives and returns a plain
 * string - it has no `Plan` or other domain-entity dependency.
 *
 * Surfaces keep their own formatter (admin `Formatting`, product/cart
 * `PlanOptionFormatter`, portal `ViewModel`) for their surface-specific wording
 * and delegate these shared primitives here.
 *
 * All methods return plain text; escape with esc_html() when rendering.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Utilities
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Static formatter for shared, cross-surface string formatting.
 */
final class Formatter {

	/**
	 * The em-dash-free placeholder shown when a value is absent. Lives here (not
	 * on a surface class) so any surface can render an absent value the same way
	 * without reaching across layers.
	 */
	const PLACEHOLDER = '-';

	/**
	 * Pluralized period label via `_n()`, so locales with non-trivial plural rules
	 * translate each unit independently. Unknown periods fall through to the raw
	 * value.
	 *
	 * @param string $period   One of 'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Interval count driving pluralization.
	 */
	public static function period_label( string $period, int $interval ): string {
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

	/**
	 * The explicit cadence a merchant reads (`Every 3 months`, and `Every 1 month`
	 * at interval 1, so the exact config is visible). Interval is clamped to a
	 * minimum of 1. Used by the admin detail Schedule and the admin product panel's
	 * Frequency column.
	 *
	 * @param string $period   Period unit: 'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Period count (coerced to a minimum of 1).
	 */
	public static function explicit_cadence( string $period, int $interval ): string {
		$interval = max( 1, $interval );

		return sprintf(
			/* translators: 1: billing interval count, 2: pluralized billing period (e.g. "month", "months"). */
			__( 'Every %1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$interval,
			self::period_label( $period, $interval )
		);
	}

	/**
	 * The price cadence a customer reads next to an amount: `/ month` at interval 1,
	 * else `every 2 weeks`. Used by the PDP picker, the cart line cadence, and the
	 * customer portal's recurring summary, so a single translation covers them all.
	 * Returns '' for an unknown period.
	 *
	 * @param string $period   Period unit: 'day' | 'week' | 'month' | 'year'.
	 * @param int    $interval Period count.
	 */
	public static function price_cadence( string $period, int $interval ): string {
		if ( 1 === $interval ) {
			switch ( $period ) {
				case 'day':
					return __( '/ day', 'woocommerce-subscriptions-lite' );
				case 'week':
					return __( '/ week', 'woocommerce-subscriptions-lite' );
				case 'month':
					return __( '/ month', 'woocommerce-subscriptions-lite' );
				case 'year':
					return __( '/ year', 'woocommerce-subscriptions-lite' );
			}
		}

		switch ( $period ) {
			case 'day':
				/* translators: %d: interval count. */
				return sprintf( _n( 'every %d day', 'every %d days', $interval, 'woocommerce-subscriptions-lite' ), $interval );
			case 'week':
				/* translators: %d: interval count. */
				return sprintf( _n( 'every %d week', 'every %d weeks', $interval, 'woocommerce-subscriptions-lite' ), $interval );
			case 'month':
				/* translators: %d: interval count. */
				return sprintf( _n( 'every %d month', 'every %d months', $interval, 'woocommerce-subscriptions-lite' ), $interval );
			case 'year':
				/* translators: %d: interval count. */
				return sprintf( _n( 'every %d year', 'every %d years', $interval, 'woocommerce-subscriptions-lite' ), $interval );
			default:
				return '';
		}
	}
}
