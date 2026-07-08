/**
 * Plan picker view module.
 *
 * The Interactivity API store behind templates/single-product/plan-picker.php:
 * the purchase-mode radios drive the plan select's visibility and disabled
 * state through the template's `data-wp-bind` directives. All option strings
 * are server-rendered; on variable products this module only swaps each
 * option's innerHTML from the pre-rendered `subscriptions_lite.option_html`
 * map in the variation payload (and restores the initial strings on reset).
 * No prices are computed client-side.
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

import './style.scss';

store( 'woocommerce-subscriptions-lite/plan-picker', {
	state: {
		get isOneTime() {
			return getContext().mode === 'one-time';
		},
		/**
		 * Variable products keep the whole picker hidden until a variation is
		 * chosen; simple products seed `variationChosen` true and never hide.
		 */
		get isPickerHidden() {
			const context = getContext();
			return context.isVariable && ! context.variationChosen;
		},
	},
	actions: {
		setMode() {
			const { ref } = getElement();
			if ( ref.checked ) {
				getContext().mode = ref.value;
			}
		},
	},
	callbacks: {
		/**
		 * Enable the Subscribe radio once the module is running.
		 *
		 * The no-JS baseline paints the radio disabled because without this
		 * module the subscribe path cannot reveal or enable the plan select -
		 * it would be operable but inert. `disabled` is not a bound prop on
		 * the radio, so later re-renders never restore it.
		 */
		enableSubscribeRadio() {
			getElement().ref.disabled = false;
		},

		/**
		 * Bridge WooCommerce's variation events into option-text swaps.
		 *
		 * The variations form emits `found_variation` and `reset_data`
		 * through jQuery, which WooCommerce always loads on variable product
		 * pages - so the bridge (guardedly) rides on window.jQuery. Bound on
		 * island init for pickers living inside a variations form; simple
		 * products carry no `data-wp-init` and never run this.
		 */
		initVariationBridge() {
			const { ref } = getElement();
			const form = ref.closest( 'form.variations_form' );
			if ( ! form || typeof window.jQuery !== 'function' ) {
				return;
			}

			const context = getContext();

			const options = Array.from(
				ref.querySelectorAll( 'option[data-wcsl-plan-id]' )
			);
			const initialHtml = new Map(
				options.map( ( option ) => [ option, option.innerHTML ] )
			);

			const $form = window.jQuery( form );

			$form.on( 'found_variation.wcslPlanPicker', ( _, variation ) => {
				// A variation is now selected: reveal the picker.
				context.variationChosen = true;

				const optionHtml =
					variation &&
					variation.subscriptions_lite &&
					variation.subscriptions_lite.option_html;
				if ( ! optionHtml ) {
					return;
				}
				options.forEach( ( option ) => {
					const html =
						optionHtml[
							option.getAttribute( 'data-wcsl-plan-id' )
						];
					if ( html ) {
						option.innerHTML = html;
					}
				} );
			} );

			// `reset_data` fires on a cleared selection, `hide_variation` when
			// the chosen attributes match nothing: hide the picker again and
			// restore the seed option strings.
			$form.on(
				'reset_data.wcslPlanPicker hide_variation.wcslPlanPicker',
				() => {
					context.variationChosen = false;
					options.forEach( ( option ) => {
						option.innerHTML = initialHtml.get( option );
					} );
				}
			);

			return () => $form.off( '.wcslPlanPicker' );
		},
	},
} );
