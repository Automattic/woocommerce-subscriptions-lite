<?php
/**
 * My Account -> Cancel-subscription modal template (customer portal).
 *
 * A native `<dialog>` wired to the namespaced iAPI store. The body copy is
 * state-aware (seeded server-side from the view-model): an active subscription
 * cancels at period end (with the date); an on-hold subscription cancels
 * immediately. The error region is a live region (`role="alert"`,
 * `aria-live="polite"`) the store populates on a failed submit.
 *
 * Rendered inside the detail page's interactivity root, so it shares the store
 * context; no separate `data-wp-interactive` wrapper is needed.
 *
 * @var array<string, mixed> $detail Pre-shaped detail view-model.
 * @var string               $store  The iAPI store namespace (for reference; context is inherited).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' )
	? ' ' . wc_wp_theme_get_element_class_name( 'button' )
	: '';
?>

<dialog
	class="wc-subscriptions-lite-cancel-modal"
	aria-labelledby="wc-subscriptions-lite-cancel-modal-title"
	aria-describedby="wc-subscriptions-lite-cancel-modal-body"
	data-wp-on--close="callbacks.onDialogClose"
>
	<div class="wc-subscriptions-lite-cancel-modal__inner">
		<button
			type="button"
			class="wc-subscriptions-lite-cancel-modal__close"
			aria-label="<?php esc_attr_e( 'Close', 'woocommerce-subscriptions-lite' ); ?>"
			data-wp-on--click="actions.closeCancelModal"
		>&times;</button>

		<h2 id="wc-subscriptions-lite-cancel-modal-title" class="wc-subscriptions-lite-cancel-modal__title">
			<?php esc_html_e( 'Cancel subscription', 'woocommerce-subscriptions-lite' ); ?>
		</h2>

		<p id="wc-subscriptions-lite-cancel-modal-body" class="wc-subscriptions-lite-cancel-modal__body">
			<?php echo esc_html( (string) $detail['cancel_modal_copy'] ); ?>
		</p>

		<div
			class="wc-subscriptions-lite-cancel-modal__error"
			role="alert"
			aria-live="polite"
			data-wp-bind--hidden="!state.error"
			data-wp-text="state.error"
		></div>

		<div class="wc-subscriptions-lite-cancel-modal__actions">
			<button
				type="button"
				class="button wc-subscriptions-lite-cancel-modal__dismiss<?php echo esc_attr( $wp_button_class ); ?>"
				data-wp-on--click="actions.closeCancelModal"
				data-wp-bind--disabled="state.submitting"
			>
				<?php esc_html_e( 'Keep subscription', 'woocommerce-subscriptions-lite' ); ?>
			</button>

			<button
				type="button"
				class="button wc-subscriptions-lite-cancel-modal__submit<?php echo esc_attr( $wp_button_class ); ?>"
				data-wp-on--click="actions.submitCancel"
				data-wp-bind--disabled="state.submitting"
			>
				<?php esc_html_e( 'Cancel subscription', 'woocommerce-subscriptions-lite' ); ?>
			</button>
		</div>
	</div>
</dialog>
