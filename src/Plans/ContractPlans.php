<?php
/**
 * ContractPlans - resolves contracts to the live selling plans they were created under.
 *
 * Reads through the engine's catalog facade scoped to Lite's slug, so only active
 * Lite-owned plans resolve; a contract without a plan, or whose plan was archived or
 * deleted, resolves to none.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Contract to live plan lookups.
 */
final class ContractPlans {

	/**
	 * The live plan of one contract, or null.
	 *
	 * @param ContractView $contract Contract.
	 */
	public static function for_contract( ContractView $contract ): ?Plan {
		return self::for_contracts( [ $contract ] )[ (int) $contract->get_selling_plan_id() ] ?? null;
	}

	/**
	 * The live plans of several contracts in one read, keyed by plan id.
	 *
	 * @param array<int, ContractView> $contracts Contracts.
	 * @return array<int, Plan>
	 */
	public static function for_contracts( array $contracts ): array {
		$ids = [];
		foreach ( $contracts as $contract ) {
			$id = $contract->get_selling_plan_id();
			if ( null !== $id ) {
				$ids[ $id ] = $id;
			}
		}

		if ( [] === $ids ) {
			return [];
		}

		$plans = [];
		foreach ( ( new SellingPlans( [ Package::EXTENSION_SLUG ] ) )->get_plans( array_values( $ids ) ) as $plan ) {
			$plans[ (int) $plan->get_id() ] = $plan;
		}

		return $plans;
	}
}
