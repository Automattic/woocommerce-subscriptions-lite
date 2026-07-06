<?php
/**
 * Customer portal pagination - Previous / Next controls in WooCommerce's
 * my-account pagination style (as on the orders list). Renders nothing when
 * neither URL is set, so callers can include it unconditionally.
 *
 * @var array{previous_url?: string, next_url?: string} $pagination Page URLs; empty string = no link.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

$previous_url = (string) ( $pagination['previous_url'] ?? '' );
$next_url     = (string) ( $pagination['next_url'] ?? '' );

if ( '' === $previous_url && '' === $next_url ) {
	return;
}

$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' )
	? ' ' . wc_wp_theme_get_element_class_name( 'button' )
	: '';
?>
<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination subscriptions-lite-pagination">
	<?php if ( '' !== $previous_url ) : ?>
		<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( $previous_url ); ?>">
			<?php esc_html_e( 'Previous', 'woocommerce-subscriptions-lite' ); ?>
		</a>
	<?php endif; ?>
	<?php if ( '' !== $next_url ) : ?>
		<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button<?php echo esc_attr( $wp_button_class ); ?>" href="<?php echo esc_url( $next_url ); ?>">
			<?php esc_html_e( 'Next', 'woocommerce-subscriptions-lite' ); ?>
		</a>
	<?php endif; ?>
</div>
