<?php
/**
 * My Account -> Subscriptions list template (Slice 0).
 *
 * Lists the logged-in customer's contracts and, for cancelable ones, renders the
 * authenticated cancel form. Rows are pre-shaped by
 * {@see \Automattic\WooCommerce\SubscriptionsLite\Portal\SubscriptionsEndpoint::build_rows()}
 * so this template is pure presentation - no contract lookups or formatting here.
 *
 * @var array<int, array<string, mixed>> $rows          Pre-shaped contract rows.
 * @var string                           $cancel_url    admin-post.php URL the cancel form posts to.
 * @var string                           $cancel_action The admin-post action slug.
 * @var string                           $nonce_field   The cancel nonce field name.
 * @var string                           $nonce_action  The cancel nonce action.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $rows ) ) :
	?>
	<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
		<a class="woocommerce-Button button" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
			<?php esc_html_e( 'Browse products', 'woocommerce-subscriptions-lite' ); ?>
		</a>
		<?php esc_html_e( 'You have no subscriptions yet.', 'woocommerce-subscriptions-lite' ); ?>
	</div>
	<?php
	return;
endif;
?>

<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders woocommerce-subscriptions-lite-subscriptions">
	<thead>
		<tr>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions-lite' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Status', 'woocommerce-subscriptions-lite' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Next payment', 'woocommerce-subscriptions-lite' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Total', 'woocommerce-subscriptions-lite' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr">&nbsp;</span></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr class="woocommerce-orders-table__row">
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Subscription', 'woocommerce-subscriptions-lite' ); ?>">
					<?php
					printf(
						/* translators: %d: subscription id */
						esc_html__( '#%d', 'woocommerce-subscriptions-lite' ),
						(int) $row['id']
					);
					?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Status', 'woocommerce-subscriptions-lite' ); ?>">
					<?php echo esc_html( (string) $row['status_label'] ); ?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Next payment', 'woocommerce-subscriptions-lite' ); ?>">
					<?php echo '' !== $row['next_payment'] ? esc_html( (string) $row['next_payment'] ) : '&mdash;'; ?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Total', 'woocommerce-subscriptions-lite' ); ?>">
					<?php echo esc_html( (string) $row['total'] ); ?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="&nbsp;">
					<?php if ( ! empty( $row['cancelable'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( (string) $cancel_url ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( (string) $cancel_action ); ?>" />
							<input type="hidden" name="contract_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
							<?php wp_nonce_field( (string) $nonce_action, (string) $nonce_field ); ?>
							<button type="submit" class="woocommerce-button button">
								<?php esc_html_e( 'Cancel', 'woocommerce-subscriptions-lite' ); ?>
							</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
