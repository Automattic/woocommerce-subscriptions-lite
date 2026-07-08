<?php
/**
 * Plan picker - the one-time vs subscription plan choice on the product page.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/single-product/plan-picker.php.
 *
 * Rendered inside the add-to-cart form for simple and variable products that
 * resolve at least one selling plan. The chosen plan posts as
 * `_wcsl_selling_plan_id`; while the one-time option is selected the plan select is
 * disabled and posts nothing. The server paints the state matching the initial
 * context, so the markup is correct before (and without) the
 * `woocommerce-subscriptions-lite/plan-picker` Interactivity API module
 * loading.
 *
 * @var WC_Product $product           Product being rendered (simple or variable).
 * @var array<int, \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan> $plans Resolved plans in display order (never empty).
 * @var float      $base_price        Price seeding the option strings (minimum variation price on variables).
 * @var bool       $is_variable       Whether the product is variable.
 * @var bool       $one_time_allowed  Whether one-time purchase is offered alongside the plans.
 * @var string     $plan_select_label Translated plan-select label text ("Deliver:" / "Renew:" by fulfillment type).
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 * @version 0.1.0
 */

use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;

defined( 'ABSPATH' ) || exit;

$wcsl_product_id = (int) $product->get_id();
$wcsl_select_id  = 'wc-subscriptions-lite-plan-picker-select-' . $wcsl_product_id;

// The radios need a shared name for HTML radio-group exclusivity; the product
// id suffix keeps multiple pickers on one page independent. The cart flow
// ignores this key and reads only `_wcsl_selling_plan_id`.
$wcsl_mode_name = '_wcsl_picker_mode_' . $wcsl_product_id;

$wcsl_picker_classes = 'wc-subscriptions-lite-plan-picker'
	. ( $is_variable ? ' wc-subscriptions-lite-plan-picker--variable' : '' );

// Variable products keep the picker hidden until a variation is chosen; simple
// products seed variationChosen true and have nothing to wait for.
$wcsl_context = (string) wp_json_encode(
	[
		'mode'            => $one_time_allowed ? 'one-time' : 'subscribe',
		'isVariable'      => $is_variable,
		'variationChosen' => ! $is_variable,
	]
);
?>
<fieldset
	class="<?php echo esc_attr( $wcsl_picker_classes ); ?>"
	data-wp-interactive="woocommerce-subscriptions-lite/plan-picker"
	data-wp-context="<?php echo esc_attr( $wcsl_context ); ?>"
	<?php if ( $is_variable ) : ?>
		data-wp-init="callbacks.initVariationBridge"
	<?php endif; ?>
>
	<legend class="screen-reader-text">
		<?php esc_html_e( 'Choose how to purchase', 'woocommerce-subscriptions-lite' ); ?>
	</legend>

	<noscript>
		<p class="wc-subscriptions-lite-plan-picker__noscript">
			<?php
			if ( $one_time_allowed ) {
				esc_html_e( 'Subscribing requires JavaScript. You can still buy this product as a one-time purchase.', 'woocommerce-subscriptions-lite' );
			} else {
				esc_html_e( 'This product is sold on a subscription plan.', 'woocommerce-subscriptions-lite' );
			}
			?>
		</p>
	</noscript>

	<?php
	// On a variable product the picker stays hidden until a variation is
	// chosen (the view module flips `variationChosen` on `found_variation`);
	// this mirrors WooCommerce hiding its own add-to-cart controls until then.
	?>
	<div
		class="wc-subscriptions-lite-plan-picker__body"
		data-wp-bind--hidden="state.isPickerHidden"
		<?php if ( $is_variable ) : ?>
			hidden
		<?php endif; ?>
	>
	<?php if ( $one_time_allowed ) : ?>
		<ul class="wc-subscriptions-lite-plan-picker__modes">
			<li class="wc-subscriptions-lite-plan-picker__mode">
				<label>
					<input
						type="radio"
						name="<?php echo esc_attr( $wcsl_mode_name ); ?>"
						value="one-time"
						checked="checked"
						data-wp-on--change="actions.setMode"
					/>
					<?php esc_html_e( 'One-time purchase', 'woocommerce-subscriptions-lite' ); ?>
				</label>
			</li>
			<li class="wc-subscriptions-lite-plan-picker__mode">
				<label>
					<?php
					// Without JavaScript the subscribe path cannot reveal or
					// enable the plan select, so the baseline paints this radio
					// disabled (never operable-but-inert); the view module's
					// init callback enables it on hydration.
					?>
					<input
						type="radio"
						name="<?php echo esc_attr( $wcsl_mode_name ); ?>"
						value="subscribe"
						disabled="disabled"
						data-wp-on--change="actions.setMode"
						data-wp-init="callbacks.enableSubscribeRadio"
					/>
					<?php esc_html_e( 'Subscribe', 'woocommerce-subscriptions-lite' ); ?>
				</label>
			</li>
		</ul>
	<?php endif; ?>

	<div
		class="wc-subscriptions-lite-plan-picker__plans"
		data-wp-bind--hidden="state.isOneTime"
		<?php if ( $one_time_allowed ) : ?>
			hidden
		<?php endif; ?>
	>
		<label class="wc-subscriptions-lite-plan-picker__plans-label" for="<?php echo esc_attr( $wcsl_select_id ); ?>">
			<?php echo esc_html( $plan_select_label ); ?>
		</label>
		<select
			id="<?php echo esc_attr( $wcsl_select_id ); ?>"
			class="wc-subscriptions-lite-plan-picker__select"
			name="_wcsl_selling_plan_id"
			data-wp-bind--disabled="state.isOneTime"
			<?php disabled( $one_time_allowed ); ?>
		>
			<?php foreach ( $plans as $wcsl_plan ) : ?>
				<option
					value="<?php echo esc_attr( (string) $wcsl_plan->get_id() ); ?>"
					data-wcsl-plan-id="<?php echo esc_attr( (string) $wcsl_plan->get_id() ); ?>"
				>
					<?php
					// wc_price() wraps the amount in a span, so the option text
					// carries inline HTML - wp_kses_post keeps it safe. The
					// browser drops the tags inside <option> and shows the text.
					echo wp_kses_post( PlanOptionFormatter::format( $wcsl_plan, $base_price ) );
					?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	</div>
</fieldset>
