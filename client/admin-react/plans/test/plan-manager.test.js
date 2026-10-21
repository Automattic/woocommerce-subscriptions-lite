/**
 * PlanManager status-change error tests.
 *
 * Renders the real manager, hooks and notices store against a mocked REST
 * client, rejects an archive or restore request with a refusal, and asserts
 * the snackbar shows the refusal's reasons. The table is a stub exposing the
 * row actions.
 */

// React.act is test-only and not re-exported by @wordpress/element; react
// itself is provided by the @wordpress/scripts jest environment.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { createRoot } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { PlanManager } from '../components/plan-manager';

// React 18 requires an explicit opt-in for act() outside react-test-renderer.
global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../components/plans-table', () => ( {
	PlansTable: ( { plans, onArchive, onRestore } ) =>
		plans.map( ( plan ) => (
			<div key={ plan.id }>
				<button type="button" onClick={ () => onArchive( plan ) }>
					Archive
				</button>
				<button type="button" onClick={ () => onRestore( plan ) }>
					Restore
				</button>
			</div>
		) ),
} ) );

const PLAN = { id: 7, name: 'Monthly', status: 'archived' };

const REFUSAL = {
	code: 'rest_invalid_param',
	message: 'Invalid parameter(s): billing_policy',
	data: {
		status: 400,
		params: {
			billing_policy:
				'billing_policy.period: Must be one of day, week, month, and year. billing_policy.interval: Must be 1 or greater.',
		},
		errors: {
			'billing_policy.period': [
				{
					code: 'not_in_enum',
					message: 'Must be one of day, week, month, and year.',
					params: { allowed: [ 'day', 'week', 'month', 'year' ] },
				},
			],
			'billing_policy.interval': [
				{
					code: 'out_of_bounds',
					message: 'Must be 1 or greater.',
					params: { min: 1 },
				},
			],
		},
	},
};

/**
 * Answer the list request with one plan and reject every status change.
 *
 * @param {Object} options apiFetch options.
 * @return {Promise} The mocked response.
 */
const respond = ( options ) => {
	if ( options.method === 'PATCH' ) {
		return Promise.reject( REFUSAL );
	}

	return Promise.resolve( {
		json: async () => [ PLAN ],
		headers: { get: () => '1' },
	} );
};

const getErrorNotices = () =>
	select( noticesStore )
		.getNotices()
		.filter( ( notice ) => notice.status === 'error' )
		.map( ( notice ) => notice.content );

describe( 'PlanManager status changes', () => {
	let container;
	let root;

	beforeEach( async () => {
		jest.useFakeTimers();
		apiFetch.mockImplementation( respond );
		container = document.createElement( 'div' );
		document.body.appendChild( container );

		await act( async () => {
			root = createRoot( container );
			root.render( <PlanManager /> );
		} );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		select( noticesStore )
			.getNotices()
			.forEach( ( notice ) =>
				dispatch( noticesStore ).removeNotice( notice.id )
			);
		jest.useRealTimers();
	} );

	const click = async ( label ) => {
		const button = [ ...container.querySelectorAll( 'button' ) ].find(
			( candidate ) => candidate.textContent === label
		);

		await act( async () => {
			button.click();
		} );
	};

	it.each( [ 'Archive', 'Restore' ] )(
		'shows every reason when %s is refused',
		async ( label ) => {
			await click( label );

			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'PATCH',
					data: expect.objectContaining( {
						status: label === 'Archive' ? 'archived' : 'active',
					} ),
				} )
			);
			expect( getErrorNotices() ).toEqual( [
				'billing_policy.period: Must be one of day, week, month, and year. billing_policy.interval: Must be 1 or greater.',
			] );
		}
	);
} );
