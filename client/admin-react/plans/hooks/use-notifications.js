/**
 * useNotifications - snackbar notifications for the plan manager.
 *
 * Dispatches to the WordPress notices store with the snackbar type; the
 * WooCommerce admin layout renders the snackbar queue on settings screens,
 * matching the released plans UI.
 */

import { useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

const SUCCESS_DISMISS_MS = 2000;
const ERROR_DISMISS_MS = 3000;

/**
 * @return {Object} Notification helpers.
 */
export function useNotifications() {
	const { createNotice, removeNotice } = useDispatch( noticesStore );

	const show = useCallback(
		( status, message, dismissAfter, options ) => {
			const id = `wc-subscriptions-lite-${ status }-${ Date.now() }`;
			createNotice( status, message, {
				id,
				isDismissible: true,
				type: 'snackbar',
				...options,
			} );

			setTimeout( () => {
				removeNotice( id );
			}, dismissAfter );
		},
		[ createNotice, removeNotice ]
	);

	const showSuccess = useCallback(
		( message, options = {} ) =>
			show( 'success', message, SUCCESS_DISMISS_MS, options ),
		[ show ]
	);

	const showError = useCallback(
		( message, options = {} ) =>
			show( 'error', message, ERROR_DISMISS_MS, options ),
		[ show ]
	);

	return { showSuccess, showError };
}
