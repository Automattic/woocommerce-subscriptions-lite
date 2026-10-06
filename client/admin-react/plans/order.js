/**
 * Lite plan display order.
 *
 * Mirrors the server's PlanOrder::sort(): plans whose id is in the saved order
 * come first, in that order; the rest follow by id ascending. Ids in the saved
 * order with no matching plan are ignored.
 *
 * @param {Array<Object>} plans Plans with numeric `id`.
 * @param {Array<number>} order Saved plan ids in display order.
 * @return {Array<Object>} A new, sorted array; the input is not mutated.
 */
export function sortByPlanOrder( plans, order ) {
	const position = new Map();
	( Array.isArray( order ) ? order : [] ).forEach( ( id, index ) => {
		if ( ! position.has( id ) ) {
			position.set( id, index );
		}
	} );

	return [ ...plans ].sort( ( a, b ) => {
		const aListed = position.has( a.id );
		const bListed = position.has( b.id );

		if ( aListed && bListed ) {
			return position.get( a.id ) - position.get( b.id );
		}
		if ( aListed !== bListed ) {
			return aListed ? -1 : 1;
		}

		return a.id - b.id;
	} );
}

/**
 * Serialize plan order saves: each save starts after the previous one settles,
 * and each saved order is applied in turn, so overlapping moves cannot leave the
 * screen on an older order than the one stored. A failed save rejects its own
 * call only; later saves still run.
 *
 * @param {Function} save  Saves ids; resolves to `{ ids }` (the saved order).
 * @param {Function} apply Receives each saved order's ids.
 * @return {Function} `( ids ) => Promise` resolving once that save is applied.
 */
export function createReorderQueue( save, apply ) {
	let last = Promise.resolve();

	return ( ids ) => {
		const run = last
			.catch( () => {} )
			.then( () => save( ids ) )
			.then( ( response ) => apply( response.ids ) );
		last = run;

		return run;
	};
}
