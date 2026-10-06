/**
 * Lite plan display order tests.
 */

import { sortByPlanOrder } from '../order';

const plans = [ { id: 3 }, { id: 1 }, { id: 2 } ];
const ids = ( list ) => list.map( ( plan ) => plan.id );

describe( 'sortByPlanOrder', () => {
	it( 'sorts by id when there is no saved order', () => {
		expect( ids( sortByPlanOrder( plans, [] ) ) ).toEqual( [ 1, 2, 3 ] );
		expect( ids( sortByPlanOrder( plans, undefined ) ) ).toEqual( [
			1, 2, 3,
		] );
	} );

	it( 'puts listed plans first in order, then the rest by id', () => {
		expect( ids( sortByPlanOrder( plans, [ 3, 1 ] ) ) ).toEqual( [
			3, 1, 2,
		] );
	} );

	it( 'ignores stale ids', () => {
		expect( ids( sortByPlanOrder( plans, [ 99, 2, 42 ] ) ) ).toEqual( [
			2, 1, 3,
		] );
	} );

	it( 'does not mutate the input', () => {
		const input = [ ...plans ];
		sortByPlanOrder( input, [ 2 ] );

		expect( ids( input ) ).toEqual( [ 3, 1, 2 ] );
	} );
} );
