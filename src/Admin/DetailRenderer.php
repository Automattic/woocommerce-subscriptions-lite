<?php
/**
 * DetailRenderer - the admin subscription detail page.
 *
 * Server-rendered read view for `?page=...&action=view&id=N`. Reads the contract
 * and its billing-cycle history through the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions} facade and
 * renders a details block plus a cycle-history table, with the same Renew now /
 * Cancel actions the list row offers (status-gated, sharing one set of handlers).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use WP_User;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Cycle;

defined( 'ABSPATH' ) || exit;

/**
 * Static renderer for the subscription detail page.
 */
final class DetailRenderer {

	/**
	 * Render the detail page for a contract id.
	 *
	 * Capability is enforced upstream by {@see PageController::render_page()}.
	 *
	 * @param int $contract_id Contract id from the request (already absint'd).
	 */
	public static function render( int $contract_id ): void {
		$contract = $contract_id > 0 ? Subscriptions::get( $contract_id ) : null;

		if ( null === $contract ) {
			self::render_not_found( $contract_id );
			return;
		}

		?>
		<div class="wrap wc-subs-lite-admin wc-subs-lite-subscription-detail">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: subscription number. */
				printf( esc_html__( 'Subscription #%d', 'woocommerce-subscriptions-lite' ), (int) $contract->get_id() );
				?>
				<?php echo StatusLabels::contract_badge_html( $contract->get_status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge markup escaped at source. ?>
			</h1>
			<a href="<?php echo esc_url( PageController::page_url() ); ?>" class="page-title-action">
				<?php esc_html_e( 'Back to subscriptions', 'woocommerce-subscriptions-lite' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php PageController::render_flash_notice(); ?>

			<?php self::render_actions( $contract ); ?>
			<?php self::render_details( $contract ); ?>
			<?php self::render_history( (int) $contract->get_id() ); ?>
		</div>
		<?php
	}

	/**
	 * Not-found state - keeps the page chrome so the merchant can navigate back.
	 *
	 * @param int $contract_id The requested contract id.
	 */
	private static function render_not_found( int $contract_id ): void {
		?>
		<div class="wrap wc-subs-lite-admin wc-subs-lite-subscription-detail">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: subscription number. */
				printf( esc_html__( 'Subscription #%d not found.', 'woocommerce-subscriptions-lite' ), (int) $contract_id );
				?>
			</h1>
			<a href="<?php echo esc_url( PageController::page_url() ); ?>" class="page-title-action">
				<?php esc_html_e( 'Back to subscriptions', 'woocommerce-subscriptions-lite' ); ?>
			</a>
			<hr class="wp-header-end" />
		</div>
		<?php
	}

	/**
	 * Status-gated action buttons (Renew now, Cancel), sharing the list row's
	 * handlers and confirm contract.
	 *
	 * @param Contract $contract The contract.
	 */
	private static function render_actions( Contract $contract ): void {
		$id     = (int) $contract->get_id();
		$status = $contract->get_status();

		$renewable   = StatusLabels::is_renewable( $status );
		$cancellable = StatusLabels::is_cancellable( $status );

		if ( ! $renewable && ! $cancellable ) {
			return;
		}

		?>
		<p class="wc-subs-lite-detail-actions">
			<?php if ( $renewable ) : ?>
				<a class="button" href="<?php echo esc_url( PageController::action_url( PageController::ACTION_RENEW_NOW, $id ) ); ?>">
					<?php esc_html_e( 'Renew now', 'woocommerce-subscriptions-lite' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $cancellable ) : ?>
				<a class="button wc-subs-lite-cancel-link" href="<?php echo esc_url( PageController::action_url( PageController::ACTION_CANCEL, $id ) ); ?>" data-confirm="<?php esc_attr_e( 'Cancel this subscription immediately? This cannot be undone.', 'woocommerce-subscriptions-lite' ); ?>">
					<?php esc_html_e( 'Cancel', 'woocommerce-subscriptions-lite' ); ?>
				</a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Contract details as a form-table of label/value pairs.
	 *
	 * @param Contract $contract The contract.
	 */
	private static function render_details( Contract $contract ): void {
		?>
		<h2><?php esc_html_e( 'Details', 'woocommerce-subscriptions-lite' ); ?></h2>
		<table class="form-table wc-subs-lite-detail-table">
			<tbody>
				<?php
				foreach ( self::detail_rows( $contract ) as $row ) {
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
	 * Compose the detail label/value rows.
	 *
	 * @param Contract $contract The contract.
	 * @return array<int, array{label: string, value: string}>
	 */
	private static function detail_rows( Contract $contract ): array {
		$origin_order_id = $contract->get_origin_order_id();
		$origin_value    = null !== $origin_order_id
			? sprintf( '<a href="%s">#%d</a>', esc_url( self::order_edit_url( $origin_order_id ) ), $origin_order_id )
			: Formatting::PLACEHOLDER;

		return [
			[
				'label' => __( 'Status', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( StatusLabels::contract_label( $contract->get_status() ) ),
			],
			[
				'label' => __( 'Customer', 'woocommerce-subscriptions-lite' ),
				'value' => self::customer_value( $contract->get_customer_id() ),
			],
			[
				'label' => __( 'Recurring total', 'woocommerce-subscriptions-lite' ),
				'value' => Formatting::price( $contract->get_billing_total(), $contract->get_currency() ),
			],
			[
				'label' => __( 'Start date', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( Formatting::date( $contract->get_start_gmt() ) ),
			],
			[
				'label' => __( 'Next payment', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( Formatting::date( $contract->get_next_payment_gmt() ) ),
			],
			[
				'label' => __( 'Last payment', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( Formatting::date( $contract->get_last_payment_gmt() ) ),
			],
			[
				'label' => __( 'End date', 'woocommerce-subscriptions-lite' ),
				'value' => esc_html( Formatting::date( $contract->get_end_gmt() ) ),
			],
			[
				'label' => __( 'Original order', 'woocommerce-subscriptions-lite' ),
				'value' => $origin_value,
			],
		];
	}

	/**
	 * Customer cell value - linked display name or a neutral placeholder.
	 *
	 * @param int $customer_id Customer id.
	 */
	private static function customer_value( int $customer_id ): string {
		$user = $customer_id > 0 ? get_userdata( $customer_id ) : false;
		if ( ! $user instanceof WP_User ) {
			return esc_html__( '(no customer)', 'woocommerce-subscriptions-lite' );
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) get_edit_user_link( $customer_id ) ),
			esc_html( $user->display_name )
		);
	}

	/**
	 * Billing-cycle history table, newest first.
	 *
	 * @param int $contract_id Contract id.
	 */
	private static function render_history( int $contract_id ): void {
		$cycles = Subscriptions::get_history( $contract_id, PageController::PER_PAGE );

		?>
		<h2><?php esc_html_e( 'Billing history', 'woocommerce-subscriptions-lite' ); ?></h2>
		<?php if ( empty( $cycles ) ) : ?>
			<p><?php esc_html_e( 'No billing cycles yet.', 'woocommerce-subscriptions-lite' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="wp-list-table widefat fixed striped wc-subs-lite-history-table">
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
					<?php self::render_history_row( $cycle ); ?>
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
	private static function render_history_row( Cycle $cycle ): void {
		$count    = $cycle->get_count();
		$order_id = $cycle->get_order_id();
		$period   = sprintf(
			/* translators: 1: period start date, 2: period end date. */
			__( '%1$s to %2$s', 'woocommerce-subscriptions-lite' ),
			Formatting::date( $cycle->get_starts_at_gmt() ),
			Formatting::date( $cycle->get_ends_at_gmt() )
		);

		$order_value = null !== $order_id
			? sprintf( '<a href="%s">#%d</a>', esc_url( self::order_edit_url( $order_id ) ), $order_id )
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

	/**
	 * Admin edit URL for an order. Targets the HPOS `wc-orders` screen, which
	 * the post-edit URL also resolves to on HPOS installs.
	 *
	 * @param int $order_id Order id.
	 */
	private static function order_edit_url( int $order_id ): string {
		return add_query_arg(
			[
				'page'   => 'wc-orders',
				'action' => 'edit',
				'id'     => $order_id,
			],
			admin_url( 'admin.php' )
		);
	}
}
