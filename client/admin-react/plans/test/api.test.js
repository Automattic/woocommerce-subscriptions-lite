/**
 * Plans REST client tests.
 */

import apiFetch from '@wordpress/api-fetch';
import { reorderPlans } from '../api';
import { config } from '../config';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'reorderPlans', () => {
	it( 'posts the ids to the Lite order route', async () => {
		apiFetch.mockResolvedValueOnce( { ids: [ 2, 1 ] } );

		await expect( reorderPlans( [ 2, 1 ] ) ).resolves.toEqual( {
			ids: [ 2, 1 ],
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: config.orderPath,
			method: 'POST',
			data: { ids: [ 2, 1 ] },
		} );
		expect( config.orderPath ).toBe(
			'/wc/v3/subscriptions-lite/plans/reorder'
		);
	} );
} );
