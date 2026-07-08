<?php
/**
 * OrderReceived - the subscription summary beneath the order details table.
 *
 * On `woocommerce_order_details_after_order_table` (the same hook WooCommerce
 * Subscriptions uses) it renders a "Related subscriptions" table directly below
 * the order details table on the order-received (thank-you) and My Account
 * view-order pages, resolving the order's contract through the engine's public
 * facade. The table reuses WooCommerce's My Account orders-table markup and
 * classes, so it inherits the active theme's order-table styling. When a
 * subscription was intended but not created (see {@see ContractCreationHandler}),
 * it prints a warning notice instead.
 *
 * The classic order details template fires this hook; the block Order Confirmation
 * page renders order details as blocks, so on a block order-received the table
 * appears on the My Account view-order page rather than inline on the thank-you
 * page. The subscription is always available in the customer portal.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Checkout
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\OrderLinkage;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Render the order's subscription (or a deferral warning) below the order table.
 *
 * Reads the contract through the engine facade and the plan through the Lite
 * catalog; covered by integration tests that render a real order end to end.
 */
final class OrderReceived {

	/**
	 * Wire the order-details hook. Called once from the bootstrap.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', [ new self(), 'render' ], 10, 1 );
	}

	/**
	 * Print the subscription table, or the deferral warning, for an order.
	 *
	 * @param mixed $order A `WC_Order` (as the hook passes) or an order id.
	 */
	public function render( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$contract_id = (int) $order->get_meta( OrderLinkage::META_CONTRACT_ID );
		if ( $contract_id > 0 ) {
			$contract = Subscriptions::get_for_customer( $contract_id, $order->get_customer_id() );
			if ( $contract instanceof Contract ) {
				$this->render_table( $contract );
				return;
			}
		}

		if ( '' !== (string) $order->get_meta( ContractCreationHandler::CREATION_DEFERRED_META ) ) {
			printf(
				'<p class="woocommerce-info wc-subscriptions-lite-order-notice">%s</p>',
				esc_html__( 'This order includes a subscription that could not be set up automatically. Please contact the store and we will get it sorted.', 'woocommerce-subscriptions-lite' )
			);
		}
	}

	/**
	 * Print the "Related subscriptions" table for a created contract.
	 *
	 * Mirrors WooCommerce's My Account orders-table markup so the theme styles it
	 * identically to the order details table above it.
	 *
	 * @param Contract $contract The created contract.
	 */
	private function render_table( Contract $contract ): void {
		$contract_id  = (int) $contract->get_id();
		$status       = (string) $contract->get_status();
		$url          = ( new Endpoints() )->detail_url( $contract_id );
		$plan         = $this->find_plan( $contract->get_selling_plan_id() );
		$cadence      = $plan instanceof Plan ? PlanOptionFormatter::cadence_suffix( $plan ) : '';
		$amount       = wc_price( (float) $contract->get_billing_total(), [ 'currency' => $contract->get_currency() ] );
		$next_gmt     = $contract->get_next_payment_gmt();
		$next_ts      = null !== $next_gmt ? strtotime( $next_gmt . ' UTC' ) : false;
		$next_out     = false !== $next_ts ? wp_date( wc_date_format(), $next_ts ) : '';
		$button_class = wc_wp_theme_get_element_class_name( 'button' );
		$button_class = $button_class ? ' ' . $button_class : '';
		/* translators: %s: subscription number. */
		$view_label = sprintf( __( 'View subscription %s', 'woocommerce-subscriptions-lite' ), $contract_id );
		?>
		<header>
			<h2><?php esc_html_e( 'Related subscriptions', 'woocommerce-subscriptions-lite' ); ?></h2>
		</header>
		<table class="shop_table shop_table_responsive my_account_orders woocommerce-orders-table woocommerce-orders-table--subscriptions wc-subscriptions-lite-order-subscriptions">
			<thead>
				<tr>
					<th class="subscription-id order-number woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions-lite' ); ?></span></th>
					<th class="subscription-status order-status woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php echo esc_html_x( 'Status', 'table heading', 'woocommerce-subscriptions-lite' ); ?></span></th>
					<th class="subscription-next-payment order-date woocommerce-orders-table__header woocommerce-orders-table__header-order-date"><span class="nobr"><?php echo esc_html_x( 'Next payment', 'table heading', 'woocommerce-subscriptions-lite' ); ?></span></th>
					<th class="subscription-total order-total woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions-lite' ); ?></span></th>
					<th class="subscription-actions order-actions woocommerce-orders-table__header woocommerce-orders-table__header-order-actions">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $status ); ?>">
					<td class="subscription-id order-number woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Subscription', 'woocommerce-subscriptions-lite' ); ?>">
						<a href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $view_label ); ?>">
							<?php echo esc_html( sprintf( '#%d', $contract_id ) ); ?>
						</a>
					</td>
					<td class="subscription-status order-status woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" style="white-space:nowrap;" data-title="<?php echo esc_attr_x( 'Status', 'table heading', 'woocommerce-subscriptions-lite' ); ?>">
						<?php echo esc_html( $this->status_label( $status ) ); ?>
					</td>
					<td class="subscription-next-payment order-date woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php echo esc_attr_x( 'Next payment', 'table heading', 'woocommerce-subscriptions-lite' ); ?>">
						<?php echo esc_html( '' !== $next_out ? $next_out : '-' ); ?>
					</td>
					<td class="subscription-total order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php echo esc_attr_x( 'Total', 'table heading', 'woocommerce-subscriptions-lite' ); ?>">
						<?php
						echo wp_kses_post( $amount );
						if ( '' !== $cadence ) {
							echo ' ' . esc_html( $cadence );
						}
						?>
					</td>
					<td class="subscription-actions order-actions woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions">
						<a href="<?php echo esc_url( $url ); ?>" class="woocommerce-button button view wc-subscriptions-lite-manage-subscription<?php echo esc_attr( $button_class ); ?>" aria-label="<?php echo esc_attr( $view_label ); ?>">
							<?php echo esc_html_x( 'View', 'view a subscription', 'woocommerce-subscriptions-lite' ); ?>
						</a>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The plan behind a contract, read from the Lite catalog, or null if gone.
	 *
	 * @param int $plan_id The selling plan id.
	 */
	private function find_plan( int $plan_id ): ?Plan {
		$plans = ( new SellingPlans( [ Package::EXTENSION_SLUG ] ) )->get_plans( [ $plan_id ] );
		$plan  = reset( $plans );

		return $plan instanceof Plan ? $plan : null;
	}

	/**
	 * Human-readable label for a contract status (e.g. "active" -> "Active").
	 *
	 * @param string $status The engine status slug.
	 */
	private function status_label( string $status ): string {
		return ucwords( str_replace( [ '-', '_' ], ' ', $status ) );
	}
}
