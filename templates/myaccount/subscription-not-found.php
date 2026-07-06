<?php
/**
 * Rendered when the requested contract does not exist OR belongs to a different
 * customer. Both collapse to "not found" so the portal does not leak the
 * existence of a contract the requester does not own.
 *
 * @var string $list_endpoint The subscriptions-list endpoint slug to link back to.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

$list_endpoint = isset( $list_endpoint ) ? (string) $list_endpoint : 'subscriptions-lite';
?>

<div class="woocommerce-message woocommerce-Message woocommerce-Message--info woocommerce-info">
	<a class="woocommerce-Button button" href="<?php echo esc_url( wc_get_endpoint_url( $list_endpoint, '', wc_get_page_permalink( 'myaccount' ) ) ); ?>">
		<?php esc_html_e( 'Back to subscriptions', 'woocommerce-subscriptions-lite' ); ?>
	</a>
	<?php esc_html_e( 'Subscription not found.', 'woocommerce-subscriptions-lite' ); ?>
</div>
