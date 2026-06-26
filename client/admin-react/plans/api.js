import apiFetch from '@wordpress/api-fetch';
import { config } from './config';

function withQuery( path, query ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			params.set( key, String( value ) );
		}
	} );
	const queryString = params.toString();
	return queryString ? `${ path }?${ queryString }` : path;
}

export async function fetchPlans( query ) {
	const response = await apiFetch( {
		// Use the extension slug from the config by default, but allow for overriding.
		path: withQuery( config.restBase, {
			extension_slug: config.extensionSlug,
			...query,
		} ),
		parse: false,
	} );
	const data = await response.json();

	return {
		data,
		totalItems: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) || 0 ),
	};
}

export function createPlan( payload ) {
	return apiFetch( {
		path: config.restBase,
		method: 'POST',
		data: { extension_slug: config.extensionSlug, ...payload },
	} );
}

export function updatePlan( id, payload ) {
	return apiFetch( {
		path: `${ config.restBase }/${ id }`,
		method: 'PATCH',
		data: { extension_slug: config.extensionSlug, ...payload },
	} );
}

export function reorderPlans( ids, extensionSlug = null ) {
	return apiFetch( {
		path: `${ config.restBase }/reorder`,
		method: 'POST',
		data: { ids, extension_slug: extensionSlug || config.extensionSlug },
	} );
}
