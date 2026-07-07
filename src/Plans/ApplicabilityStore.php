<?php
/**
 * ApplicabilityStore - Lite-owned product meta I/O for selling-plan
 * applicability.
 *
 * The three meta keys are Lite's data model for "does this product sell on
 * plans": mode and allow-one-time as single-value meta, attached plan ids as
 * one multi-value row per id (only written for 'inherit_select'). The engine
 * stores only the plans catalog; which products a plan applies to is Lite's.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use InvalidArgumentException;
use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Product applicability meta store.
 *
 * Applicability lives on parent products only: the write path rejects
 * variations and product types that cannot carry plans, and reads operate on
 * the id they are given - variation-to-parent resolution lives in
 * {@see ProductPlanResolver}.
 */
final class ApplicabilityStore {

	public const META_APPLY_MODE = '_wcsl_plans_apply_mode';

	public const META_PLAN_IDS = '_wcsl_plans_plan_ids';

	public const META_ALLOW_ONE_TIME = '_wcsl_plans_allow_one_time';

	/**
	 * Product types that may carry applicability - the canonical list; the
	 * PDP picker's render gate and the admin panel's save gate reference it
	 * so the surfaces cannot drift.
	 *
	 * @var array<int, string>
	 */
	public const SUPPORTED_PRODUCT_TYPES = [ 'simple', 'variable' ];

	/**
	 * Read a product's applicability. Absent meta yields the defaults
	 * (disable, one-time allowed).
	 *
	 * @param int $product_id Parent product id.
	 */
	public function get( int $product_id ): ProductApplicability {
		$plan_ids = get_post_meta( $product_id, self::META_PLAN_IDS, false );

		return ProductApplicability::from_storage(
			[
				'mode'           => get_post_meta( $product_id, self::META_APPLY_MODE, true ),
				'plan_ids'       => is_array( $plan_ids ) ? $plan_ids : [],
				'allow_one_time' => get_post_meta( $product_id, self::META_ALLOW_ONE_TIME, true ),
			]
		);
	}

	/**
	 * Write a product's applicability.
	 *
	 * Validates before writing, all-or-nothing: the product must exist and be
	 * a simple or variable parent (variations and other product types are
	 * rejected), and under 'inherit_select' every plan id must be an active
	 * Lite-owned plan in the engine catalog. Nothing is written when
	 * validation fails.
	 *
	 * Mode and the allow-one-time flag are single-value writes; the plan rows
	 * are reconciled to exactly the value object's plan ids - stale rows are
	 * deleted, missing ones added, one row per id. 'disable' and 'inherit_all'
	 * therefore end with zero attachment rows (all-mode is virtual).
	 *
	 * @param int                  $product_id    Parent product id.
	 * @param ProductApplicability $applicability Applicability to persist.
	 * @throws InvalidArgumentException If the product is missing or not a simple/variable parent, or a plan id is not an active Lite-owned plan.
	 */
	public function set( int $product_id, ProductApplicability $applicability ): void {
		$this->validate( $product_id, $applicability );

		$data = $applicability->to_storage();

		update_post_meta( $product_id, self::META_APPLY_MODE, $data['mode'] );
		update_post_meta( $product_id, self::META_ALLOW_ONE_TIME, $data['allow_one_time'] );

		$wanted = $data['plan_ids'];

		$existing_rows = get_post_meta( $product_id, self::META_PLAN_IDS, false );
		$existing      = [];
		foreach ( is_array( $existing_rows ) ? $existing_rows : [] as $row ) {
			if ( is_scalar( $row ) ) {
				$existing[] = (int) $row;
			}
		}

		$row_counts = array_count_values( $existing );
		foreach ( $row_counts as $plan_id => $row_count ) {
			if ( ! in_array( $plan_id, $wanted, true ) ) {
				delete_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
			} elseif ( $row_count > 1 ) {
				// Collapse externally duplicated rows back to one row per id.
				delete_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
				add_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
			}
		}

		foreach ( $wanted as $plan_id ) {
			if ( ! isset( $row_counts[ $plan_id ] ) ) {
				add_post_meta( $product_id, self::META_PLAN_IDS, $plan_id );
			}
		}
	}

	/**
	 * Validate a write. Plan-id existence and ownership go through the
	 * engine's catalog read ({@see SellingPlans::get_plans()} scoped to
	 * Lite's slug): an id that is unknown, archived, or owned by another
	 * extension is absent from the result and rejects the whole write.
	 *
	 * @param int                  $product_id    Parent product id.
	 * @param ProductApplicability $applicability Applicability being persisted.
	 * @throws InvalidArgumentException If the product or a plan id fails validation.
	 */
	private function validate( int $product_id, ProductApplicability $applicability ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'ApplicabilityStore: product %d does not exist.', $product_id ) )
			);
		}

		if ( ! in_array( $product->get_type(), self::SUPPORTED_PRODUCT_TYPES, true ) ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'ApplicabilityStore: product %d must be a simple or variable parent product, got "%s".', $product_id, $product->get_type() ) )
			);
		}

		if ( ProductApplicability::MODE_INHERIT_SELECT !== $applicability->get_mode() ) {
			return;
		}

		$plan_ids = $applicability->get_plan_ids();
		if ( [] === $plan_ids ) {
			return;
		}

		$found = [];
		foreach ( SellingPlans::get_plans( $plan_ids, Package::EXTENSION_SLUG ) as $plan ) {
			$found[] = (int) $plan->get_id();
		}

		foreach ( $plan_ids as $plan_id ) {
			if ( ! in_array( $plan_id, $found, true ) ) {
				throw new InvalidArgumentException(
					esc_html( sprintf( 'ApplicabilityStore: plan %d does not exist for extension "%s".', $plan_id, Package::EXTENSION_SLUG ) )
				);
			}
		}
	}
}
