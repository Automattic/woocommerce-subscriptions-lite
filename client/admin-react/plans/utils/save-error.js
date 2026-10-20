/**
 * Save-error messages for the plan editor.
 *
 * The plans REST route reports refused input as WordPress's `rest_invalid_param`
 * error, with one translated message per request arg in `data.params`.
 */

/**
 * The messages to show for a failed plan save: each per-field message from
 * `error.data.params`, else the error's own message, else the fallback.
 *
 * @param {*}      error    The rejected API request's error.
 * @param {string} fallback Message to show when the error has none.
 * @return {string[]} Messages to show, never empty.
 */
export function getSaveErrorMessages( error, fallback ) {
	const params = error?.data?.params;
	if ( params && typeof params === 'object' ) {
		const messages = Object.values( params ).filter(
			( message ) => typeof message === 'string' && message !== ''
		);
		if ( messages.length > 0 ) {
			return messages;
		}
	}

	if ( typeof error?.message === 'string' && error.message !== '' ) {
		return [ error.message ];
	}

	return [ fallback ];
}
