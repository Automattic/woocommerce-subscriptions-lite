<?php
/**
 * ContractPlans - resolves contracts to the live selling plans they were created under.
 *
 * Reads through the engine plan facade filtered to Lite's slug, in any status: archiving
 * a plan only stops offering it to new customers, so existing contracts keep resolving it
 * (as renewal and the order-received page do). A contract without a plan, or whose plan
 * was deleted or is owned by another extension, resolves to none.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
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
	public static function for_contract( ContractView $contract ): ?PlanView {
		return self::for_contracts( [ $contract ] )[ (int) $contract->get_selling_plan_id() ] ?? null;
	}

	/**
	 * The live plans of several contracts in one read, keyed by plan id.
	 *
	 * @param array<int, ContractView> $contracts Contracts.
	 * @return array<int, PlanView>
	 */
	public static function for_contracts( array $contracts ): array {
		$ids = [];
		foreach ( $contracts as $contract ) {
			$id = $contract->get_selling_plan_id();
			if ( null !== $id && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( [] === $ids ) {
			return [];
		}

		$owned_plans = Plans::list(
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'ids'            => array_values( $ids ),
				'limit'          => count( $ids ),
			]
		);

		$plans = [];
		foreach ( $owned_plans as $plan ) {
			$plans[ $plan->get_id() ] = $plan;
		}

		return $plans;
	}
}
