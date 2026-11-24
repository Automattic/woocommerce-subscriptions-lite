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
 * payload. It must be pure and must not throw on partial input: the form
 * also calls it while rendering an unnamed new plan, on unvalidated data, to
 * suggest the name.
 *
 * Pricing: the discount field creates `pricing_policy` and edits the first
 * `policies` entry that carries its marker (`field: 'discount'`, see
 * isDiscountEntry()); position plays no part, and an unmarked entry is never
 * the discount. It writes the marker on its entry, keeps an edited entry in
 * its stored position, inserts a new one first, and passes on every other
 * stored entry unchanged. Another pricing field registers AFTER discount,
 * finds its own entry by its own key and replaces it; it never writes the
 * discount marker. Registering a pricing field before discount is
 * unsupported. Clearing a stored discount empties `policies`; a plan stored
 * with no discount keeps its entries when the discount stays empty.
 */

import { __ } from '@wordpress/i18n';
import { NameEdit } from '../components/fields/name-field';
import { FrequencyEdit } from '../components/fields/frequency-field';
import { ExpirationEdit } from '../components/fields/expiration-field';
import { DiscountEdit } from '../components/fields/discount-field';
import { formatFrequency, formatDiscount, formatExpiration } from '../format';

/**
 * The key and value that mark a `pricing_policy.policies` entry as the one the
 * discount field edits. The value is the discount descriptor's id.
 */
export const DISCOUNT_MARKER_KEY = 'field';
export const DISCOUNT_MARKER_VALUE = 'discount';

/**
 * Whether a pricing entry is the discount field's entry, by its marker.
 *
 * @param {Object} entry A `pricing_policy.policies` entry.
 * @return {boolean} True when the entry carries the discount marker.
 */
export function isDiscountEntry( entry ) {
	return (
		!! entry &&
		typeof entry === 'object' &&
		entry[ DISCOUNT_MARKER_KEY ] === DISCOUNT_MARKER_VALUE
	);
}

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
				const discountEntry = Array.isArray(
					plan.pricing_policy?.policies
				)
					? plan.pricing_policy.policies.find( isDiscountEntry )
					: null;
				const durationCycles = discountEntry?.duration_cycles || '';
				let pricingScope = 'all';
				if ( Number( durationCycles ) === 1 ) {
					pricingScope = 'first';
				} else if ( Number( durationCycles ) > 1 ) {
					pricingScope = 'n_cycles';
				}
				// BOGO entries are value-less; a stored value is ignored, so the
				// value field stays empty rather than surfacing a stray "0".
				const isBogo = discountEntry?.type === 'bogo';
				return {
					pricingType: discountEntry?.type || 'percentage',
					pricingValue: isBogo ? '' : discountEntry?.value ?? '',
					pricingScope,
					durationCycles,
				};
			},
			toPayload: ( formData, payload, plan ) => {
				// A PATCH replaces the whole pricing_policy, so an edit starts
				// from the stored payload: top-level keys, the keys of the
				// discount entry this field does not edit (e.g. starting_cycle),
				// and every other entry, which this field does not show.
				const existing = plan?.pricing_policy || {};
				const storedEntries = Array.isArray( existing.policies )
					? existing.policies
					: [];
				const discountIndex =
					storedEntries.findIndex( isDiscountEntry );
				const pricing = payload.pricing_policy || {
					...existing,
					policies: [],
					one_time_fees: Array.isArray( existing.one_time_fees )
						? existing.one_time_fees
						: [],
				};

				// BOGO is a value-less policy, so it is written even though the
				// value field is empty; every other type needs a value to apply.
				const isBogo = formData.pricingType === 'bogo';
				let entries = storedEntries;
				if ( isBogo || formData.pricingValue !== '' ) {
					const entry = {
						...( storedEntries[ discountIndex ] || {} ),
						[ DISCOUNT_MARKER_KEY ]: DISCOUNT_MARKER_VALUE,
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
					// An edited discount keeps its stored position, as entries
					// apply in order; a new one goes first.
					entries =
						discountIndex === -1
							? [ entry, ...storedEntries ]
							: storedEntries.map( ( stored, index ) =>
									index === discountIndex ? entry : stored
							  );
				} else if ( discountIndex !== -1 ) {
					// Clearing a stored discount removes every entry, hidden
					// ones too: the plan then has no discount at all. A plan
					// with no discount keeps its entries as stored.
					entries = [];
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
