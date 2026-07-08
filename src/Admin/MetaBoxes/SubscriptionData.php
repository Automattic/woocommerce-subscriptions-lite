<?php
/**
 * SubscriptionData meta box - the contract's status and billing facts.
 *
 * Mirrors WooCommerce's "Order data" box: a read view of the subscription's
 * status, schedule, recurring total, and originating order, rendered inside a
 * postbox on the detail screen. All values come through the engine facade's
 * entities and the shared {@see StatusLabels}/{@see Formatting} helpers.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;
use Automattic\WooCommerce\SubscriptionsLite\Admin\OrderLinks;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;

defined( 'ABSPATH' ) || exit;

/**
 * "Subscription data" meta box.
 */
final class SubscriptionData {

	/**
	 * Render the box body.
	 *
	 * Receives the contract as the object `do_meta_boxes()` passes to the box
	 * callback.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		?>
		<table class="widefat striped wc-subs-lite-detail-table">
			<tbody>
				<?php
				foreach ( self::rows( $contract ) as $row ) {
					printf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html( $row['label'] ),
						wp_kses_post( $row['value'] )
					);
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Compose the label/value rows.
	 *
	 * @param Contract $contract The contract.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function rows( Contract $contract ): array {
		$origin_order_id = $contract->get_origin_order_id();
		$origin_value    = null !== $origin_order_id
			? sprintf( '<a href="%s">#%d</a>', esc_url( OrderLinks::edit_url( $origin_order_id ) ), $origin_order_id )
			: Formatting::PLACEHOLDER;

		$rows = [
			[
				'label' => __( 'Status', 'woocommerce-subscriptions-lite' ),
				'value' => StatusLabels::contract_badge_html( $contract->get_status() ),
			],
			[
				'label' => __( 'Recurring total', 'woocommerce-subscriptions-lite' ),
				'value' => Formatting::price( $contract->get_billing_total(), $contract->get_currency() ),
			],
		];

		// Plan details read off the contract's frozen plan snapshot - the cadence
		// the contract actually bills on, not the live plan's current terms.
		foreach ( self::plan_rows( $contract ) as $plan_row ) {
			$rows[] = $plan_row;
		}

		$rows[] = [
			'label' => __( 'Payment method', 'woocommerce-subscriptions-lite' ),
			'value' => esc_html( self::payment_method_label( $contract ) ),
		];
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
		$rows[] = [
			'label' => __( 'Original order', 'woocommerce-subscriptions-lite' ),
			'value' => $origin_value,
		];

		return $rows;
	}

	/**
	 * Plan-detail rows from the contract's frozen plan snapshot: the billing
	 * cadence, the fixed length when the plan is close-ended, and a native trial
	 * when present. Empty when the contract carries no plan snapshot, so a
	 * snapshot-less contract simply shows no plan rows rather than a fatal.
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

	/**
	 * Merchant-facing payment method label: the instrument's stored title, falling back to its
	 * gateway id, then a placeholder when neither is known.
	 *
	 * @param Contract $contract The contract.
	 */
	private static function payment_method_label( Contract $contract ): string {
		$instrument = $contract->get_payment_instrument();
		return $instrument->get_title() ?? $instrument->get_gateway() ?? Formatting::PLACEHOLDER;
	}
}
