<?php
/**
 * VariationPlanData - per-variation plan option HTML in variation payloads.
 *
 * Extends WooCommerce's `woocommerce_available_variation` payload with
 * pre-rendered plan option strings computed at each VARIATION's price:
 *
 *   subscriptions_lite: {
 *     option_html: {
 *       <plan_id>: "<span ...>$27.00</span> every 1 month (10% off)",
 *     }
 *   }
 *
 * The picker view module swaps each option's innerHTML from this map on
 * `found_variation` - no client-side price math or string formatting, so
 * {@see PlanOptionFormatter} stays the single source of truth and the swap
 * matches the server-rendered first paint. Mirrors WooCommerce core shipping
 * formatted strings (price_html) in the same payload.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\ProductPage
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\ProductPage;

use WC_Product;
use WC_Product_Variation;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Variation-payload filter for the PDP picker.
 */
final class VariationPlanData {

	/**
	 * Guards against double registration; `register()` is idempotent.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Per-request cache of resolved plans keyed by parent product id.
	 * WooCommerce serializes every variation's payload in one request, and
	 * the plans belong to the shared parent - without the cache each
	 * variation would re-run the same resolution.
	 *
	 * @var array<int, array<int, \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan>>
	 */
	private static $plans_cache = [];

	/**
	 * Wire the payload filter. Called from the bootstrap; idempotent so a
	 * repeated call cannot double-append the payload data.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'woocommerce_available_variation', [ new self(), 'filter_available_variation' ], 10, 3 );
	}

	/**
	 * Append per-plan option HTML to a variation's payload.
	 *
	 * Returns the payload untouched when the parent resolves no plans (the
	 * picker is absent, nothing consumes the map). Plans without an id cannot
	 * be keyed and are skipped.
	 *
	 * @param array<string, mixed> $data      Variation payload WooCommerce emits.
	 * @param WC_Product           $product   Parent variable product.
	 * @param WC_Product_Variation $variation Variation being serialized.
	 * @return array<string, mixed> Payload with `subscriptions_lite.option_html` appended when applicable.
	 */
	public function filter_available_variation( array $data, WC_Product $product, WC_Product_Variation $variation ): array {
		$product_id = (int) $product->get_id();
		if ( ! array_key_exists( $product_id, self::$plans_cache ) ) {
			self::$plans_cache[ $product_id ] = ( new ProductPlanResolver() )->for_product( $product_id );
		}

		$plans = self::$plans_cache[ $product_id ];
		if ( empty( $plans ) ) {
			return $data;
		}

		$variation_price = (float) $variation->get_price();

		$option_html = [];
		foreach ( $plans as $plan ) {
			$plan_id = $plan->get_id();
			if ( null === $plan_id ) {
				continue;
			}
			// The view module injects these strings via innerHTML; KSES here
			// is defense-in-depth over the formatter's wc_price() markup.
			$option_html[ (string) $plan_id ] = wp_kses_post( PlanOptionFormatter::format( $plan, $variation_price ) );
		}

		$data['subscriptions_lite'] = [
			'option_html' => $option_html,
		];

		return $data;
	}
}
