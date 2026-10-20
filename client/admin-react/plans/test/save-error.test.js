/**
 * Plan save-error message tests.
 */

import { getSaveErrorMessages } from '../utils/save-error';

const FALLBACK = 'Subscription plan could not be saved.';

describe( 'getSaveErrorMessages', () => {
	it( 'returns one message per field from data.params', () => {
		const error = {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): billing_policy, pricing_policy',
			data: {
				status: 400,
				params: {
					billing_policy:
						'billing_policy[min_cycles] must be less than or equal to 2.',
					pricing_policy:
						'pricing_policy[policies][0][value] must not be negative.',
				},
			},
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'billing_policy[min_cycles] must be less than or equal to 2.',
			'pricing_policy[policies][0][value] must not be negative.',
		] );
	} );

	it( 'skips params that are not messages', () => {
		const error = {
			message: 'Invalid parameter(s): name',
			data: { params: { name: 'name is required.', other: '', bad: 5 } },
		};

		expect( getSaveErrorMessages( error, FALLBACK ) ).toEqual( [
			'name is required.',
		] );
	} );

	it( 'falls back to the error message when params is empty', () => {
		const error = {
			code: 'earlier_error',
			message: 'Plan rejected.',
			data: { status: 400, params: {} },
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
