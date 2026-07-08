<?php
/**
 * ProductPlanResolver - resolves which selling plans apply to a product.
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
	 * 'inherit_select' resolves to no plans.
	 *
	 * @param int $product_id Product (or variation) id.
	 * @return array<int, Plan> Plans in display order.
	 */
	public function get_plans_for_product( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return [];
		}

		$parent_id     = (int) $product->get_parent_id();
		$applicability = $this->store->get( $parent_id > 0 ? $parent_id : $product_id );

		if ( ProductApplicability::MODE_INHERIT_ALL === $applicability->get_mode() ) {
			return $this->catalog->list_plans();
		}

		if ( ProductApplicability::MODE_INHERIT_SELECT === $applicability->get_mode() ) {
			$plan_ids = $applicability->get_plan_ids();

			return [] === $plan_ids ? [] : $this->catalog->get_plans( $plan_ids );
		}

		return [];
	}

	/**
	 * Whether a plan applies to a product - it is among the plans the PDP picker
	 * renders for it. The add-to-cart applicability gate.
	 *
	 * @param int $plan_id    Plan id to check.
	 * @param int $product_id Product (or variation) id.
	 */
	public function is_plan_applicable_to_product( int $plan_id, int $product_id ): bool {
		foreach ( $this->get_plans_for_product( $product_id ) as $plan ) {
			if ( $plan->get_id() === $plan_id ) {
				return true;
			}
		}
		return false;
	}
}
