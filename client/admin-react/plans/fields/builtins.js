/**
 * Built-in plan field descriptors.
 *
 * Each descriptor is a self-contained field definition consumed by both the
 * form (client/admin-react/plans/components/plan-form.js) and the transforms
 * layer (client/admin-react/plans/transforms.js). A descriptor owns:
 *
 *   {
 *     id,          // unique field id
 *     default,     // partial form-data defaults this field contributes
 *     Edit,        // React component ({ data, onChange, errors, definitions })
 *     fromPlan,    // (plan) => partial form data
 *     toPayload,   // (formData, payload, plan) => next engine payload
 *     validate,    // (formData) => { [key]: message } | null
 *     listColumn,  // optional { id, label, render(item, definitions) }
 *   }
 *
 * Form fields and list columns render in registry order. Extensions add
 * their own fields via the field-registry filter (see ./index.js) using this
 * same shape, positioning themselves by where they insert in the filtered
 * array, so injected fields render and round-trip without any change to
 * Lite core.
 *
 * `toPayload` runs in registry order, each step receiving the previous
 * payload. On edit, `payload.pricing_policy.policies` already carries the
 * stored entries the discount field does not edit, so a later field replaces
 * its own entry by key and never appends.
 */

import { __ } from '@wordpress/i18n';
import { NameEdit } from '../components/fields/name-field';
import { FrequencyEdit } from '../components/fields/frequency-field';
import { ExpirationEdit } from '../components/fields/expiration-field';
import { DiscountEdit } from '../components/fields/discount-field';
import { formatFrequency, formatDiscount, formatExpiration } from '../format';

/**
 * The built-in field descriptors, in render order.
 *
 * @return {Array<Object>} Built-in descriptors.
 */
export function builtInFields() {
	return [
		{
			id: 'name',
			// `null` until the merchant types a name; the form shows and saves
			// a suggested name meanwhile. A stored plan loads its own name.
			default: { name: null },
			Edit: NameEdit,
			fromPlan: ( plan ) => ( { name: plan.name ?? '' } ),
			toPayload: ( formData, payload ) =>
				typeof formData.name === 'string'
					? { ...payload, name: formData.name.trim() }
					: payload,
			// Required, as the engine requires it; an emptied name is never
			// replaced with the suggested one.
			validate: ( formData ) =>
				typeof formData.name === 'string' && ! formData.name.trim()
					? {
							name: __(
								'Name is required.',
								'woocommerce-subscriptions-lite'
							),
					  }
					: null,
		},
		{
			id: 'frequency',
			default: { interval: 1, period: 'month' },
			Edit: FrequencyEdit,
			fromPlan: ( plan ) => ( {
				interval: plan.billing_policy?.interval || 1,
				period: plan.billing_policy?.period || 'month',
			} ),
			// A PATCH replaces the whole billing_policy, so an edit starts from
			// the stored payload to keep keys this form does not edit.
			toPayload: ( formData, payload, plan ) => ( {
				...payload,
				billing_policy: {
					...( payload.billing_policy || plan?.billing_policy || {} ),
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
			id: 'discount',
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
				// BOGO entries are value-less; a stored value is ignored, so the
				// value field stays empty rather than surfacing a stray "0".
				const isBogo = firstPolicy?.type === 'bogo';
				return {
					pricingType: firstPolicy?.type || 'percentage',
					pricingValue: isBogo ? '' : firstPolicy?.value ?? '',
					pricingScope,
					durationCycles,
				};
			},
			toPayload: ( formData, payload, plan ) => {
				// A PATCH replaces the whole pricing_policy, so an edit starts
				// from the stored payload: top-level keys, the keys of the first
				// entry this field does not edit (e.g. starting_cycle), and the
				// entries after the first, which this field does not show.
				const existing = plan?.pricing_policy || {};
				const [ storedEntry = {}, ...otherEntries ] = Array.isArray(
					existing.policies
				)
					? existing.policies
					: [];
				const pricing = payload.pricing_policy || {
					...existing,
					policies: [],
					one_time_fees: Array.isArray( existing.one_time_fees )
						? existing.one_time_fees
						: [],
				};

				// BOGO is a value-less policy, so it is written even though the
				// value field is empty; every other type needs a value to apply.
				// Clearing the discount removes every entry, hidden ones too:
				// the plan then has no discount at all.
				const isBogo = formData.pricingType === 'bogo';
				const entries = [];
				if ( isBogo || formData.pricingValue !== '' ) {
					const entry = {
						...storedEntry,
						type: formData.pricingType,
					};
					if ( isBogo ) {
						delete entry.value;
					} else {
						entry.value = Number( formData.pricingValue );
					}
					if ( formData.pricingScope === 'first' ) {
						entry.duration_cycles = 1;
					} else if ( formData.pricingScope === 'n_cycles' ) {
						entry.duration_cycles = Number(
							formData.durationCycles
						);
					} else {
						delete entry.duration_cycles;
					}
					entries.push( entry, ...otherEntries );
				}

				return {
					...payload,
					pricing_policy: {
						...pricing,
						policies: [ ...pricing.policies, ...entries ],
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
						'Discount cannot be negative.',
						'woocommerce-subscriptions-lite'
					);
				}
				if (
					formData.pricingType === 'percentage' &&
					formData.pricingValue !== '' &&
					Number( formData.pricingValue ) > 100
				) {
					errors.pricingValue = __(
						'Percentage discount cannot exceed 100%.',
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
		{
			id: 'expiration',
			default: { expires: false, maxCycles: '' },
			Edit: ExpirationEdit,
			fromPlan: ( plan ) => {
				const maxCycles = plan.billing_policy?.max_cycles || '';
				return {
					expires: Number( maxCycles ) > 0,
					maxCycles,
				};
			},
			toPayload: ( formData, payload, plan ) => ( {
				...payload,
				billing_policy: {
					...( payload.billing_policy || plan?.billing_policy || {} ),
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
			listColumn: {
				id: 'expiration',
				label: __( 'Expiration', 'woocommerce-subscriptions-lite' ),
				render: ( item ) => formatExpiration( item ),
			},
		},
	];
}
