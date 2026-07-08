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
			interval: 1,
			period: 'month',
			expires: false,
			pricingType: 'percentage',
			pricingScope: 'all',
		} );
		// There are no name/description fields; the name is derived at save.
		expect( defaults ).not.toHaveProperty( 'name' );
		expect( defaults ).not.toHaveProperty( 'description' );
	} );

	it( 'maps a plan to form data', () => {
		const registry = buildFieldRegistry();
		const formData = planToFormData( registry, samplePlan );
		expect( formData ).toMatchObject( {
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

		// The name is derived from the billing frequency (same formatter as
		// the Frequency list column); there is no description field.
		expect( payload.name ).toBe( '2 months' );
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
		// No name field, so nothing can report a name error.
		expect( errors.name ).toBeUndefined();
	} );

	it( 'derives the name from the billing frequency when creating', () => {
		const registry = buildFieldRegistry();
		const payload = formDataToPayload(
			registry,
			makeDefaultFormData( registry ),
			null
		);
		expect( payload.name ).toBe( '1 month' );
	} );

	describe( 'BOGO pricing', () => {
		const bogoPlan = {
			id: 7,
			name: 'Monthly BOGO',
			billing_policy: { period: 'month', interval: 1 },
			pricing_policy: {
				// The engine stores BOGO value-less, normalized to 0.
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

	describe( 'injected name field', () => {
		const NAMESPACE = 'test/custom-name';

		const injectName = ( name ) => {
			addFilter( PLAN_FIELDS_FILTER, NAMESPACE, ( fields ) => [
				...fields,
				{
					id: 'customName',
					toPayload: ( formData, payload ) => ( {
						...payload,
						name,
					} ),
				},
			] );
		};

		const buildPayload = () => {
			const registry = buildFieldRegistry();
			return formDataToPayload(
				registry,
				makeDefaultFormData( registry ),
				null
			);
		};

		afterEach( () => {
			removeFilter( PLAN_FIELDS_FILTER, NAMESPACE );
		} );

		it( 'wins over the derived frequency name', () => {
			injectName( 'Custom name' );
			expect( buildPayload().name ).toBe( 'Custom name' );
		} );

		it.each( [
			[ 'an empty string', '' ],
			[ 'whitespace only', '   ' ],
		] )(
			'falls back to the derived name when the injected name is %s',
			( _description, name ) => {
				injectName( name );
				expect( buildPayload().name ).toBe( '1 month' );
			}
		);
	} );
} );
