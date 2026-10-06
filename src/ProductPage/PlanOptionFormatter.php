<?php
/**
 * PlanOptionFormatter - plan price, frequency, and discount display strings.
 *
 * The single source of truth for how a selling plan reads on the product
 * surfaces: the PDP picker option text (`$21.60 / month (10% off)`) and the
 * admin product-panel table's Frequency and Discount columns. Price math lives
 * in {@see PriceCalculator}; this class owns formatting and i18n.
 *
 * Two cadence phrasings, both keyed off the billing interval:
 *  - price cadence (customer price strings): `/ month` at interval 1, else
 *    `every N months` - identical to the customer portal's recurring summary.
 *  - frequency label (the customer-facing plan name): the adjective `Monthly`
 *    at interval 1, else `Every N months`. The admin `format_frequency()`
 *    column stays explicit (`Every 1 month`) so merchants read the exact config.
 *
 * format() and format_discount() carry wc_price()'s inline HTML (the wrapping
 * price span), so callers rendering to HTML escape with wp_kses_post(), never
 * esc_html(). format_frequency() is plain text; escape it with esc_html().
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\ProductPage
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\ProductPage;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PricingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;

defined( 'ABSPATH' ) || exit;

/**
 * Static formatter for plan option and column strings.
 *
 * Discount strings inspect only the FIRST valid pricing entry: Lite's plan
 * UI writes one adjustment per plan, so multi-policy plans render the lead
 * entry's discount only (the price itself always reflects the full chain).
 */
final class PlanOptionFormatter {

	/**
	 * Format a picker option's visible text: `{price} {cadence}` plus an optional
	 * ` ({discount})` suffix (`$21.60 / month (10% off)`). Carries wc_price() HTML.
	 *
	 * @param PlanView $plan       Plan being formatted.
	 * @param float    $base_price Base price the plan applies to.
	 * @return string Option text; escape with wp_kses_post() when rendering.
	 */
	public static function format( PlanView $plan, float $base_price ): string {
		$calculator = PriceCalculator::for_plan( $plan );
		$price      = wc_price( $calculator->unit_price( $base_price, 1 ) );
		$cadence    = self::cadence_suffix( $plan );
		$discount   = self::discount_suffix( $calculator->get_terms(), $base_price );
		$text       = '' === $cadence ? $price : sprintf( '%s %s', $price, $cadence );

		if ( '' === $discount ) {
			return $text;
		}

		return sprintf( '%s (%s)', $text, $discount );
	}

	/**
	 * Price-cadence suffix for a cart/checkout line price (`/ month`, or
	 * `every 2 weeks` above interval 1). Plain text; escape with esc_html().
	 * Matches the customer portal's recurring-summary phrasing.
	 *
	 * @param PlanView $plan Plan being formatted.
	 */
	public static function cadence_suffix( PlanView $plan ): string {
		$terms = BillingTerms::from_plan( $plan );

		return null === $terms ? '' : Formatter::price_cadence( $terms->get_period(), $terms->get_interval() );
	}

	/**
	 * Short customer-facing plan label for a cart line / Store API row: the
	 * frequency read as an adjective (`Monthly`, or `Every 2 weeks` above
	 * interval 1). Post the name/description drop, a plan's identity is its
	 * cadence. Plain text.
	 *
	 * @param PlanView $plan Plan being formatted.
	 */
	public static function plan_label( PlanView $plan ): string {
		$terms = BillingTerms::from_plan( $plan );

		return null === $terms ? '' : self::frequency_label( $terms->get_period(), $terms->get_interval() );
	}

	/**
	 * Format a plan's billing cadence for the admin panel table's Frequency
	 * column (`Every 3 months`). Plain text.
	 *
	 * @param PlanView $plan Plan being formatted.
	 */
	public static function format_frequency( PlanView $plan ): string {
		$terms = BillingTerms::from_plan( $plan );

		return null === $terms ? '' : Formatter::explicit_cadence( $terms->get_period(), $terms->get_interval() );
	}

	/**
	 * Format a plan's discount for the admin panel table's Discount column.
	 * Returns the placeholder when the plan carries no discount to show.
	 * Carries wc_price() HTML for amount-based discounts.
	 *
	 * @param PlanView $plan       Plan being formatted.
	 * @param float    $base_price Base price the plan applies to.
	 * @return string Discount text; escape with wp_kses_post() when rendering.
	 */
	public static function format_discount( PlanView $plan, float $base_price ): string {
		$suffix = self::discount_suffix( PricingTerms::from_plan( $plan ), $base_price );

		return '' === $suffix ? Formatter::PLACEHOLDER : $suffix;
	}

	/**
	 * The frequency read as a customer-facing label: the adjective `Monthly` at
	 * interval 1, else `Every N months`. Unknown periods at interval 1 fall back
	 * to the explicit `Every 1 {period}` form.
	 *
	 * @param string $period   Billing period.
	 * @param int    $interval Periods per cycle.
	 */
	private static function frequency_label( string $period, int $interval ): string {
		if ( 1 === $interval ) {
			switch ( $period ) {
				case 'day':
					return __( 'Daily', 'woocommerce-subscriptions-lite' );
				case 'week':
					return __( 'Weekly', 'woocommerce-subscriptions-lite' );
				case 'month':
					return __( 'Monthly', 'woocommerce-subscriptions-lite' );
				case 'year':
					return __( 'Yearly', 'woocommerce-subscriptions-lite' );
			}
		}

		return sprintf(
			/* translators: 1: billing interval count, 2: pluralized billing period (e.g. "month", "months"). */
			__( 'Every %1$d %2$s', 'woocommerce-subscriptions-lite' ),
			$interval,
			Formatter::period_label( $period, $interval )
		);
	}

	/**
	 * Discount string from the first valid pricing entry, or '' when there is
	 * nothing to show (no entry, zero adjustment, or a price increase).
	 *
	 * Shapes: percentage -> `10% off`; fixed_amount -> `$5.00 off`; price ->
	 * `$8.00 off` only when the replacement price undercuts the base. A
	 * `starting_cycle > 1` appends the `(from cycle N)` qualifier.
	 *
	 * @param PricingTerms $terms      The plan's pricing terms.
	 * @param float        $base_price Base price the plan applies to.
	 */
	private static function discount_suffix( PricingTerms $terms, float $base_price ): string {
		$policies = $terms->get_policies();
		if ( empty( $policies ) ) {
			return '';
		}

		$first          = $policies[0];
		$value          = $first['value'];
		$starting_cycle = $first['starting_cycle'] ?? 1;

		switch ( $first['type'] ) {
			case PricingTerms::TYPE_PERCENTAGE:
				$suffix = self::percentage_off( $value );
				break;
			case PricingTerms::TYPE_FIXED_AMOUNT:
				$suffix = self::amount_off( $value );
				break;
			case PricingTerms::TYPE_PRICE:
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
