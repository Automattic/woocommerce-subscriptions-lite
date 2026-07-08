<?php
/**
 * OrderReceived - the subscription summary on the order-received (thank-you) page.
 *
 * On `woocommerce_thankyou` it resolves the order's contract through the engine's
 * public facade and prints a short summary - recurring total, cadence, and next
 * payment - with a link to the customer portal. When a subscription was intended
 * but not created (see {@see ContractCreationHandler}), it prints a warning notice
 * instead.
 *
 * Classic checkout fires `woocommerce_thankyou` unconditionally. Blocks/Store API
 * checkout fires it from the (default, but merchant-removable) Order Confirmation
 * "Additional Information" block; if a merchant removes that block the summary
 * simply does not appear here - the subscription still exists and is shown in the
 * customer portal. A Blocks-native surface is a later refinement.
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
 * Render the new subscription (or a deferral warning) on the order-received page.
 *
 * Construct via the no-arg constructor in production; tests inject the contract /
 * plan / url seams to render without the full checkout stack.
 */
final class OrderReceived {

	/**
	 * Contract finder. Production: `Subscriptions::get_for_customer()` (facade, ownership-checked).
	 *
	 * @var callable(int, int): ?Contract
	 */
	private $contract_finder;

	/**
	 * Plan finder. Production: the Lite-scoped catalog read.
	 *
	 * @var callable(int): ?Plan
	 */
	private $plan_finder;

	/**
	 * Portal detail-URL builder. Production: `Endpoints::detail_url()`.
	 *
	 * @var callable(int): string
	 */
	private $detail_url;

	/**
	 * Construct the renderer.
	 *
	 * @param (callable(int, int): ?Contract)|null $contract_finder Contract finder; defaults to the facade.
	 * @param (callable(int): ?Plan)|null          $plan_finder     Plan finder; defaults to the Lite catalog read.
	 * @param (callable(int): string)|null         $detail_url      Portal URL builder; defaults to `Endpoints`.
	 */
	public function __construct(
		?callable $contract_finder = null,
		?callable $plan_finder = null,
		?callable $detail_url = null
	) {
		$this->contract_finder = $contract_finder ?? static function ( int $contract_id, int $customer_id ): ?Contract {
			return Subscriptions::get_for_customer( $contract_id, $customer_id );
		};
		$this->plan_finder     = $plan_finder ?? static function ( int $plan_id ): ?Plan {
			$plans = ( new SellingPlans( [ Package::EXTENSION_SLUG ] ) )->get_plans( [ $plan_id ] );
			$plan  = reset( $plans );
			return $plan instanceof Plan ? $plan : null;
		};
		$this->detail_url      = $detail_url ?? static function ( int $contract_id ): string {
			return ( new Endpoints() )->detail_url( $contract_id );
		};
	}

	/**
	 * Wire the order-received hook. Called once from the bootstrap.
	 */
	public static function register(): void {
		add_action( 'woocommerce_thankyou', [ new self(), 'render' ], 10, 1 );
	}

	/**
	 * Print the subscription summary, or the deferral warning, for an order.
	 *
	 * @param mixed $order_id The order-received order id.
	 */
	public function render( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$contract_id = (int) $order->get_meta( OrderLinkage::META_CONTRACT_ID );
		if ( $contract_id > 0 ) {
			$contract = ( $this->contract_finder )( $contract_id, $order->get_customer_id() );
			if ( $contract instanceof Contract ) {
				$this->render_summary( $contract );
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
	 * Print the subscription summary block for a created contract.
	 *
	 * @param Contract $contract The created contract.
	 */
	private function render_summary( Contract $contract ): void {
		$plan     = ( $this->plan_finder )( $contract->get_selling_plan_id() );
		$cadence  = $plan instanceof Plan ? PlanOptionFormatter::cadence_suffix( $plan ) : '';
		$amount   = wc_price( (float) $contract->get_billing_total(), [ 'currency' => $contract->get_currency() ] );
		$next_gmt = $contract->get_next_payment_gmt();
		$next_ts  = null !== $next_gmt ? strtotime( $next_gmt . ' UTC' ) : false;
		$next_out = false !== $next_ts ? wp_date( wc_date_format(), $next_ts ) : '';
		$url      = ( $this->detail_url )( (int) $contract->get_id() );
		?>
		<section class="woocommerce-order-subscription">
			<h2 class="woocommerce-order-details__title"><?php esc_html_e( 'Your subscription', 'woocommerce-subscriptions-lite' ); ?></h2>
			<table class="woocommerce-table shop_table subscription_details">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Recurring total', 'woocommerce-subscriptions-lite' ); ?></th>
						<td>
							<?php
							echo wp_kses_post( $amount );
							if ( '' !== $cadence ) {
								echo ' ' . esc_html( $cadence );
							}
							?>
						</td>
					</tr>
					<?php if ( '' !== $next_out ) : ?>
						<tr>
							<th><?php esc_html_e( 'Next payment', 'woocommerce-subscriptions-lite' ); ?></th>
							<td><?php echo esc_html( $next_out ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p>
				<a class="button wc-subscriptions-lite-manage-subscription" href="<?php echo esc_url( $url ); ?>">
					<?php esc_html_e( 'Manage subscription', 'woocommerce-subscriptions-lite' ); ?>
				</a>
			</p>
		</section>
		<?php
	}
}
