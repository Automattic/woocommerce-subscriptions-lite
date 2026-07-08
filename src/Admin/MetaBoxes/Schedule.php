<?php
/**
 * Schedule meta box - the contract's billing cadence and schedule dates.
 *
 * A side-column box gathering the "when" of a subscription: the billing cadence,
 * fixed length and native trial read off the contract's frozen plan snapshot,
 * followed by the start, next-payment, last-payment and end dates. Kept beside
 * the main detail so the {@see SubscriptionData} box stays a compact status
 * summary, mirroring how the order edit screen keeps schedule-like facts out of
 * the headline order-data row.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * "Schedule" meta box.
 */
final class Schedule {

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		DetailTable::render( self::rows( $contract ) );
	}

	/**
	 * Compose the schedule rows: the plan cadence rows (when a snapshot is
	 * present) followed by the schedule dates.
	 *
	 * @param Contract $contract The contract.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function rows( Contract $contract ): array {
		$rows = self::plan_rows( $contract );

		$rows[] = [
			'label' => __( 'Start date', 'woocommerce-subscriptions-lite' ),
			'value' => esc_html( Formatting::date( $contract->get_start_gmt() ) ),
		];
		$rows[] = [
			'label' => __( 'Next payment', 'woocommerce-subscriptions-lite' ),
			'value' => esc_html( Formatting::date( $contract->get_next_payment_gmt() ) ),
		];
		$rows[] = [
			'label' => __( 'Last payment', 'woocommerce-subscriptions-lite' ),
			'value' => esc_html( Formatting::date( $contract->get_last_payment_gmt() ) ),
		];
		$rows[] = [
			'label' => __( 'End date', 'woocommerce-subscriptions-lite' ),
			'value' => esc_html( Formatting::date( $contract->get_end_gmt() ) ),
		];

		return $rows;
	}

	/**
	 * Plan-detail rows from the contract's frozen plan snapshot: the billing
	 * cadence, the fixed length when the plan is close-ended, and a native trial
	 * when present. Empty when the contract carries no plan snapshot, so a
	 * snapshot-less contract simply shows its dates rather than a fatal.
	 *
	 * @param Contract $contract The contract.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function plan_rows( Contract $contract ): array {
		$snapshot = $contract->get_plan_snapshot();
		$policy   = null !== $snapshot ? $snapshot->get_billing_policy() : null;
		if ( null === $policy ) {
			return [];
		}

		$rows = [
			[
				'label' => __( 'Billing', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( Formatting::billing_cadence( $policy->get_period(), $policy->get_interval() ) ),
			],
		];

		$max_cycles = $policy->get_max_cycles();
		if ( null !== $max_cycles ) {
			$rows[] = [
				'label' => __( 'Billing cycles', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html(
					sprintf(
						/* translators: %s: number of billing cycles. */
						_n( '%s cycle', '%s cycles', $max_cycles, 'woocommerce-subscriptions-lite' ),
						number_format_i18n( $max_cycles )
					)
				),
			];
		}

		$trial = $policy->get_trial_duration();
		if ( is_array( $trial ) && isset( $trial['length'], $trial['unit'] ) ) {
			$rows[] = [
				'label' => __( 'Free trial', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( Formatting::trial_duration( (int) $trial['length'], (string) $trial['unit'] ) ),
			];
		}

		return $rows;
	}
}
