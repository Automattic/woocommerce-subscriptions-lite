<?php
/**
 * ProductPlanResolver - resolves which selling plans apply to a product and
 * bridges the result through Lite's product-plans filter.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Product plan resolver.
 *
 * Applicability lives on parent products: a variation id resolves to its
 * parent before the meta is read. The mode drives the engine catalog read -
 * 'disable' yields no plans, 'inherit_all' every active Lite-owned plan,
 * 'inherit_select' the attached plans that are active - and plans come back
 * in the catalog's display order.
 */
final class ProductPlanResolver {

	/**
	 * Filter over the plans resolved for a product, with
	 * `( $plans, $product_id )`. Lite's eligibility extension point over the
	 * resolved set (Premium overlays it): consumers may remove or append
	 * plans; non-Plan entries are discarded after the filter runs.
	 */
	public const PRODUCT_PLANS_FILTER = 'woocommerce_subscriptions_lite_product_plans';

	/**
	 * Applicability meta store.
	 *
	 * @var ApplicabilityStore
	 */
	private $store;

	/**
	 * Engine catalog read facade, scoped to Lite's slug.
	 *
	 * @var SellingPlans
	 */
	private $catalog;

	/**
	 * Construct the resolver.
	 *
	 * @param ApplicabilityStore|null $store Applicability meta store.
	 */
	public function __construct( ?ApplicabilityStore $store = null ) {
		$this->store   = $store ?? new ApplicabilityStore();
		$this->catalog = new SellingPlans( [ Package::EXTENSION_SLUG ] );
	}

	/**
	 * Resolve the active plans applying to a product.
	 *
	 * An unknown product resolves to no plans. Otherwise the parent product's
	 * applicability mode drives the lookup; an empty selection under
	 * 'inherit_select' short-circuits to no plans without running the filter.
	 *
	 * @param int $product_id Product (or variation) id.
	 * @return array<int, Plan> Plans in display order.
	 */
	public function for_product( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return [];
		}

		$parent_id     = (int) $product->get_parent_id();
		$applicability = $this->store->get( $parent_id > 0 ? $parent_id : $product_id );

		$plans = [];
		if ( ProductApplicability::MODE_INHERIT_ALL === $applicability->get_mode() ) {
			$plans = $this->catalog->list_plans();
		} elseif ( ProductApplicability::MODE_INHERIT_SELECT === $applicability->get_mode() ) {
			$plan_ids = $applicability->get_plan_ids();
			if ( [] === $plan_ids ) {
				return [];
			}

			$plans = $this->catalog->get_plans( $plan_ids );
		}

		/**
		 * Filters the plans resolved for a product.
		 *
		 * Lite's eligibility extension point over the resolved set: consumers
		 * may remove or append plans. Entries that are not Plan instances are
		 * discarded after the filter runs.
		 *
		 * @param array<int, Plan> $plans      Resolved plans, in display order.
		 * @param int              $product_id The id the caller asked about (a variation id is passed as-is).
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is a literal carrying the woocommerce_subscriptions_lite prefix.
		$plans = apply_filters( self::PRODUCT_PLANS_FILTER, $plans, $product_id );

		return array_values(
			array_filter(
				is_array( $plans ) ? $plans : [],
				static function ( $plan ): bool {
					return $plan instanceof Plan;
				}
			)
		);
	}
}
