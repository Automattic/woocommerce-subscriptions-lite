/**
 * Save-error messages for the plan editor.
 *
 * The plans REST route reports refused input as WordPress's `rest_invalid_param`
 * error. Its `data.errors` maps each field (a dot path such as
 * `pricing_policy.policies.0.value`) to that field's translated, field-free
 * messages; `data.params` holds one sentence per request arg. Errors without a
 * field (another extension's refusal) ride in `additional_errors`.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Whether a value is a non-empty string.
 *
 * @param {*} value Candidate.
 * @return {boolean} True for a non-empty string.
 */
const isMessage = ( value ) => typeof value === 'string' && value !== '';

/**
 * One item per field from `data.errors`: the field, then its messages.
 *
 * @param {*} errors The `data.errors` map.
 * @return {string[]} Items, in key order.
 */
function getFieldMessages( errors ) {
	if ( ! errors || typeof errors !== 'object' ) {
		return [];
	}

	return Object.entries( errors ).flatMap( ( [ field, fieldErrors ] ) => {
		const messages = Array.isArray( fieldErrors )
			? fieldErrors
					.map( ( fieldError ) => fieldError?.message )
					.filter( isMessage )
			: [];
		if ( messages.length === 0 ) {
			return [];
		}

		return [
			sprintf(
				/* translators: 1: field path, such as billing_policy.min_cycles; 2: what is wrong with it. */
				__( '%1$s: %2$s', 'woocommerce-subscriptions-lite' ),
				field,
				messages.join( ' ' )
			),
		];
	} );
}

/**
 * The messages to show for a failed plan save or status change: one item per
 * field from `error.data.errors`, else the sentences in `error.data.params`,
 * else the error's own message; then the message of each fieldless
 * `error.additional_errors` entry. The fallback when nothing is left.
 *
 * @param {*}      error    The rejected API request's error.
 * @param {string} fallback Message to show when the error has none.
 * @return {string[]} Messages to show, never empty.
 */
export function getSaveErrorMessages( error, fallback ) {
	let messages = getFieldMessages( error?.data?.errors );

	if ( messages.length === 0 ) {
		const params = error?.data?.params;
		messages =
			params && typeof params === 'object'
				? Object.values( params ).filter( isMessage )
				: [];
	}

	if ( messages.length === 0 && isMessage( error?.message ) ) {
		messages = [ error.message ];
	}

	const additional = Array.isArray( error?.additional_errors )
		? error.additional_errors
				.map( ( additionalError ) => additionalError?.message )
				.filter( isMessage )
		: [];
	messages = [ ...messages, ...additional ];

	return messages.length > 0 ? messages : [ fallback ];
}
