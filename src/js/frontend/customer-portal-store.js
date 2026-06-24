/**
 * Customer-portal Interactivity API store.
 *
 * The shared, namespaced client surface for the My Account customer portal.
 * State is seeded server-side via `wp_interactivity_state()`; this module adds
 * the lifecycle actions (open/close the cancel modal, submit cancel / hold /
 * reactivate) and the success-refresh + inline-retry UX.
 *
 * The store namespace is part of the public extension contract: a premium
 * overlay imports this same store to add actions without forking the renderer.
 *
 * Transport: lifecycle actions POST to the engine's authenticated `wc/v3` REST
 * routes with the `X-WP-Nonce` cookie-auth header. There is NO Store API path.
 * Until the engine routes exist, the store runs in MOCK MODE (see `isMockMode`):
 * the request is short-circuited to a resolved success (or a forced failure for
 * the failure-path demo) so the full open -> confirm -> submit -> refresh and
 * the failure -> retry flows are exercisable with no engine dependency. The
 * single swap point is `performAction()`: once the real routes land, mock mode
 * is off by default and the fetch path runs.
 *
 * Translatable copy is seeded into `state.i18n` from PHP rather than called via
 * `@wordpress/i18n` in the module, so the strings stay in the text domain and
 * the module has no runtime i18n dependency.
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

const STORE_NAMESPACE = 'woocommerce-subscriptions-lite/customer-portal';

/**
 * Mock-mode sentinel. When the localized REST base is empty or this sentinel,
 * the store does not hit the network - it resolves the action locally so the
 * UX can be exercised end-to-end without the engine. Swap point: real wiring
 * passes a concrete `wc/v3` base, which turns mock mode off.
 *
 * @param {Object} currentState The store state.
 * @return {boolean} Whether to run the mock transport.
 */
function isMockMode( currentState ) {
	return ! currentState.restBase || currentState.restBase === 'mock';
}

/**
 * Run the configured transport for a lifecycle action.
 *
 * Real mode: POST to `{restBase}{contractId}/{action}` with the REST nonce.
 * Mock mode: resolve success, unless the per-action context opts into a forced
 * failure (`context.forceFailure`) so the inline-retry path is demonstrable.
 *
 * @param {Object} currentState The store state.
 * @param {string} action       The action segment ('cancel' | 'hold' | 'reactivate').
 * @param {Object} body         The JSON request body.
 * @param {Object} context      The element interactivity context (for mock flags).
 * @return {Promise<void>} Resolves on success; rejects with an Error on failure.
 */
function performAction( currentState, action, body, context ) {
	if ( isMockMode( currentState ) ) {
		return new Promise( ( resolve, reject ) => {
			// Defer so the "submitting" state is observable before resolution.
			setTimeout( () => {
				if ( context && context.forceFailure ) {
					reject( new Error( '' ) );
					return;
				}
				resolve();
			}, 300 );
		} );
	}

	return fetch(
		`${ currentState.restBase }${ currentState.contractId }/${ action }`,
		{
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': currentState.nonce,
			},
			body: JSON.stringify( body ),
		}
	).then( ( response ) => {
		if ( ! response.ok ) {
			return response.json().then(
				( payload ) =>
					Promise.reject(
						new Error(
							payload && payload.message ? payload.message : ''
						)
					),
				() => Promise.reject( new Error( '' ) )
			);
		}
	} );
}

/**
 * Reload the current page so the refreshed server-rendered state and any queued
 * success notice render. The simple, robust refresh path for slice 1; a later
 * slice can re-fetch the contract detail and re-render via state in place.
 */
function refresh() {
	window.location.reload();
}

const { state } = store( STORE_NAMESPACE, {
	actions: {
		/**
		 * Open the cancel confirmation dialog. Remembers the trigger so focus
		 * returns to it on close.
		 */
		openCancelModal() {
			const dialog = document.querySelector(
				'dialog.wc-subscriptions-lite-cancel-modal'
			);
			if ( ! dialog || typeof dialog.showModal !== 'function' ) {
				return;
			}
			// Remember the trigger (the clicked button) so focus returns to it
			// when the dialog closes.
			const element = getElement();
			dialog.__triggerEl = ( element && element.ref ) || null;
			state.error = '';
			state.modalOpen = true;
			dialog.showModal();
		},

		/**
		 * Close the cancel dialog and return focus to the trigger.
		 */
		closeCancelModal() {
			const dialog = document.querySelector(
				'dialog.wc-subscriptions-lite-cancel-modal'
			);
			state.modalOpen = false;
			if ( ! dialog ) {
				return;
			}
			if ( typeof dialog.close === 'function' && dialog.open ) {
				dialog.close();
			}
			const trigger = dialog.__triggerEl;
			dialog.__triggerEl = null;
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus();
			}
		},

		/**
		 * Submit the cancel. Forwards the server-resolved `atPeriodEnd` mode.
		 */
		*submitCancel() {
			const context = getContext();
			yield runLifecycle(
				'cancel',
				{ at_period_end: state.atPeriodEnd },
				context
			);
		},

		/**
		 * Submit the hold/pause action.
		 */
		*submitHold() {
			const context = getContext();
			yield runLifecycle( 'hold', {}, context );
		},

		/**
		 * Submit the reactivate action.
		 */
		*submitReactivate() {
			const context = getContext();
			yield runLifecycle( 'reactivate', {}, context );
		},
	},
	callbacks: {
		/**
		 * Mirror native dialog dismissal (ESC / backdrop) back into state so the
		 * store and the DOM agree, and return focus to the trigger.
		 */
		onDialogClose() {
			state.modalOpen = false;
			const dialog = document.querySelector(
				'dialog.wc-subscriptions-lite-cancel-modal'
			);
			if ( ! dialog ) {
				return;
			}
			const trigger = dialog.__triggerEl;
			dialog.__triggerEl = null;
			if ( trigger && typeof trigger.focus === 'function' ) {
				trigger.focus();
			}
		},
	},
} );

/**
 * Shared submit path for the three lifecycle actions: guards against a
 * double-submit, runs the transport, refreshes on success, and surfaces an
 * inline retryable error on failure.
 *
 * @param {string} action  The action segment.
 * @param {Object} body    The JSON request body.
 * @param {Object} context The element interactivity context.
 * @return {Promise<void>} Resolves when the submit completes (success or handled failure).
 */
function runLifecycle( action, body, context ) {
	if ( state.submitting ) {
		return Promise.resolve();
	}
	state.submitting = true;
	state.error = '';

	return performAction( state, action, body, context ).then(
		() => {
			refresh();
		},
		( err ) => {
			state.submitting = false;
			state.error = formatError( action, err && err.message );
		}
	);
}

/**
 * Compose the customer-facing error message for a failed action from the
 * server-seeded copy, wrapping any server-supplied detail.
 *
 * @param {string} action The action segment.
 * @param {string} detail Server-supplied detail (may be empty).
 * @return {string} The error message.
 */
function formatError( action, detail ) {
	const copy = state.i18n || {};
	const base =
		( action === 'reactivate' && copy.reactivateError ) ||
		( action === 'hold' && copy.holdError ) ||
		copy.cancelError ||
		'';

	const suffix = copy.errorSuffix || '';
	const message = detail ? `${ base } ${ detail }` : base;
	return suffix ? `${ message } ${ suffix }` : message;
}
