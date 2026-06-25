<?php
/**
 * BillingHistory meta box - the contract's billing cycles.
 *
 * Mirrors WooCommerce's "Items" box: a table of the subscription's billing
 * cycles (newest first) read through the engine facade, each linking to its
 * renewal order. Degrades to a notice when the history read fails.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes;

use Throwable;
use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;
use Automattic\WooCommerce\SubscriptionsLite\Admin\OrderLinks;
use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\StatusLabels;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Cycle;

defined( 'ABSPATH' ) || exit;

/**
 * "Billing history" meta box.
 */
final class BillingHistory {

	/**
	 * Render the box body.
	 *
	 * @param Contract $contract The contract being viewed.
	 */
	public static function output( Contract $contract ): void {
		$contract_id = (int) $contract->get_id();

		try {
			$cycles = Subscriptions::get_history( $contract_id, PageController::PER_PAGE );
		} catch ( Throwable $e ) {
			wc_get_logger()->error(
				'Admin subscription history could not be loaded for #' . $contract_id . ': ' . $e->getMessage(),
				[
					'source'      => 'woocommerce-subscriptions-lite',
					'contract_id' => $contract_id,
					'exception'   => $e,
				]
			);
			echo '<p>' . esc_html__( 'Billing history could not be loaded. Check the WooCommerce logs for details.', 'woocommerce-subscriptions-lite' ) . '</p>';
			return;
		}

		if ( empty( $cycles ) ) {
			echo '<p>' . esc_html__( 'No billing cycles yet.', 'woocommerce-subscriptions-lite' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped wc-subs-lite-history-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Sequence', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Count', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Period', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expected total', 'woocommerce-subscriptions-lite' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order', 'woocommerce-subscriptions-lite' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cycles as $cycle ) : ?>
					<?php self::row( $cycle ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one cycle-history row.
	 *
	 * @param Cycle $cycle One billing cycle.
	 */
	private static function row( Cycle $cycle ): void {
		$count    = $cycle->get_count();
		$order_id = $cycle->get_order_id();
		$period   = sprintf(
			/* translators: 1: period start date, 2: period end date. */
			__( '%1$s to %2$s', 'woocommerce-subscriptions-lite' ),
			Formatting::date( $cycle->get_starts_at_gmt() ),
			Formatting::date( $cycle->get_ends_at_gmt() )
		);

		$order_value = null !== $order_id
			? sprintf( '<a href="%s">#%d</a>', esc_url( OrderLinks::edit_url( $order_id ) ), $order_id )
			: Formatting::PLACEHOLDER;
		?>
		<tr>
			<td><?php echo esc_html( (string) $cycle->get_sequence_no() ); ?></td>
			<td><?php echo esc_html( null === $count ? Formatting::PLACEHOLDER : (string) $count ); ?></td>
			<td><?php echo esc_html( StatusLabels::cycle_label( $cycle->get_status()->get_value() ) ); ?></td>
			<td><?php echo esc_html( $period ); ?></td>
			<td><?php echo Formatting::price( $cycle->get_expected_total(), $cycle->get_currency() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- price markup escaped at source. ?></td>
			<td><?php echo wp_kses_post( $order_value ); ?></td>
		</tr>
		<?php
	}
}
