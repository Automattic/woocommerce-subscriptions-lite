/**
 * PlanManager status-change and duplicate-check tests.
 *
 * Renders the real manager, hooks and notices store against a mocked REST
 * client that rejects every PATCH with a refusal. Asserts the snackbar shows
 * the refusal's reasons, and that the duplicate check skips archived plans on
 * save and refuses a restore that would duplicate an active plan. The table
 * and modal are stubs exposing the row actions and a save button.
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
	PlansTable: ( { plans, onEdit, onArchive, onRestore } ) =>
		plans.map( ( plan ) => (
			<div key={ plan.id }>
				<button
					type="button"
					data-plan-id={ plan.id }
					onClick={ () => onEdit( plan ) }
				>
					Edit
				</button>
				<button
					type="button"
					data-plan-id={ plan.id }
					onClick={ () => onArchive( plan ) }
				>
					Archive
				</button>
				<button
					type="button"
					data-plan-id={ plan.id }
					onClick={ () => onRestore( plan ) }
				>
					Restore
				</button>
			</div>
		) ),
} ) );

jest.mock( '../components/plan-modal', () => ( {
	PlanModal: ( { plan, onSave } ) => (
		<button
			type="button"
			onClick={ () =>
				onSave( {
					name: 'Renamed',
					billing_policy: plan.billing_policy,
					pricing_policy: plan.pricing_policy,
				} )
			}
		>
			Save
		</button>
	),
} ) );

const PLAN = { id: 7, name: 'Monthly', status: 'archived' };

const POLICIES = {
	billing_policy: { period: 'month', interval: 1 },
	pricing_policy: { policies: [], one_time_fees: [] },
};

const ARCHIVED_TWIN = {
	id: 7,
	name: 'Monthly',
	status: 'archived',
	...POLICIES,
};

const ACTIVE_TWIN = {
	id: 8,
	name: 'Monthly again',
	status: 'active',
	...POLICIES,
};

const ACTIVE_OTHER = {
	id: 9,
	name: 'Weekly',
	status: 'active',
	...POLICIES,
	billing_policy: { period: 'week', interval: 1 },
};

const RESTORE_REFUSAL =
	'This plan matches an active plan. Archive or change that plan first.';

let listedPlans = [ PLAN ];

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
 * Answer the list request with the listed plans and reject every PATCH.
 *
 * @param {Object} options apiFetch options.
 * @return {Promise} The mocked response.
 */
const respond = ( options ) => {
	if ( options.method === 'PATCH' ) {
		return Promise.reject( REFUSAL );
	}

	return Promise.resolve( {
		json: async () => listedPlans,
		headers: { get: () => '1' },
	} );
};

const getPatches = () =>
	apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => options.method === 'PATCH' );

const getErrorNotices = () =>
	select( noticesStore )
		.getNotices()
		.filter( ( notice ) => notice.status === 'error' )
		.map( ( notice ) => notice.content );

describe( 'PlanManager status changes', () => {
	let container;
	let root;

	const render = async ( plans ) => {
		listedPlans = plans;
		await act( async () => {
			root = createRoot( container );
			root.render( <PlanManager /> );
		} );
	};

	beforeEach( () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockImplementation( respond );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
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

	const click = async ( label, planId = null ) => {
		const button = [ ...container.querySelectorAll( 'button' ) ].find(
			( candidate ) =>
				candidate.textContent === label &&
				( planId === null ||
					candidate.dataset.planId === String( planId ) )
		);

		await act( async () => {
			button.click();
		} );
	};

	it.each( [ 'Archive', 'Restore' ] )(
		'shows every reason when %s is refused',
		async ( label ) => {
			await render( [ PLAN ] );
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

	it( 'refuses to restore a plan whose active twin is loaded', async () => {
		await render( [ ARCHIVED_TWIN, ACTIVE_TWIN ] );
		await click( 'Restore', ARCHIVED_TWIN.id );

		expect( getPatches() ).toEqual( [] );
		expect( getErrorNotices() ).toEqual( [ RESTORE_REFUSAL ] );
	} );

	it( 'sends the restore when no active plan matches', async () => {
		await render( [ ARCHIVED_TWIN, ACTIVE_OTHER ] );
		await click( 'Restore', ARCHIVED_TWIN.id );

		expect( getPatches() ).toEqual( [
			expect.objectContaining( {
				path: expect.stringMatching( /\/7$/ ),
				data: expect.objectContaining( { status: 'active' } ),
			} ),
		] );
		expect( getErrorNotices() ).not.toContain( RESTORE_REFUSAL );
	} );

	it( 'saves an edit to an archived plan whose active twin is loaded', async () => {
		await render( [ ARCHIVED_TWIN, ACTIVE_TWIN ] );
		await click( 'Edit', ARCHIVED_TWIN.id );
		await click( 'Save' );

		expect( getPatches() ).toEqual( [
			expect.objectContaining( {
				path: expect.stringMatching( /\/7$/ ),
				data: expect.objectContaining( { name: 'Renamed' } ),
			} ),
		] );
	} );

	it( 'does not save an edit to an active plan that matches another', async () => {
		await render( [ { ...ARCHIVED_TWIN, status: 'active' }, ACTIVE_TWIN ] );
		await click( 'Edit', ARCHIVED_TWIN.id );
		await click( 'Save' );

		expect( getPatches() ).toEqual( [] );
	} );
} );
