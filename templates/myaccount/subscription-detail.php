<?php
/**
 * My Account -> Subscription detail template (customer portal).
 *
 * Server-rendered markup carrying Interactivity API directives. The action
 * buttons call the namespaced iAPI store rather than any Store-API JS. The
 * view-model is pre-shaped by {@see \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\ViewModel}
 * so this template is pure presentation.
 *
 * @var array<string, mixed> $detail Pre-shaped detail view-model.
 * @var string               $store  The iAPI store namespace.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' )
	? ' ' . wc_wp_theme_get_element_class_name( 'button' )
	: '';

$has_actions = $detail['cancel_visible'] || $detail['hold_visible'] || $detail['reactivate_visible'] || $detail['needs_payment_notice'];
?>

<div
	data-wp-interactive="<?php echo esc_attr( $store ); ?>"
	class="woocommerce-subscriptions-lite-portal woocommerce-subscriptions-lite-portal--detail"
>
	<?php
	// Render any queued WC notices (the post-action success notices queued by
	// PostActionNoticeListener land here after the action + refresh).
	if ( function_exists( 'wc_print_notices' ) ) {
		wc_print_notices();
	}
	?>

	<dl class="subscription-detail-block">
		<dt><?php esc_html_e( 'Status', 'woocommerce-subscriptions-lite' ); ?></dt>
		<dd>
			<span class="wc-subs-lite-status-badge wc-subs-lite-status-badge--<?php echo esc_attr( (string) $detail['status'] ); ?>">
				<?php echo esc_html( (string) $detail['status_label'] ); ?>
			</span>
		</dd>

		<dt><?php esc_html_e( 'Recurring', 'woocommerce-subscriptions-lite' ); ?></dt>
		<dd><?php echo '' !== (string) $detail['recurring_summary'] ? esc_html( (string) $detail['recurring_summary'] ) : '&mdash;'; ?></dd>

		<dt><?php esc_html_e( 'Start date', 'woocommerce-subscriptions-lite' ); ?></dt>
		<dd><?php echo '' !== (string) $detail['start_date'] ? esc_html( (string) $detail['start_date'] ) : '&mdash;'; ?></dd>

		<dt><?php esc_html_e( 'Last order date', 'woocommerce-subscriptions-lite' ); ?></dt>
		<dd><?php echo '' !== (string) $detail['last_order_date'] ? esc_html( (string) $detail['last_order_date'] ) : '&mdash;'; ?></dd>

		<dt><?php echo esc_html( (string) $detail['date_row_label'] ); ?></dt>
		<dd><?php echo '' !== (string) $detail['date_row_value'] ? esc_html( (string) $detail['date_row_value'] ) : '&mdash;'; ?></dd>

		<dt><?php esc_html_e( 'Payment', 'woocommerce-subscriptions-lite' ); ?></dt>
		<dd>
			<?php if ( '' !== (string) $detail['payment_method_title'] ) : ?>
				<?php echo esc_html( (string) $detail['payment_method_title'] ); ?>
				<?php if ( '' !== (string) $detail['payment_method_expires'] ) : ?>
					<br /><small><?php echo esc_html( (string) $detail['payment_method_expires'] ); ?></small>
				<?php endif; ?>
			<?php else : ?>
				&mdash;
			<?php endif; ?>
		</dd>

		<?php
		/**
		 * Fires inside the detail data block, after the default rows.
		 *
		 * Additive-only: an overlay may append extra `<dt>`/`<dd>` rows; it
		 * must not alter the default rows.
		 *
		 * @since 0.0.1
		 *
		 * @param array<string, mixed> $detail The detail view-model.
		 */
		do_action( 'woocommerce_subscriptions_lite_customer_portal_detail_rows', $detail );
		?>
	</dl>

	<?php if ( $has_actions ) : ?>
		<h3 class="subscription-detail-actions-heading"><?php esc_html_e( 'Actions', 'woocommerce-subscriptions-lite' ); ?></h3>

		<div class="subscription-detail-actions">
			<?php if ( $detail['cancel_visible'] ) : ?>
				<button
					type="button"
					class="woocommerce-button button cancel-subscription<?php echo esc_attr( $wp_button_class ); ?>"
					data-wp-on--click="actions.openCancelModal"
				>
					<?php esc_html_e( 'Cancel subscription', 'woocommerce-subscriptions-lite' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $detail['hold_visible'] ) : ?>
				<button
					type="button"
					class="woocommerce-button button hold-subscription<?php echo esc_attr( $wp_button_class ); ?>"
					data-wp-on--click="actions.submitHold"
					data-wp-bind--disabled="state.submitting"
				>
					<?php esc_html_e( 'Pause subscription', 'woocommerce-subscriptions-lite' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $detail['reactivate_visible'] ) : ?>
				<button
					type="button"
					class="woocommerce-button button reactivate-subscription<?php echo esc_attr( $wp_button_class ); ?>"
					data-wp-on--click="actions.submitReactivate"
					data-wp-bind--disabled="state.submitting"
				>
					<?php esc_html_e( 'Reactivate subscription', 'woocommerce-subscriptions-lite' ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $detail['needs_payment_notice'] ) : ?>
				<div class="woocommerce-message woocommerce-info subscription-needs-payment-notice">
					<?php esc_html_e( 'Your payment method needs updating before this subscription can resume. The option to update your payment method is coming soon.', 'woocommerce-subscriptions-lite' ); ?>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Fires after the default detail action buttons.
			 *
			 * Additive-only: an overlay may append extra action buttons wired
			 * through the shared iAPI store; it must not alter the defaults.
			 *
			 * @since 0.0.1
			 *
			 * @param array<string, mixed> $detail The detail view-model.
			 */
			do_action( 'woocommerce_subscriptions_lite_customer_portal_detail_actions', $detail );
			?>

			<?php
			// Inline error region for the in-page lifecycle actions (Pause /
			// Reactivate). A failed submit re-enables its button and writes the
			// message here. Bound to its own state field (`state.actionError`) so
			// it never cross-renders with the cancel modal's error region. The
			// seeded copy already carries the "try again" affordance, and the
			// re-enabled button is the retry. role="alert" announces the message
			// to assistive tech the moment it appears.
			?>
			<div
				class="subscription-detail-actions__error"
				role="alert"
				aria-live="polite"
				data-wp-bind--hidden="!state.actionError"
				data-wp-text="state.actionError"
			></div>
		</div>

		<?php
		// The cancel confirmation modal. Rendered for cancelable contracts so
		// the cancel button has a dialog to open.
		if ( $detail['cancel_visible'] ) {
			wc_get_template(
				'myaccount/cancel-modal.php',
				[
					'detail' => $detail,
					'store'  => $store,
				],
				'',
				\Automattic\WooCommerce\SubscriptionsLite\Package::get_path() . '/templates/'
			);
		}
		?>
	<?php endif; ?>

	<?php if ( ! empty( $detail['items'] ) ) : ?>
		<h3 class="subscription-detail-totals-heading"><?php esc_html_e( 'Subscription totals', 'woocommerce-subscriptions-lite' ); ?></h3>
		<table class="shop_table shop_table_responsive subscription-totals">
			<thead>
				<tr>
					<th class="subscription-item-name"><?php esc_html_e( 'Product', 'woocommerce-subscriptions-lite' ); ?></th>
					<th class="subscription-item-subtotal"><?php esc_html_e( 'Total', 'woocommerce-subscriptions-lite' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $detail['items'] as $item ) : ?>
					<tr class="subscription-item">
						<td class="subscription-item-name" data-title="<?php esc_attr_e( 'Product', 'woocommerce-subscriptions-lite' ); ?>">
							<?php echo esc_html( (string) $item['name'] ); ?>
							<strong class="product-quantity">&times;&nbsp;<?php echo esc_html( (string) $item['quantity'] ); ?></strong>
						</td>
						<td class="subscription-item-subtotal" data-title="<?php esc_attr_e( 'Total', 'woocommerce-subscriptions-lite' ); ?>">
							<?php echo esc_html( (string) $item['subtotal'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<?php if ( ! empty( $detail['totals_rows'] ) ) : ?>
				<tfoot>
					<?php foreach ( $detail['totals_rows'] as $totals_row ) : ?>
						<tr class="subscription-totals-row">
							<th scope="row"><?php echo esc_html( (string) $totals_row['label'] ); ?></th>
							<td data-title="<?php echo esc_attr( (string) $totals_row['label'] ); ?>"><?php echo esc_html( (string) $totals_row['value'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tfoot>
			<?php endif; ?>
		</table>
	<?php endif; ?>

	<?php if ( '' !== (string) $detail['billing_address'] || '' !== (string) $detail['shipping_address'] ) : ?>
		<?php $has_both_addresses = '' !== (string) $detail['billing_address'] && '' !== (string) $detail['shipping_address']; ?>
		<section class="woocommerce-customer-details subscription-detail-addresses">
			<?php if ( $has_both_addresses ) : ?>
			<div class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
			<?php endif; ?>

				<?php if ( '' !== (string) $detail['billing_address'] ) : ?>
					<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">
						<h3 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce-subscriptions-lite' ); ?></h3>
						<address>
							<?php echo wp_kses_post( (string) $detail['billing_address'] ); ?>
							<?php if ( '' !== (string) $detail['billing_phone'] ) : ?>
								<p class="woocommerce-customer-details--phone"><?php echo esc_html( (string) $detail['billing_phone'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== (string) $detail['billing_email'] ) : ?>
								<p class="woocommerce-customer-details--email"><?php echo esc_html( (string) $detail['billing_email'] ); ?></p>
							<?php endif; ?>
						</address>
					</div>
				<?php endif; ?>

				<?php if ( '' !== (string) $detail['shipping_address'] ) : ?>
					<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
						<h3 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce-subscriptions-lite' ); ?></h3>
						<address>
							<?php echo wp_kses_post( (string) $detail['shipping_address'] ); ?>
						</address>
					</div>
				<?php endif; ?>

			<?php if ( $has_both_addresses ) : ?>
			</div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php // Related orders render last: the list can grow long, while the sections above carry (future) actions. ?>
	<?php if ( ! empty( $detail['related_orders'] ) ) : ?>
		<h3 class="subscription-detail-related-heading"><?php esc_html_e( 'Related orders', 'woocommerce-subscriptions-lite' ); ?></h3>
		<table class="shop_table shop_table_responsive subscription-related-orders">
			<thead>
				<tr>
					<th class="related-order-number"><?php esc_html_e( 'Order', 'woocommerce-subscriptions-lite' ); ?></th>
					<th class="related-order-date"><?php esc_html_e( 'Date', 'woocommerce-subscriptions-lite' ); ?></th>
					<th class="related-order-status"><?php esc_html_e( 'Status', 'woocommerce-subscriptions-lite' ); ?></th>
					<th class="related-order-total"><?php esc_html_e( 'Total', 'woocommerce-subscriptions-lite' ); ?></th>
					<th class="related-order-actions">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $detail['related_orders'] as $related_order ) : ?>
					<tr class="subscription-related-order">
						<td class="related-order-number" data-title="<?php esc_attr_e( 'Order', 'woocommerce-subscriptions-lite' ); ?>">
							<a href="<?php echo esc_url( (string) $related_order['view_url'] ); ?>">#<?php echo esc_html( (string) $related_order['number'] ); ?></a>
						</td>
						<td class="related-order-date" data-title="<?php esc_attr_e( 'Date', 'woocommerce-subscriptions-lite' ); ?>">
							<?php echo '' !== (string) $related_order['date'] ? esc_html( (string) $related_order['date'] ) : '&mdash;'; ?>
						</td>
						<td class="related-order-status" data-title="<?php esc_attr_e( 'Status', 'woocommerce-subscriptions-lite' ); ?>">
							<span class="wc-subs-lite-status-badge wc-subs-lite-status-badge--<?php echo esc_attr( (string) $related_order['status'] ); ?>">
								<?php echo esc_html( (string) $related_order['status_label'] ); ?>
							</span>
						</td>
						<td class="related-order-total" data-title="<?php esc_attr_e( 'Total', 'woocommerce-subscriptions-lite' ); ?>">
							<?php echo esc_html( (string) $related_order['total'] ); ?>
						</td>
						<td class="related-order-actions">
							<a href="<?php echo esc_url( (string) $related_order['view_url'] ); ?>" class="woocommerce-button button view<?php echo esc_attr( $wp_button_class ); ?>">
								<?php esc_html_e( 'View order', 'woocommerce-subscriptions-lite' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php
	/**
	 * Fires after the default sections, at the end of the detail page.
	 *
	 * Additive-only: an overlay may append extra sections; it must not alter
	 * the default sections.
	 *
	 * @since 0.0.1
	 *
	 * @param array<string, mixed> $detail The detail view-model.
	 */
	do_action( 'woocommerce_subscriptions_lite_customer_portal_detail_sections', $detail );
	?>
</div>
