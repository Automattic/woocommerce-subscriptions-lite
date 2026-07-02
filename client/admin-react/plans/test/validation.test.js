/**
 * Duplicate-plan detection tests.
 */

import { findDuplicatePlan, planSignature } from '../utils/validation';

const plan = ( overrides = {} ) => ( {
	id: 1,
	billing_policy: { period: 'month', interval: 1, max_cycles: null },
	pricing_policy: { policies: [], one_time_fees: [] },
	...overrides,
} );

describe( 'planSignature', () => {
	it( 'is stable regardless of value numeric type', () => {
		const a = plan( {
			billing_policy: { period: 'month', interval: '2' },
			pricing_policy: {
				policies: [ { type: 'percentage', value: '10' } ],
			},
		} );
		const b = plan( {
			billing_policy: { period: 'month', interval: 2 },
			pricing_policy: {
				policies: [ { type: 'percentage', value: 10 } ],
			},
		} );
		expect( planSignature( a ) ).toBe( planSignature( b ) );
	} );

	it( 'differs when cadence differs', () => {
		expect(
			planSignature(
				plan( { billing_policy: { period: 'month', interval: 1 } } )
			)
		).not.toBe(
			planSignature(
				plan( { billing_policy: { period: 'week', interval: 1 } } )
			)
		);
	} );
} );

describe( 'findDuplicatePlan', () => {
	const existing = [
		plan( { id: 10 } ),
		plan( {
			id: 11,
			billing_policy: { period: 'year', interval: 1 },
		} ),
	];

	it( 'detects a functional duplicate', () => {
		const candidate = plan( { id: undefined } );
		const result = findDuplicatePlan( candidate, existing );
		expect( result.isDuplicate ).toBe( true );
		expect( result.matchingPlan.id ).toBe( 10 );
	} );

	it( 'ignores the plan being edited', () => {
		const candidate = plan( { id: 10 } );
		const result = findDuplicatePlan( candidate, existing, 10 );
		expect( result.isDuplicate ).toBe( false );
	} );

	it( 'returns no match for a unique plan', () => {
		const candidate = plan( {
			billing_policy: { period: 'day', interval: 3 },
		} );
		const result = findDuplicatePlan( candidate, existing );
		expect( result.isDuplicate ).toBe( false );
		expect( result.matchingPlan ).toBeNull();
	} );
} );
