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

	const renderForm = ( plan = null, fields = registry ) => {
		act( () => {
			root = createRoot( container );
			root.render(
				<PlanForm
					registry={ fields }
					definitions={ definitions }
					plan={ plan }
					onCancel={ () => {} }
					onSave={ onSave }
				/>
			);
		} );
	};

	// Find an input through its visible label, and its error through the
	// input's aria-describedby.
	const inputByLabel = ( text ) => {
		const label = [ ...container.querySelectorAll( 'label' ) ].find(
			( element ) => element.textContent === text
		);
		return label ? document.getElementById( label.htmlFor ) : null;
	};
	const nameInput = () => inputByLabel( 'Name' );
	const intervalInput = () => inputByLabel( 'Frequency' );
	const errorFor = ( input ) => {
		const id = input.getAttribute( 'aria-describedby' );
		return id ? document.getElementById( id ) : null;
	};
	const saveButton = () =>
		[ ...container.querySelectorAll( 'button' ) ].find(
			( button ) => button.textContent === 'Save'
		);
	const blur = ( input ) => {
		act( () => {
			input.dispatchEvent(
				new window.FocusEvent( 'focusout', { bubbles: true } )
			);
		} );
	};

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

	it( 'suggests from the saved billing policy, not the frequency form keys', () => {
		// A frequency field with its own form keys that writes the same
		// billing_policy shape.
		const customFrequency = {
			id: 'frequency',
			default: { every: 2, unit: 'week' },
			Edit: ( { data, onChange } ) => (
				<>
					<label htmlFor="custom-every">Every</label>
					<input
						id="custom-every"
						type="number"
						value={ data.every }
						onChange={ ( event ) =>
							onChange( { every: Number( event.target.value ) } )
						}
					/>
				</>
			),
			toPayload: ( formData, payload ) => ( {
				...payload,
				billing_policy: {
					...( payload.billing_policy || {} ),
					interval: formData.every,
					period: formData.unit,
				},
			} ),
		};
		renderForm(
			null,
			registry.map( ( field ) =>
				field.id === 'frequency' ? customFrequency : field
			)
		);
		expect( nameInput().value ).toBe( '2 weeks' );

		change( inputByLabel( 'Every' ), '5' );
		expect( nameInput().value ).toBe( '5 weeks' );

		submit();
		expect( onSave.mock.calls[ 0 ][ 0 ].name ).toBe( '5 weeks' );
	} );

	it( 'marks the name as required for assistive technology', () => {
		renderForm();
		expect( nameInput().getAttribute( 'aria-required' ) ).toBe( 'true' );
		// Not native `required`: the field error, not the browser's, reports it.
		expect( nameInput().required ).toBe( false );
	} );

	it( 'shows the error on blur and disables Save for an emptied name', () => {
		renderForm( storedPlan );
		change( nameInput(), '' );
		blur( nameInput() );

		const input = nameInput();
		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( errorFor( input ).textContent ).toBe( 'Name is required.' );
		expect( saveButton().disabled ).toBe( true );

		// Any edit clears the error until the next blur or submit.
		change( nameInput(), 'C' );
		expect( errorFor( nameInput() ) ).toBeNull();
		expect( saveButton().disabled ).toBe( false );
	} );

	it( 'raises no error when an untouched new-plan name is blurred', () => {
		renderForm();
		blur( nameInput() );

		expect( nameInput().getAttribute( 'aria-invalid' ) ).toBeNull();
		expect( errorFor( nameInput() ) ).toBeNull();
		expect( saveButton().disabled ).toBe( false );

		submit();
		expect( onSave.mock.calls[ 0 ][ 0 ].name ).toBe( '1 month' );
	} );

	it( 'requires a name and does not save an emptied one', () => {
		renderForm( storedPlan );
		change( nameInput(), '  ' );
		submit();

		expect( onSave ).not.toHaveBeenCalled();
		const input = nameInput();
		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( errorFor( input ).textContent ).toBe( 'Name is required.' );
	} );
} );
