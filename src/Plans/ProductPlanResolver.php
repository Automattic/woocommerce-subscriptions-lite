<?php
/**
 * ProductPlanResolver - resolves which selling plans apply to a product.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;

defined( 'ABSPATH' ) || exit;

/**
 * Product plan resolver.
 *
 * Applicability lives on parent products: a variation id resolves to its
 * parent before the meta is read. The mode drives the engine catalog read -
 * 'disable' yields no plans, 'inherit_all' every active Lite-owned plan,
 * 'inherit_select' the attached plans that are active. Plans without usable
 * billing terms are skipped, and the rest come back in the Lite plan order
 * ({@see PlanOrder}).
 */
final class ProductPlanResolver {

	/**
	 * Applicability meta store.
	 *
	 * @var ApplicabilityStore
	 */
	private $store;

	/**
	 * Construct the resolver.
	 *
	 * @param ApplicabilityStore|null $store Applicability meta store.
	 */
	public function __construct( ?ApplicabilityStore $store = null ) {
		$this->store = $store ?? new ApplicabilityStore();
	}

	/**
	 * Resolve the active plans applying to a product.
	 *
	 * An unknown product resolves to no plans. Otherwise the parent product's
	 * applicability mode drives the lookup; an empty selection under
	 * 'inherit_select' resolves to no plans.
	 *
	 * @param int $product_id Product (or variation) id.
	 * @return array<int, PlanView> Plans in display order.
	 */
	public function get_plans_for_product( int $product_id ): array {
		$plans = array_filter(
			$this->get_applicable_plans( $product_id ),
			static function ( PlanView $plan ): bool {
				return null !== BillingTerms::from_plan( $plan );
			}
		);

		return ( new PlanOrder() )->sort( array_values( $plans ) );
	}

	/**
	 * The applicability of a product, read from its parent for a variation; null
	 * for an unknown product.
	 *
	 * @param int $product_id Product (or variation) id.
	 */
	private function get_applicability( int $product_id ): ?ProductApplicability {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$parent_id = (int) $product->get_parent_id();

		return $this->store->get( $parent_id > 0 ? $parent_id : $product_id );
	}

	/**
	 * Active plans selected by the product's applicability, oldest id first.
	 *
	 * @param int $product_id Product (or variation) id.
	 * @return array<int, PlanView>
	 */
	private function get_applicable_plans( int $product_id ): array {
		$applicability = $this->get_applicability( $product_id );
		if ( null === $applicability ) {
			return [];
		}

		if ( ProductApplicability::MODE_INHERIT_ALL === $applicability->get_mode() ) {
			return Plans::list(
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => PlanStatus::ACTIVE,
				]
			);
		}

		if ( ProductApplicability::MODE_INHERIT_SELECT === $applicability->get_mode() ) {
			$plan_ids = $applicability->get_plan_ids();

			return [] === $plan_ids ? [] : Plans::list(
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => PlanStatus::ACTIVE,
					'ids'            => $plan_ids,
				]
			);
		}

		return [];
	}

	/**
	 * The plan a cart or order line carries: a Lite plan in any status, so a line
	 * keeps its plan when the plan is archived after it was added, with billable
	 * terms ({@see BillingTerms::from_plan()}). Null for a non-positive id, an
	 * unknown or foreign plan, or unusable billing. The one rule cart pricing, the
	 * Store API and contract creation share.
	 *
	 * @param int $plan_id Plan id stamped on the line.
	 */
	public function get_line_plan( int $plan_id ): ?PlanView {
		$plan = Plans::get( $plan_id );
		if ( null === $plan || Package::EXTENSION_SLUG !== $plan->get_extension_slug() ) {
			return null;
		}

		return null !== BillingTerms::from_plan( $plan ) ? $plan : null;
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
