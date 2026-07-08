/**
 * PlanForm validation-error rendering.
 *
 * Renders the real registry-driven form in jsdom, submits invalid data, and
 * asserts the error message renders and is associated with its input via
 * aria-describedby / aria-invalid.
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

describe( 'PlanForm validation errors', () => {
	let container;
	let root;

	const registry = buildFieldRegistry( {
		definitions: normalizeDefinitions( {} ),
		config: {},
	} );

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
	} );

	const renderForm = () => {
		act( () => {
			root = createRoot( container );
			root.render(
				<PlanForm
					registry={ registry }
					definitions={ normalizeDefinitions( {} ) }
					plan={ null }
					onCancel={ () => {} }
					onSave={ () => {} }
				/>
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

	it( 'renders the total-payments error, associated with the input', () => {
		renderForm();

		const checkbox = container.querySelector( 'input[type="checkbox"]' );
		act( () => {
			checkbox.click();
		} );

		// Total payments defaults to 1 when the toggle is enabled.
		const paymentsInput = container.querySelector(
			'.wc-subscriptions-lite-plans__total-payments input[type="number"]'
		);
		expect( paymentsInput ).not.toBeNull();

		change( paymentsInput, '0' );
		submit();

		const error = container.querySelector(
			'.wc-subscriptions-lite-plans__total-payments .wc-subscriptions-lite-plans__error'
		);
		expect( error ).not.toBeNull();
		expect( error.textContent ).toBe(
			'Total payments must be at least 1.'
		);

		const input = container.querySelector(
			'.wc-subscriptions-lite-plans__total-payments input[type="number"]'
		);
		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		const describedby = input.getAttribute( 'aria-describedby' ) || '';
		expect( describedby ).toContain( error.id );
	} );

	it( 'renders the discount error on invalid percentage', () => {
		renderForm();

		const discountInput = container.querySelector(
			'.wc-subscriptions-lite-plans__field--discount input[type="number"], input[aria-label^="Discount"]'
		);
		expect( discountInput ).not.toBeNull();

		change( discountInput, '200' );
		submit();

		const errors = [
			...container.querySelectorAll(
				'.wc-subscriptions-lite-plans__error'
			),
		].map( ( el ) => el.textContent );
		expect( errors ).toContain( 'Percentage discount cannot exceed 100%.' );
		expect( discountInput.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
	} );
	it( 'renders the discount error on blur, before any submit', () => {
		renderForm();

		const discountInput = container.querySelector(
			'input[aria-label^="Discount"]'
		);
		change( discountInput, '-12' );
		act( () => {
			discountInput.dispatchEvent(
				new window.FocusEvent( 'focusout', { bubbles: true } )
			);
		} );

		const errors = [
			...container.querySelectorAll(
				'.wc-subscriptions-lite-plans__error'
			),
		].map( ( el ) => el.textContent );
		expect( errors ).toContain( 'Discount cannot be negative.' );
		expect( discountInput.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
	} );
} );
