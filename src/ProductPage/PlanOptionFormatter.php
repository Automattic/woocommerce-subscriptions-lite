<?php
/**
 * PlanOptionFormatter - plan price, frequency, and discount display strings.
 *
 * The single source of truth for how a selling plan reads on the product
 * surfaces: the PDP picker option text (`$21.60 every 1 month (10% off)`) and
 * the admin product-panel table's Frequency and Discount columns. The price
 * math lives on the engine's Plan entity (PricingPolicy::calculate_price());
 * this class owns only formatting and i18n.
 *
 * format() and format_discount() carry wc_price()'s inline HTML (the wrapping
 * price span), so callers rendering to HTML escape with wp_kses_post(), never
 * esc_html(). format_frequency() is plain text; escape it with esc_html().
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\ProductPage
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\ProductPage;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;

defined( 'ABSPATH' ) || exit;

/**
 * Static formatter for plan option and column strings.
 *
 * Discount strings inspect only the FIRST pricing-policy entry: Lite's plan
 * UI writes one adjustment per plan, so multi-policy plans render the lead
 * entry's discount only (the price itself always reflects the full chain).
 */
final class PlanOptionFormatter {

	/**
	 * Format a picker option's visible text: `{price} every N {period}` plus
	 * an optional ` ({discount})` suffix. Carries wc_price() HTML.
	 *
	 * @param Plan  $plan       Plan being formatted.
	 * @param float $base_price Base price the plan applies to.
	 * @return string Option text; escape with wp_kses_post() when rendering.
	 */
	public static function format( Plan $plan, float $base_price ): string {
		$policy   = $plan->get_billing_policy();
		$interval = $policy->get_interval();

		$price     = wc_price( $plan->calculate_price( $base_price, 1 ) );
		$frequency = sprintf(
			/* translators: 1: billing interval count, 2: pluralized billing period (e.g. "month", "months"). */
			__( 'every %1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$interval,
			self::period_label( $policy->get_period(), $interval )
		);

		$discount = self::discount_suffix( $plan, $base_price );
		if ( '' === $discount ) {
			return sprintf( '%s %s', $price, $frequency );
		}

		return sprintf( '%s %s (%s)', $price, $frequency, $discount );
	}

	/**
	 * Format a plan's billing cadence for the admin panel table's Frequency
	 * column (`Every 3 months`). Plain text.
	 *
	 * @param Plan $plan Plan being formatted.
	 */
	public static function format_frequency( Plan $plan ): string {
		$policy   = $plan->get_billing_policy();
		$interval = $policy->get_interval();

		return sprintf(
			/* translators: 1: billing interval count, 2: pluralized billing period (e.g. "month", "months"). */
			__( 'Every %1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$interval,
			self::period_label( $policy->get_period(), $interval )
		);
	}

	/**
	 * Format a plan's discount for the admin panel table's Discount column.
	 * Returns the placeholder when the plan carries no discount to show.
	 * Carries wc_price() HTML for amount-based discounts.
	 *
	 * @param Plan  $plan       Plan being formatted.
	 * @param float $base_price Base price the plan applies to.
	 * @return string Discount text; escape with wp_kses_post() when rendering.
	 */
	public static function format_discount( Plan $plan, float $base_price ): string {
		$suffix = self::discount_suffix( $plan, $base_price );

		return '' === $suffix ? Formatting::PLACEHOLDER : $suffix;
	}

	/**
	 * Pluralized period label for a cadence via _n(), so locales with
	 * non-trivial plural rules translate each unit independently. Unknown
	 * periods fall through to the raw value.
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

	/**
	 * Discount string from the first pricing-policy entry, or '' when there is
	 * nothing to show (no policy, zero adjustment, or a price increase).
	 *
	 * Shapes: percentage -> `10% off`; fixed_amount -> `$5.00 off`; price ->
	 * `$8.00 off` only when the replacement price undercuts the base. A
	 * `starting_cycle > 1` appends the `(from cycle N)` qualifier.
	 *
	 * @param Plan  $plan       Plan being formatted.
	 * @param float $base_price Base price the plan applies to.
	 */
	private static function discount_suffix( Plan $plan, float $base_price ): string {
		$pricing_policy = $plan->get_pricing_policy();
		if ( null === $pricing_policy ) {
			return '';
		}

		$policies = $pricing_policy->get_policies();
		if ( empty( $policies ) ) {
			return '';
		}

		$first          = $policies[0];
		$type           = (string) ( $first['type'] ?? '' );
		$value          = (float) ( $first['value'] ?? 0.0 );
		$starting_cycle = isset( $first['starting_cycle'] ) ? (int) $first['starting_cycle'] : 1;

		switch ( $type ) {
			case 'percentage':
				$suffix = self::percentage_off( $value );
				break;
			case 'fixed_amount':
				$suffix = self::amount_off( $value );
				break;
			case 'price':
				// A replacement price only reads as a discount when it undercuts
				// the base; an increase gets no suffix.
				$suffix = self::amount_off( $base_price - $value );
				break;
			default:
				$suffix = '';
		}

		if ( '' === $suffix ) {
			return '';
		}

		if ( $starting_cycle > 1 ) {
			return sprintf(
				/* translators: 1: discount (e.g. "10% off"), 2: cycle number the discount starts at. */
				__( '%1$s (from cycle %2$d)', 'woocommerce-subscriptions-lite' ),
				$suffix,
				$starting_cycle
			);
		}

		return $suffix;
	}

	/**
	 * `N% off`, or '' for a zero adjustment. Whole percentages drop their
	 * decimals (`10%`, not `10.0%`); non-whole values keep precision (`12.5%`).
	 *
	 * @param float $value Percentage value.
	 */
	private static function percentage_off( float $value ): string {
		if ( $value <= 0.0 ) {
			return '';
		}

		$formatted = floor( $value ) === $value
			? (string) (int) $value
			: rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );

		return sprintf(
			/* translators: %s: percentage value (e.g. "10" or "12.5"). */
			__( '%s%% off', 'woocommerce-subscriptions-lite' ),
			$formatted
		);
	}

	/**
	 * `{wc_price(amount)} off`, or '' when the amount is not a discount.
	 *
	 * @param float $amount Discount amount.
	 */
	private static function amount_off( float $amount ): string {
		if ( $amount <= 0.0 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: wc_price()-formatted discount amount. */
			__( '%s off', 'woocommerce-subscriptions-lite' ),
			wc_price( $amount )
		);
	}
}
