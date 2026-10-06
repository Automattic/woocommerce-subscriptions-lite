/**
 * Lite plan display order tests.
 */

import { createReorderQueue, sortByPlanOrder } from '../order';

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

	it( 'keeps the first position of a repeated id', () => {
		expect( ids( sortByPlanOrder( plans, [ 2, 3, 2 ] ) ) ).toEqual( [
			2, 3, 1,
		] );
	} );

	it( 'does not mutate the input', () => {
		const input = [ ...plans ];
		sortByPlanOrder( input, [ 2 ] );

		expect( ids( input ) ).toEqual( [ 3, 1, 2 ] );
	} );
} );

describe( 'createReorderQueue', () => {
	const deferred = () => {
		let resolve;
		let reject;
		const promise = new Promise( ( res, rej ) => {
			resolve = res;
			reject = rej;
		} );
		return { promise, resolve, reject };
	};

	it( 'applies the saved order from the response, not the requested one', async () => {
		const apply = jest.fn();
		const reorder = createReorderQueue(
			() => Promise.resolve( { ids: [ 2, 1, 9 ] } ),
			apply
		);

		await reorder( [ 2, 1 ] );

		expect( apply.mock.calls ).toEqual( [ [ [ 2, 1, 9 ] ] ] );
	} );

	it( 'starts a save only after the previous one settles and applies only the newest response', async () => {
		const first = deferred();
		const save = jest
			.fn()
			.mockReturnValueOnce( first.promise )
			.mockResolvedValueOnce( { ids: [ 30, 10, 20 ] } );
		const apply = jest.fn();
		const reorder = createReorderQueue( save, apply );

		const a = reorder( [ 1, 3, 2 ] );
		const b = reorder( [ 3, 1, 2 ] );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( save ).toHaveBeenCalledTimes( 1 );

		first.resolve( { ids: [ 10, 30, 20 ] } );
		await a;

		expect( apply ).not.toHaveBeenCalled();

		await b;

		expect( save ).toHaveBeenNthCalledWith( 2, [ 3, 1, 2 ] );
		expect( apply.mock.calls ).toEqual( [ [ [ 30, 10, 20 ] ] ] );
	} );

	it( 'keeps saving after a failed save', async () => {
		const first = deferred();
		const save = jest
			.fn()
			.mockReturnValueOnce( first.promise )
			.mockResolvedValueOnce( { ids: [ 20 ] } );
		const apply = jest.fn();
		const reorder = createReorderQueue( save, apply );

		const a = reorder( [ 1 ] );
		const b = reorder( [ 2 ] );
		first.reject( new Error( 'Nope' ) );

		await expect( a ).rejects.toThrow( 'Nope' );
		await b;
		expect( apply.mock.calls ).toEqual( [ [ [ 20 ] ] ] );
	} );

	it( 'applies the last saved order when the newest save fails', async () => {
		const second = deferred();
		const save = jest
			.fn()
			.mockResolvedValueOnce( { ids: [ 10, 20 ] } )
			.mockReturnValueOnce( second.promise );
		const apply = jest.fn();
		const reorder = createReorderQueue( save, apply );

		const a = reorder( [ 1, 2 ] );
		const b = reorder( [ 2, 1 ] );
		await a;

		expect( apply ).not.toHaveBeenCalled();

		second.reject( new Error( 'Nope' ) );

		await expect( b ).rejects.toThrow( 'Nope' );
		expect( apply.mock.calls ).toEqual( [ [ [ 10, 20 ] ] ] );
	} );
} );
