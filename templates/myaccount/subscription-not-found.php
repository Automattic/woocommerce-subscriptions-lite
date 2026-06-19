<?php
/**
 * Rendered when the requested contract does not exist OR belongs to a different
 * customer. Both collapse to "not found" so the portal does not leak the
 * existence of a contract the requester does not own.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-message woocommerce-Message woocommerce-Message--info woocommerce-info">
	<a class="woocommerce-Button button" href="<?php echo esc_url( wc_get_endpoint_url( 'subscriptions', '', wc_get_page_permalink( 'myaccount' ) ) ); ?>">
		<?php esc_html_e( 'Back to subscriptions', 'woocommerce-subscriptions-lite' ); ?>
	</a>
	<?php esc_html_e( 'Subscription not found.', 'woocommerce-subscriptions-lite' ); ?>
</div>
