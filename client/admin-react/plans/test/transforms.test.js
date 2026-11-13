/**
 * Registry-driven transforms tests.
 */

import { addFilter, removeFilter } from '@wordpress/hooks';
import { buildFieldRegistry, PLAN_FIELDS_FILTER } from '../fields';
import {
	formDataToPayload,
	formErrors,
	makeDefaultFormData,
	planToFormData,
	viewToQuery,
} from '../transforms';

const samplePlan = {
	id: 5,
	name: 'Monthly',
	description: 'A monthly plan',
	billing_policy: { period: 'month', interval: 2, max_cycles: 6 },
	pricing_policy: {
		policies: [ { type: 'percentage', value: 10, duration_cycles: 3 } ],
		one_time_fees: [
			{ kind: 'signup_fee', amount: 5, taxable: false, tax_class: null },
		],
	},
	status: 'active',
};

describe( 'registry transforms', () => {
	it( 'produces default form data for a create', () => {
		const registry = buildFieldRegistry();
		const defaults = makeDefaultFormData( registry );
		expect( defaults ).toMatchObject( {
			interval: 1,
			period: 'month',
			expires: false,
			pricingType: 'percentage',
			pricingScope: 'all',
		} );
		// A new plan has no name yet: the form suggests one from the frequency.
		expect( defaults.name ).toBeNull();
		expect( defaults ).not.toHaveProperty( 'description' );
	} );

	it( 'maps a plan to form data', () => {
		const registry = buildFieldRegistry();
		const formData = planToFormData( registry, samplePlan );
		expect( formData ).toMatchObject( {
			name: 'Monthly',
			interval: 2,
			period: 'month',
			expires: true,
			maxCycles: 6,
			pricingType: 'percentage',
			pricingValue: 10,
			pricingScope: 'n_cycles',
			durationCycles: 3,
		} );
	} );

	it( 'round-trips a plan through form data and back to a payload', () => {
		const registry = buildFieldRegistry();
		const formData = planToFormData( registry, samplePlan );
		const payload = formDataToPayload( registry, formData, samplePlan );

		// The stored name is sent back as is, never a derived label.
		expect( payload.name ).toBe( 'Monthly' );
		expect( payload ).not.toHaveProperty( 'description' );
		expect( payload.billing_policy ).toMatchObject( {
			period: 'month',
			interval: 2,
			max_cycles: 6,
		} );
		expect( payload.pricing_policy.policies ).toEqual( [
			{ type: 'percentage', value: 10, duration_cycles: 3 },
		] );
		// No status key when editing.
		expect( payload.status ).toBeUndefined();
	} );

	it( 'preserves engine data the registry does not own (one_time_fees)', () => {
		const registry = buildFieldRegistry();
		const formData = planToFormData( registry, samplePlan );
		const payload = formDataToPayload( registry, formData, samplePlan );
		expect( payload.pricing_policy.one_time_fees ).toEqual(
			samplePlan.pricing_policy.one_time_fees
		);
	} );

	it( 'keeps billing keys the form does not edit when editing', () => {
		const registry = buildFieldRegistry();
		const plan = {
			...samplePlan,
			billing_policy: {
				...samplePlan.billing_policy,
				trial_duration: { length: 7, unit: 'day' },
				min_cycles: 2,
			},
		};
		const formData = {
			...planToFormData( registry, plan ),
			interval: 3,
			expires: false,
		};

		expect(
			formDataToPayload( registry, formData, plan ).billing_policy
		).toEqual( {
			period: 'month',
			interval: 3,
			max_cycles: null,
			trial_duration: { length: 7, unit: 'day' },
			min_cycles: 2,
		} );
	} );

	it( 'keeps pricing keys the form does not own when editing', () => {
		const registry = buildFieldRegistry();
		const plan = {
			...samplePlan,
			pricing_policy: {
				...samplePlan.pricing_policy,
				custom_key: { kept: true },
			},
		};
		const formData = planToFormData( registry, plan );

		const pricing = formDataToPayload(
			registry,
			formData,
			plan
		).pricing_policy;

		expect( pricing.custom_key ).toEqual( { kept: true } );
		expect( pricing.policies ).toEqual( [
			{ type: 'percentage', value: 10, duration_cycles: 3 },
		] );
		expect( pricing.one_time_fees ).toEqual(
			samplePlan.pricing_policy.one_time_fees
		);
	} );

	it( 'sends only the edited billing keys for a new plan', () => {
		const registry = buildFieldRegistry();
		const payload = formDataToPayload(
			registry,
			makeDefaultFormData( registry )
		);

		expect( payload.billing_policy ).toEqual( {
			period: 'month',
			interval: 1,
			max_cycles: null,
		} );
	} );

	it( 'queries the list in id order by default', () => {
		expect( viewToQuery( {} ) ).toMatchObject( {
			orderby: 'id',
			order: 'asc',
		} );
	} );

	it( 'sets the default status when creating', () => {
		const registry = buildFieldRegistry();
		const formData = makeDefaultFormData( registry );
		const payload = formDataToPayload( registry, formData, null );
		expect( payload.status ).toBe( 'active' );
	} );

	it( 'reports validation errors from descriptors', () => {
		const registry = buildFieldRegistry();
		const errors = formErrors( registry, {
			...makeDefaultFormData( registry ),
			interval: 0,
			pricingType: 'percentage',
			pricingValue: 150,
		} );
		expect( errors.interval ).toBeTruthy();
		expect( errors.pricingValue ).toBeTruthy();
		expect( errors.name ).toBeUndefined();
	} );

	describe( 'plan name', () => {
		it( 'suggests the frequency label for a new plan', () => {
			const registry = buildFieldRegistry();
			const payload = formDataToPayload(
				registry,
				makeDefaultFormData( registry ),
				null
			);
			expect( payload.name ).toBe( '1 month' );
		} );

		it( 'follows the frequency until the merchant names a new plan', () => {
			const registry = buildFieldRegistry();
			const payload = formDataToPayload(
				registry,
				{
					...makeDefaultFormData( registry ),
					interval: 3,
					period: 'week',
				},
				null
			);
			expect( payload.name ).toBe( '3 weeks' );
		} );

		it( 'keeps the stored name when the cadence changes', () => {
			const registry = buildFieldRegistry();
			const formData = {
				...planToFormData( registry, samplePlan ),
				interval: 1,
				period: 'year',
			};
			const payload = formDataToPayload( registry, formData, samplePlan );
			expect( payload.name ).toBe( 'Monthly' );
		} );

		it( 'sends a renamed plan with surrounding whitespace trimmed', () => {
			const registry = buildFieldRegistry();
			const formData = {
				...planToFormData( registry, samplePlan ),
				name: '  Coffee refill  ',
			};
			const payload = formDataToPayload( registry, formData, samplePlan );
			expect( payload.name ).toBe( 'Coffee refill' );
		} );

		it.each( [
			[ 'an empty string', '' ],
			[ 'whitespace only', '   ' ],
		] )(
			'requires a name when it is %s, without falling back',
			( _description, name ) => {
				const registry = buildFieldRegistry();
				const formData = {
					...planToFormData( registry, samplePlan ),
					name,
				};
				expect( formErrors( registry, formData ).name ).toBe(
					'Name is required.'
				);
			}
		);
	} );

	describe( 'pricing entry keys the form does not edit', () => {
		const planWithEntry = ( entry, ...others ) => ( {
			...samplePlan,
			pricing_policy: {
				...samplePlan.pricing_policy,
				policies: [ entry, ...others ],
			},
		} );

		const payloadFor = ( plan, patch = {} ) => {
			const registry = buildFieldRegistry();
			const formData = {
				...planToFormData( registry, plan ),
				...patch,
			};
			return formDataToPayload( registry, formData, plan ).pricing_policy
				.policies;
		};

		it( 'keeps starting_cycle and unknown keys through an edit', () => {
			const plan = planWithEntry( {
				type: 'percentage',
				value: 10,
				starting_cycle: 3,
				duration_cycles: 3,
				custom_key: 'kept',
			} );

			expect(
				payloadFor( plan, { pricingValue: 15, interval: 1 } )
			).toEqual( [
				{
					type: 'percentage',
					value: 15,
					starting_cycle: 3,
					duration_cycles: 3,
					custom_key: 'kept',
				},
			] );
		} );

		it( 'keeps starting_cycle when the scope drops duration_cycles', () => {
			const plan = planWithEntry( {
				type: 'percentage',
				value: 10,
				starting_cycle: 2,
				duration_cycles: 3,
			} );

			expect( payloadFor( plan, { pricingScope: 'all' } ) ).toEqual( [
				{ type: 'percentage', value: 10, starting_cycle: 2 },
			] );
		} );

		it( 'keeps starting_cycle and drops the value when switching to BOGO', () => {
			const plan = planWithEntry( {
				type: 'fixed_amount',
				value: 5,
				starting_cycle: 2,
			} );

			expect(
				payloadFor( plan, { pricingType: 'bogo', pricingValue: '' } )
			).toEqual( [ { type: 'bogo', starting_cycle: 2 } ] );
		} );

		it( 'keeps the entries after the first, which the form does not show', () => {
			const second = {
				type: 'fixed_amount',
				value: 2,
				starting_cycle: 6,
			};
			const plan = planWithEntry(
				{ type: 'percentage', value: 10, duration_cycles: 3 },
				second
			);

			expect( payloadFor( plan, { pricingValue: 20 } ) ).toEqual( [
				{ type: 'percentage', value: 20, duration_cycles: 3 },
				second,
			] );
			// Clearing the edited discount removes only the first entry.
			expect( payloadFor( plan, { pricingValue: '' } ) ).toEqual( [
				second,
			] );
		} );
	} );

	describe( 'BOGO pricing', () => {
		const bogoPlan = {
			id: 7,
			name: 'Monthly BOGO',
			billing_policy: { period: 'month', interval: 1 },
			pricing_policy: {
				// BOGO entries are value-less; a stored value is ignored.
				policies: [ { type: 'bogo', value: 0, duration_cycles: 1 } ],
				one_time_fees: [],
			},
			status: 'active',
		};

		it( 'maps a value-less BOGO plan to empty form value', () => {
			const registry = buildFieldRegistry();
			const formData = planToFormData( registry, bogoPlan );
			expect( formData ).toMatchObject( {
				pricingType: 'bogo',
				pricingValue: '',
				pricingScope: 'first',
			} );
		} );

		it( 'writes a value-less BOGO entry with its cycle scope', () => {
			const registry = buildFieldRegistry();
			const formData = planToFormData( registry, bogoPlan );
			const payload = formDataToPayload( registry, formData, bogoPlan );
			expect( payload.pricing_policy.policies ).toEqual( [
				{ type: 'bogo', duration_cycles: 1 },
			] );
			// BOGO carries no numeric amount.
			expect( payload.pricing_policy.policies[ 0 ] ).not.toHaveProperty(
				'value'
			);
		} );

		it( 'writes BOGO even though the amount field is empty', () => {
			const registry = buildFieldRegistry();
			const payload = formDataToPayload(
				registry,
				{
					...makeDefaultFormData( registry ),
					pricingType: 'bogo',
					pricingValue: '',
					pricingScope: 'all',
				},
				null
			);
			expect( payload.pricing_policy.policies ).toEqual( [
				{ type: 'bogo' },
			] );
		} );
	} );

	describe( 'injected fields', () => {
		const NAMESPACE = 'test/min-cycles';

		beforeEach( () => {
			addFilter( PLAN_FIELDS_FILTER, NAMESPACE, ( fields ) => [
				...fields,
				{
					id: 'minCycles',
					group: 'billing',
					default: { minCycles: '' },
					fromPlan: ( plan ) => ( {
						minCycles: plan.billing_policy?.min_cycles || '',
					} ),
					toPayload: ( formData, payload ) => ( {
						...payload,
						billing_policy: {
							...( payload.billing_policy || {} ),
							min_cycles:
								formData.minCycles === ''
									? null
									: Number( formData.minCycles ),
						},
					} ),
				},
			] );
		} );

		afterEach( () => {
			removeFilter( PLAN_FIELDS_FILTER, NAMESPACE );
		} );

		it( 'round-trips an injected field with no Lite core changes', () => {
			const registry = buildFieldRegistry();
			const plan = {
				...samplePlan,
				billing_policy: {
					...samplePlan.billing_policy,
					min_cycles: 2,
				},
			};
			const formData = planToFormData( registry, plan );
			expect( formData.minCycles ).toBe( 2 );

			const payload = formDataToPayload( registry, formData, plan );
			expect( payload.billing_policy.min_cycles ).toBe( 2 );
		} );
	} );
} );
