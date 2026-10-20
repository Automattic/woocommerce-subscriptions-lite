/**
 * Plan save-error message tests.
 */

import { getSaveErrorMessages } from '../utils/save-error';

const FALLBACK = 'Subscription plan could not be saved.';

describe( 'getSaveErrorMessages', () => {
	it( 'returns one item per field from data.errors, in key order', () => {
		const error = {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): billing_policy, pricing_policy',
			data: {
				status: 400,
				params: {
					billing_policy:
						'billing_policy.min_cycles: Must be less than or equal to 2.',
					pricing_policy:
						'pricing_policy.policies.0.value: Must not be negative. pricing_policy.policies.1.value: Must not be negative.',
				},
				errors: {
					'billing_policy.min_cycles': [
						{
							code: 'out_of_bounds',
							message: 'Must be less than or equal to 2.',
							params: { max: 2 },
						},
					],
					'pricing_policy.policies.0.value': [
						{
							code: 'out_of_bounds',
							message: 'Must not be negative.',
							params: { min: 0 },
						},
					],
					'pricing_policy.policies.1.value': [
						{
							code: 'out_of_bounds',
							message: 'Must not be negative.',
							params: { min: 0 },
						},
					],
				},
			},
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'billing_policy.min_cycles: Must be less than or equal to 2.',
			'pricing_policy.policies.0.value: Must not be negative.',
			'pricing_policy.policies.1.value: Must not be negative.',
		] );
	} );

	it( 'joins several messages for one field into its item', () => {
		const error = {
			data: {
				errors: {
					'pricing_policy.policies.0.value': [
						{
							code: 'out_of_bounds',
							message: 'Must not be negative.',
						},
						{ code: 'other', message: 'Must be a whole number.' },
						{ code: 'empty', message: '' },
					],
				},
			},
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'pricing_policy.policies.0.value: Must not be negative. Must be a whole number.',
		] );
	} );

	it( 'appends fieldless additional errors to the field items', () => {
		const error = {
			message: 'Invalid parameter(s): name',
			data: {
				params: { name: 'name: This field is required.' },
				errors: {
					name: [
						{
							code: 'required',
							message: 'This field is required.',
						},
					],
				},
			},
			additional_errors: [
				{
					code: 'other_refusal',
					message: 'Another extension said no.',
				},
				{ code: 'no_message', message: '' },
			],
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'name: This field is required.',
			'Another extension said no.',
		] );
	} );

	it( 'appends fieldless additional errors to a fieldless message', () => {
		const error = {
			code: 'earlier_error',
			message: 'Plan rejected.',
			data: { status: 400 },
			additional_errors: [
				{
					code: 'other_refusal',
					message: 'Another extension said no.',
				},
			],
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'Plan rejected.',
			'Another extension said no.',
		] );
	} );

	it( 'falls back to data.params when data.errors is absent', () => {
		const error = {
			message: 'Invalid parameter(s): name',
			data: {
				params: {
					name: 'name: This field is required.',
					other: '',
					bad: 5,
				},
			},
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'name: This field is required.',
		] );
	} );

	it( 'shows the reason a restore was refused, not the generic message', () => {
		const error = {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): billing_policy',
			data: {
				status: 400,
				params: {
					billing_policy: 'billing_policy: This field is required.',
				},
				details: {
					billing_policy: {
						code: 'required',
						message: 'billing_policy: This field is required.',
						data: { field: 'billing_policy', params: {} },
					},
				},
				errors: {
					billing_policy: [
						{
							code: 'required',
							message: 'This field is required.',
							params: {},
						},
					],
				},
			},
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'billing_policy: This field is required.',
		] );
	} );

	it( 'falls back to the error message when params is empty', () => {
		const error = {
			code: 'earlier_error',
			message: 'Plan rejected.',
			data: { status: 400, params: {}, errors: {} },
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'Plan rejected.',
		] );
	} );

	it( 'falls back to the error message when there is no data', () => {
		expect(
			getSaveErrorMessages( { message: 'Plan rejected.' }, FALLBACK )
		).toEqual( [ 'Plan rejected.' ] );
	} );

	it( 'falls back to the fallback when the message is empty', () => {
		expect(
			getSaveErrorMessages( { message: '', data: {} }, FALLBACK )
		).toEqual( [ FALLBACK ] );
	} );

	it( 'falls back to the fallback when there is no message', () => {
		expect(
			getSaveErrorMessages( { code: 'unknown', data: {} }, FALLBACK )
		).toEqual( [ FALLBACK ] );
	} );

	it.each( [ undefined, null, 'Network error', 42 ] )(
		'falls back to the fallback for a non-object error (%p)',
		( error ) => {
			expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
				FALLBACK,
			] );
		}
	);
} );
