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
			name: '',
			description: '',
			interval: 1,
			period: 'month',
			expires: false,
			pricingType: 'percentage',
			pricingScope: 'all',
		} );
	} );

	it( 'maps a plan to form data', () => {
		const registry = buildFieldRegistry();
		const formData = planToFormData( registry, samplePlan );
		expect( formData ).toMatchObject( {
			name: 'Monthly',
			description: 'A monthly plan',
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

		expect( payload.name ).toBe( 'Monthly' );
		expect( payload.description ).toBe( 'A monthly plan' );
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
			name: '',
			interval: 0,
			pricingType: 'percentage',
			pricingValue: 150,
		} );
		expect( errors.name ).toBeTruthy();
		expect( errors.interval ).toBeTruthy();
		expect( errors.pricingValue ).toBeTruthy();
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
