/**
 * Built-in plan field descriptors.
 *
 * Each descriptor is a self-contained field definition consumed by both the
 * form (client/admin-react/plans/components/plan-form.js) and the transforms
 * layer (client/admin-react/plans/transforms.js). A descriptor owns:
 *
 *   {
 *     id,          // unique field id
 *     group,       // form section id (undefined = ungrouped, rendered first)
 *     default,     // partial form-data defaults this field contributes
 *     Edit,        // React component ({ data, onChange, errors, definitions })
 *     fromPlan,    // (plan) => partial form data
 *     toPayload,   // (formData, payload, plan) => next engine payload
 *     validate,    // (formData) => { [key]: message } | null
 *     listColumn,  // optional { id, label, render(item, definitions) }
 *   }
 *
 * Extensions add their own fields via the field-registry filter (see
 * ./index.js) using this same shape, so injected fields render and round-trip
 * without any change to Lite core.
 */

import { __ } from '@wordpress/i18n';
import { NameEdit, DescriptionEdit } from '../components/fields/text-fields';
import { FrequencyEdit } from '../components/fields/frequency-field';
import { ExpirationEdit } from '../components/fields/expiration-field';
import { DiscountEdit } from '../components/fields/discount-field';
import { formatFrequency, formatDiscount } from '../format';

export const GROUP_BILLING = 'billing';
export const GROUP_PRICING = 'pricing';

/**
 * The built-in field descriptors, in render order.
 *
 * @return {Array<Object>} Built-in descriptors.
 */
export function builtInFields() {
	return [
		{
			id: 'name',
			default: { name: '' },
			Edit: NameEdit,
			fromPlan: ( plan ) => ( { name: plan.name || '' } ),
			toPayload: ( formData, payload ) => ( {
				...payload,
				name: ( formData.name || '' ).trim(),
			} ),
			validate: ( formData ) =>
				( formData.name || '' ).trim()
					? null
					: {
							name: __(
								'Name is required.',
								'woocommerce-subscriptions-lite'
							),
					  },
			listColumn: {
				id: 'name',
				label: __( 'Name', 'woocommerce-subscriptions-lite' ),
				isTitle: true,
				render: ( item ) => item.name,
			},
		},
		{
			id: 'description',
			default: { description: '' },
			Edit: DescriptionEdit,
			fromPlan: ( plan ) => ( { description: plan.description || '' } ),
			toPayload: ( formData, payload ) => ( {
				...payload,
				description: ( formData.description || '' ).trim() || null,
			} ),
		},
		{
			id: 'frequency',
			group: GROUP_BILLING,
			default: { interval: 1, period: 'month' },
			Edit: FrequencyEdit,
			fromPlan: ( plan ) => ( {
				interval: plan.billing_policy?.interval || 1,
				period: plan.billing_policy?.period || 'month',
			} ),
			toPayload: ( formData, payload ) => ( {
				...payload,
				billing_policy: {
					...( payload.billing_policy || {} ),
					period: formData.period,
					interval: Number( formData.interval ),
				},
			} ),
			validate: ( formData ) =>
				Number( formData.interval ) < 1
					? {
							interval: __(
								'Interval must be at least 1.',
								'woocommerce-subscriptions-lite'
							),
					  }
					: null,
			listColumn: {
				id: 'frequency',
				label: __( 'Frequency', 'woocommerce-subscriptions-lite' ),
				render: ( item, definitions ) =>
					formatFrequency( item, definitions ),
			},
		},
		{
			id: 'expiration',
			group: GROUP_BILLING,
			default: { expires: false, maxCycles: '' },
			Edit: ExpirationEdit,
			fromPlan: ( plan ) => {
				const maxCycles = plan.billing_policy?.max_cycles || '';
				return {
					expires: Number( maxCycles ) > 0,
					maxCycles,
				};
			},
			toPayload: ( formData, payload ) => ( {
				...payload,
				billing_policy: {
					...( payload.billing_policy || {} ),
					max_cycles: formData.expires
						? Number( formData.maxCycles )
						: null,
				},
			} ),
			validate: ( formData ) =>
				formData.expires && Number( formData.maxCycles ) < 1
					? {
							maxCycles: __(
								'Total payments must be at least 1.',
								'woocommerce-subscriptions-lite'
							),
					  }
					: null,
		},
		{
			id: 'discount',
			group: GROUP_PRICING,
			default: {
				pricingType: 'percentage',
				pricingValue: '',
				pricingScope: 'all',
				durationCycles: '',
			},
			Edit: DiscountEdit,
			fromPlan: ( plan ) => {
				const firstPolicy = Array.isArray(
					plan.pricing_policy?.policies
				)
					? plan.pricing_policy.policies[ 0 ]
					: null;
				const durationCycles = firstPolicy?.duration_cycles || '';
				let pricingScope = 'all';
				if ( Number( durationCycles ) === 1 ) {
					pricingScope = 'first';
				} else if ( Number( durationCycles ) > 1 ) {
					pricingScope = 'n_cycles';
				}
				return {
					pricingType: firstPolicy?.type || 'percentage',
					pricingValue: firstPolicy?.value ?? '',
					pricingScope,
					durationCycles,
				};
			},
			toPayload: ( formData, payload, plan ) => {
				const existing = plan?.pricing_policy || {};
				const pricing = payload.pricing_policy || {
					policies: [],
					one_time_fees: Array.isArray( existing.one_time_fees )
						? existing.one_time_fees
						: [],
				};

				if ( formData.pricingValue === '' ) {
					return { ...payload, pricing_policy: pricing };
				}

				const entry = {
					type: formData.pricingType,
					value: Number( formData.pricingValue ),
				};
				if ( formData.pricingScope === 'first' ) {
					entry.duration_cycles = 1;
				} else if ( formData.pricingScope === 'n_cycles' ) {
					entry.duration_cycles = Number( formData.durationCycles );
				}

				return {
					...payload,
					pricing_policy: {
						...pricing,
						policies: [ ...pricing.policies, entry ],
					},
				};
			},
			validate: ( formData ) => {
				const errors = {};
				if (
					formData.pricingValue !== '' &&
					Number( formData.pricingValue ) < 0
				) {
					errors.pricingValue = __(
						'Discount value cannot be negative.',
						'woocommerce-subscriptions-lite'
					);
				}
				if (
					formData.pricingType === 'percentage' &&
					formData.pricingValue !== '' &&
					Number( formData.pricingValue ) > 100
				) {
					errors.pricingValue = __(
						'Percentage cannot exceed 100.',
						'woocommerce-subscriptions-lite'
					);
				}
				if (
					formData.pricingScope === 'n_cycles' &&
					Number( formData.durationCycles ) < 2
				) {
					errors.durationCycles = __(
						'Cycle count must be at least 2.',
						'woocommerce-subscriptions-lite'
					);
				}
				return Object.keys( errors ).length ? errors : null;
			},
			listColumn: {
				id: 'discount',
				label: __( 'Discount', 'woocommerce-subscriptions-lite' ),
				render: ( item ) => formatDiscount( item ),
			},
		},
	];
}
