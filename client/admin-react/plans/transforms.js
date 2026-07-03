/**
 * Registry-driven transforms between the flat form model and the engine plan
 * shape. Every function loops the field registry so injected fields round-trip
 * without any change here; engine data no field owns (e.g. one_time_fees) is
 * preserved on edit by seeding the payload from the existing plan.
 */

import { config } from './config';

/**
 * Build the default form data by merging each descriptor's defaults.
 *
 * @param {Array<Object>} registry Field descriptors.
 * @return {Object} Default form data.
 */
export function makeDefaultFormData( registry ) {
	return registry.reduce(
		( acc, field ) => ( { ...acc, ...( field.default || {} ) } ),
		{}
	);
}

/**
 * Map an engine plan to flat form data.
 *
 * @param {Array<Object>} registry Field descriptors.
 * @param {Object}        plan     Engine plan object.
 * @return {Object} Flat form data.
 */
export function planToFormData( registry, plan = {} ) {
	return registry.reduce(
		( acc, field ) =>
			field.fromPlan ? { ...acc, ...field.fromPlan( plan ) } : acc,
		makeDefaultFormData( registry )
	);
}

/**
 * Map flat form data to an engine REST payload.
 *
 * @param {Array<Object>} registry Field descriptors.
 * @param {Object}        formData Flat form data.
 * @param {Object|null}   plan     Existing plan when editing, else null.
 * @return {Object} Engine REST payload.
 */
export function formDataToPayload( registry, formData, plan = null ) {
	const base = { extension_slug: config.extensionSlug };
	if ( ! plan ) {
		base.status = config.defaultStatus;
	}

	return registry.reduce(
		( payload, field ) =>
			field.toPayload
				? field.toPayload( formData, payload, plan )
				: payload,
		base
	);
}

/**
 * Collect validation errors from every descriptor.
 *
 * @param {Array<Object>} registry Field descriptors.
 * @param {Object}        formData Flat form data.
 * @return {Object} Errors keyed by field key.
 */
export function formErrors( registry, formData ) {
	return registry.reduce( ( acc, field ) => {
		const errors = field.validate ? field.validate( formData ) : null;
		return errors ? { ...acc, ...errors } : acc;
	}, {} );
}

/**
 * Translate a DataViews view into engine list query params.
 *
 * @param {Object} view DataViews view state.
 * @return {Object} Query params for the engine plans endpoint.
 */
export function viewToQuery( view ) {
	const statusFilter = view.filters?.find(
		( filter ) => filter.field === 'status'
	);
	const statusValue = Array.isArray( statusFilter?.value )
		? statusFilter.value[ 0 ]
		: statusFilter?.value;

	return {
		page: view.page || 1,
		per_page: view.perPage || 20,
		search: view.search || '',
		status: statusValue || '',
		orderby: view.sort?.field || 'sort_order',
		order: view.sort?.direction || 'asc',
	};
}
