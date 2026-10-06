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
 * and only the newest saved order is applied, once the queue drains, so an older
 * response can never snap the screen back over a newer move. When the newest save
 * fails, the last order the server did save is applied instead. A failed save
 * rejects its own call only; later saves still run.
 *
 * @param {Function} save  Saves ids; resolves to `{ ids }` (the saved order).
 * @param {Function} apply Receives the saved order's ids.
 * @return {Function} `( ids ) => Promise` settling with that save (after the apply, when it drains the queue).
 */
export function createReorderQueue( save, apply ) {
	let last = Promise.resolve();
	let pending = 0;
	let saved = null;

	return ( ids ) => {
		pending++;
		const run = last
			.catch( () => {} )
			.then( () => save( ids ) )
			.then( ( response ) => {
				saved = response.ids;
			} )
			.finally( () => {
				pending--;
				if ( 0 === pending && null !== saved ) {
					const ordered = saved;
					saved = null;
					apply( ordered );
				}
			} );
		last = run;

		return run;
	};
}
