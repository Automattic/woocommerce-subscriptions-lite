/**
 * useNotifications - minimal success/error notice state for the plan manager.
 */

import { useCallback, useState } from '@wordpress/element';

/**
 * @return {Object} Notice state and helpers.
 */
export function useNotifications() {
	const [ notice, setNotice ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const showSuccess = useCallback( ( message ) => {
		setError( '' );
		setNotice( message );
	}, [] );

	const showError = useCallback( ( message ) => {
		setNotice( '' );
		setError( message );
	}, [] );

	const clear = useCallback( () => {
		setNotice( '' );
		setError( '' );
	}, [] );

	return {
		notice,
		error,
		setNotice,
		setError,
		showSuccess,
		showError,
		clear,
	};
}
