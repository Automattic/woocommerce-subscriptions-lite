/**
 * Display formatter tests.
 */

import {
	EMPTY_VALUE,
	formatCurrency,
	formatDiscount,
	formatExpiration,
	formatFrequency,
} from '../format';
import { normalizeDefinitions } from '../definitions';

const USD = {
	code: 'USD',
	symbol: '$',
	position: 'left',
	thousandSeparator: ',',
	decimalSeparator: '.',
	decimals: 2,
};

describe( 'EMPTY_VALUE', () => {
	it( 'is the em dash character', () => {
		expect( EMPTY_VALUE ).toBe( '\u2014' );
	} );
} );

describe( 'formatCurrency', () => {
	it( 'formats with the default (bootstrap fallback) currency', () => {
		expect( formatCurrency( 10 ) ).toBe( '$10.00' );
	} );

	it( 'applies thousand and decimal separators', () => {
		expect( formatCurrency( 1234.5, USD ) ).toBe( '$1,234.50' );
		expect(
			formatCurrency( 1234.5, {
				...USD,
				symbol: '€',
				position: 'right_space',
				thousandSeparator: '.',
				decimalSeparator: ',',
			} )
		).toBe( '1.234,50 €' );
	} );

	it( 'respects the decimals count', () => {
		expect( formatCurrency( 10, { ...USD, decimals: 0 } ) ).toBe( '$10' );
		expect( formatCurrency( 10, { ...USD, decimals: 3 } ) ).toBe(
			'$10.000'
		);
	} );

	it.each( [
		[ 'left', '$10.00' ],
		[ 'right', '10.00$' ],
		[ 'left_space', '$ 10.00' ],
		[ 'right_space', '10.00 $' ],
	] )( 'places the symbol for position %s', ( position, expected ) => {
		expect( formatCurrency( 10, { ...USD, position } ) ).toBe( expected );
	} );
} );

describe( 'formatDiscount', () => {
	const plan = ( policy ) => ( {
		pricing_policy: { policies: policy ? [ policy ] : [] },
	} );

	it( 'renders a percentage discount', () => {
		expect(
			formatDiscount( plan( { type: 'percentage', value: 14 } ), USD )
		).toBe( '14%' );
	} );

	it( 'renders a fixed amount discount as currency', () => {
		expect(
			formatDiscount( plan( { type: 'fixed_amount', value: 10 } ), USD )
		).toBe( '$10.00' );
	} );

	it( 'renders a fixed price as currency', () => {
		expect(
			formatDiscount( plan( { type: 'price', value: 5.5 } ), USD )
		).toBe( '$5.50' );
	} );

	it( 'appends the scope suffix', () => {
		expect(
			formatDiscount(
				plan( { type: 'percentage', value: 14, duration_cycles: 1 } ),
				USD
			)
		).toBe( '14% (first cycle)' );
		expect(
			formatDiscount(
				plan( { type: 'percentage', value: 14, duration_cycles: 3 } ),
				USD
			)
		).toBe( '14% (3 cycles)' );
	} );

	it( 'renders the placeholder when there is no policy', () => {
		expect( formatDiscount( plan( null ), USD ) ).toBe( EMPTY_VALUE );
		expect( formatDiscount( {}, USD ) ).toBe( EMPTY_VALUE );
	} );
} );

describe( 'formatExpiration', () => {
	it( 'renders a payment count', () => {
		expect(
			formatExpiration( { billing_policy: { max_cycles: 5 } } )
		).toBe( '5 payments' );
	} );

	it( 'uses the singular form for one payment', () => {
		expect(
			formatExpiration( { billing_policy: { max_cycles: 1 } } )
		).toBe( '1 payment' );
	} );

	it( 'renders the placeholder for open-ended plans', () => {
		expect(
			formatExpiration( { billing_policy: { max_cycles: null } } )
		).toBe( EMPTY_VALUE );
		expect( formatExpiration( {} ) ).toBe( EMPTY_VALUE );
	} );
} );

describe( 'formatFrequency', () => {
	const definitions = normalizeDefinitions( {} );

	it( 'renders the bare unit for a single-interval cadence', () => {
		expect(
			formatFrequency(
				{ billing_policy: { interval: 1, period: 'month' } },
				definitions
			)
		).toBe( 'month' );
	} );

	it( 'renders interval and plural unit for multi-interval cadences', () => {
		expect(
			formatFrequency(
				{ billing_policy: { interval: 3, period: 'week' } },
				definitions
			)
		).toBe( '3 weeks' );
	} );
} );
