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
