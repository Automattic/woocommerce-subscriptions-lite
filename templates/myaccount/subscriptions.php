<?php
/**
 * My Account -> Subscriptions list template (customer portal).
 *
 * Server-rendered markup carrying Interactivity API directives. Rows are
 * pre-shaped by {@see \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\ViewModel}
 * so this template is pure presentation - no contract lookups or formatting.
 *
 * @var array<int, array<string, mixed>> $rows           Pre-shaped contract rows.
 * @var string                           $store          The iAPI store namespace.
 * @var callable                         $detail_url_for Builds the detail URL for a contract id.
 * @var array<string, string>            $pagination     Previous / next page URLs ('' = no link).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' )
	? ' ' . wc_wp_theme_get_element_class_name( 'button' )
	: '';

if ( empty( $rows ) ) :
	?>
	<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
		<a class="woocommerce-Button button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
			<?php esc_html_e( 'Browse products', 'woocommerce-subscriptions-lite' ); ?>
		</a>
		<?php esc_html_e( 'You have no subscriptions yet.', 'woocommerce-subscriptions-lite' ); ?>
	</div>
	<?php
	// A page past the end still gets a way back.
	wc_get_template(
		'myaccount/pagination.php',
		[ 'pagination' => $pagination ?? [] ],
		'',
		\Automattic\WooCommerce\SubscriptionsLite\Package::get_path() . '/templates/'
	);
	return;
endif;

$columns = [
	'subscription' => __( 'Subscription', 'woocommerce-subscriptions-lite' ),
	'status'       => __( 'Status', 'woocommerce-subscriptions-lite' ),
	'next-payment' => __( 'Next payment', 'woocommerce-subscriptions-lite' ),
	'total'        => __( 'Total', 'woocommerce-subscriptions-lite' ),
	'actions'      => '',
];
?>

<div
	data-wp-interactive="<?php echo esc_attr( $store ); ?>"
	class="woocommerce-subscriptions-lite-portal woocommerce-subscriptions-lite-portal--list"
>
	<table class="shop_table shop_table_responsive my_account_subscriptions account-subscriptions-table">
		<thead>
			<tr>
				<?php foreach ( $columns as $column_key => $column_label ) : ?>
					<th class="subscription-<?php echo esc_attr( $column_key ); ?>">
						<span class="nobr"><?php echo '' !== (string) $column_label ? esc_html( (string) $column_label ) : '&nbsp;'; ?></span>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $rows as $row ) :
				$detail_url = is_callable( $detail_url_for ) ? (string) call_user_func( $detail_url_for, (int) $row['id'] ) : '#';
				?>
				<tr class="subscription">
					<td class="subscription-subscription" data-title="<?php echo esc_attr( (string) $columns['subscription'] ); ?>">
						<a href="<?php echo esc_url( $detail_url ); ?>">
							<?php
							printf(
								/* translators: %d: subscription id */
								esc_html__( '#%d', 'woocommerce-subscriptions-lite' ),
								(int) $row['id']
							);
							?>
						</a>
					</td>
					<td class="subscription-status" data-title="<?php echo esc_attr( (string) $columns['status'] ); ?>">
						<span class="wc-subs-lite-status-badge wc-subs-lite-status-badge--<?php echo esc_attr( (string) $row['status'] ); ?>">
							<?php echo esc_html( (string) $row['status_label'] ); ?>
						</span>
					</td>
					<td class="subscription-next-payment" data-title="<?php echo esc_attr( (string) $columns['next-payment'] ); ?>">
						<?php if ( '' !== (string) $row['next_payment'] ) : ?>
							<?php echo esc_html( (string) $row['next_payment'] ); ?>
							<?php if ( '' !== (string) $row['payment_method_title'] ) : ?>
								<br /><small>
									<?php
									printf(
										/* translators: %s: payment method display name (e.g. "Visa ending in 4242"). */
										esc_html__( 'Via %s', 'woocommerce-subscriptions-lite' ),
										esc_html( (string) $row['payment_method_title'] )
									);
									?>
								</small>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td class="subscription-total" data-title="<?php echo esc_attr( (string) $columns['total'] ); ?>">
						<?php echo '' !== (string) $row['total'] ? esc_html( (string) $row['total'] ) : '&mdash;'; ?>
					</td>
					<td class="subscription-actions">
						<a href="<?php echo esc_url( $detail_url ); ?>" class="woocommerce-button button view<?php echo esc_attr( $wp_button_class ); ?>">
							<?php esc_html_e( 'View', 'woocommerce-subscriptions-lite' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	wc_get_template(
		'myaccount/pagination.php',
		[ 'pagination' => $pagination ?? [] ],
		'',
		\Automattic\WooCommerce\SubscriptionsLite\Package::get_path() . '/templates/'
	);
	?>
</div>
