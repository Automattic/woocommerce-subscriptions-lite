/**
 * PlanForm name field.
 *
 * Renders the real registry-driven form in jsdom and checks the name the
 * merchant sees is the name that is saved: prefilled from the stored plan,
 * suggested from the frequency for a new plan, and required.
 */

// React.act is test-only and not re-exported by @wordpress/element; react
// itself is provided by the @wordpress/scripts jest environment.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';
import { createRoot } from '@wordpress/element';

// React 18 requires an explicit opt-in for act() outside react-test-renderer.
global.IS_REACT_ACT_ENVIRONMENT = true;
import { PlanForm } from '../components/plan-form';
import { buildFieldRegistry } from '../fields';
import { normalizeDefinitions } from '../definitions';

jest.mock( '../config', () => ( {
	config: {
		restBase: '/wc/v3/subscriptions-engine/plans',
		extensionSlug: 'woocommerce-subscriptions-lite',
		defaultStatus: 'active',
		currency: {
			code: 'USD',
			symbol: '$',
			position: 'left',
			thousandSeparator: ',',
			decimalSeparator: '.',
			decimals: 2,
		},
	},
} ) );

const storedPlan = {
	id: 9,
	name: 'Lite monthly coffee refill',
	billing_policy: { period: 'month', interval: 1 },
	pricing_policy: {
		policies: [ { type: 'percentage', value: 10, starting_cycle: 2 } ],
		one_time_fees: [],
	},
	status: 'active',
};

describe( 'PlanForm name field', () => {
	let container;
	let root;
	let onSave;

	const definitions = normalizeDefinitions( {} );
	const registry = buildFieldRegistry( { definitions, config: {} } );

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		onSave = jest.fn();
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
	} );

	const renderForm = ( plan = null ) => {
		act( () => {
			root = createRoot( container );
			root.render(
				<PlanForm
					registry={ registry }
					definitions={ definitions }
					plan={ plan }
					onCancel={ () => {} }
					onSave={ onSave }
				/>
			);
		} );
	};

	const nameInput = () =>
		container.querySelector( 'input.components-text-control__input' );
	const intervalInput = () =>
		container.querySelector( 'input[type="number"]' );

	const change = ( input, value ) => {
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, value );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	};

	const submit = () => {
		act( () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );
	};

	it( 'prefills the stored name and saves it after a cadence change', () => {
		renderForm( storedPlan );
		expect( nameInput().value ).toBe( 'Lite monthly coffee refill' );

		change( intervalInput(), '3' );
		submit();

		const payload = onSave.mock.calls[ 0 ][ 0 ];
		expect( payload.name ).toBe( 'Lite monthly coffee refill' );
		expect( payload.billing_policy.interval ).toBe( 3 );
		expect( payload.pricing_policy.policies ).toEqual( [
			{ type: 'percentage', value: 10, starting_cycle: 2 },
		] );
	} );

	it( 'saves a renamed plan', () => {
		renderForm( storedPlan );
		change( nameInput(), 'Coffee club' );
		submit();

		expect( onSave.mock.calls[ 0 ][ 0 ].name ).toBe( 'Coffee club' );
	} );

	it( 'suggests the frequency for a new plan until the merchant names it', () => {
		renderForm();
		expect( nameInput().value ).toBe( '1 month' );

		change( intervalInput(), '2' );
		expect( nameInput().value ).toBe( '2 months' );

		change( nameInput(), 'Bi-monthly box' );
		change( intervalInput(), '3' );
		expect( nameInput().value ).toBe( 'Bi-monthly box' );

		submit();
		expect( onSave.mock.calls[ 0 ][ 0 ].name ).toBe( 'Bi-monthly box' );
	} );

	it( 'requires a name and does not save an emptied one', () => {
		renderForm( storedPlan );
		change( nameInput(), '  ' );
		submit();

		expect( onSave ).not.toHaveBeenCalled();
		const input = nameInput();
		const error = container.querySelector(
			'.wc-subscriptions-lite-plans__error'
		);
		expect( error.textContent ).toBe( 'Name is required.' );
		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( error.id );
	} );
} );
