<?php
/**
 * PlanOrder - the display order of Lite's selling plans.
 *
 * Display order is Lite presentation data, not engine data: it is kept in one
 * option holding an ordered list of plan ids. Plans listed in the option come
 * first, in option order; plans not listed follow by id ascending. Stale ids
 * (plans that no longer exist or are not in the list being sorted) are ignored.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Plans
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;

defined( 'ABSPATH' ) || exit;

/**
 * Lite-owned plan display order.
 */
final class PlanOrder {

	public const OPTION = 'woocommerce_subscriptions_lite_plan_order';

	/**
	 * The saved order: unique positive plan ids; anything else in the option is ignored.
	 *
	 * @return array<int, int>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$ids = [];
		foreach ( $stored as $id ) {
			if ( is_int( $id ) && $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Save the order.
	 *
	 * @param array<int, int> $ids Plan ids in display order.
	 */
	public function set( array $ids ): void {
		update_option( self::OPTION, array_values( array_map( 'intval', $ids ) ), false );
	}

	/**
	 * Sort plans by the saved order: listed plans first in order, the rest by id.
	 *
	 * @param array<int, PlanView> $plans Plans to sort.
	 * @return array<int, PlanView>
	 */
	public function sort( array $plans ): array {
		$position = array_flip( $this->get() );

		usort(
			$plans,
			static function ( PlanView $a, PlanView $b ) use ( $position ): int {
				$a_listed = isset( $position[ $a->get_id() ] );
				$b_listed = isset( $position[ $b->get_id() ] );

				if ( $a_listed && $b_listed ) {
					return $position[ $a->get_id() ] <=> $position[ $b->get_id() ];
				}
				if ( $a_listed !== $b_listed ) {
					return $a_listed ? -1 : 1;
				}

				return $a->get_id() <=> $b->get_id();
			}
		);

		return $plans;
	}
}
