<?php
/**
 * SubscriptionData meta box - the contract's status and billing facts.
 *
 * Mirrors the general block of WooCommerce's "Order data" box: a read view of
 * the subscription's status, recurring total, payment method and originating
 * order, rendered inside a postbox on the detail screen. The billing cadence and
 * the schedule dates live in the side {@see Schedule} box. All values come
 * through the engine facade's entities and the shared {@see StatusLabels}/{@see
 * Formatting} helpers.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;
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
		DetailTable::render( self::rows( $contract ) );
	}

	/**
	 * Compose the label/value rows: the contract's status, recurring total,
	 * payment method and originating order. The billing cadence and the schedule
	 * dates live in the side "Schedule" box, so this box stays the at-a-glance
	 * summary the merchant reads first.
	 *
	 * @param Contract $contract The contract.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function rows( Contract $contract ): array {
		$origin_order_id = $contract->get_origin_order_id();
		$origin_value    = null !== $origin_order_id
			? sprintf( '<a href="%s">#%d</a>', esc_url( OrderLinks::edit_url( $origin_order_id ) ), $origin_order_id )
			: Formatter::PLACEHOLDER;

		return [
			[
				'label' => __( 'Status', 'woocommerce-subscriptions-lite' ),
				'value' => StatusLabels::contract_badge_html( $contract->get_status() ),
			],
			[
				'label' => __( 'Recurring total', 'woocommerce-subscriptions-lite' ),
				'value' => Formatting::price( $contract->get_billing_total(), $contract->get_currency() ),
			],
			[
				'label' => __( 'Payment method', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( self::payment_method_label( $contract ) ),
			],
			[
				'label' => __( 'Original order', 'woocommerce-subscriptions-lite' ),
				'value' => $origin_value,
			],
		];
	}

	/**
	 * Merchant-facing payment method label: the instrument's stored title, falling back to its
	 * gateway id, then a placeholder when neither is known.
	 *
	 * @param Contract $contract The contract.
	 */
	private static function payment_method_label( Contract $contract ): string {
		$instrument = $contract->get_payment_instrument();
		return $instrument->get_title() ?? $instrument->get_gateway() ?? Formatter::PLACEHOLDER;
	}
}
