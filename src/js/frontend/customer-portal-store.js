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
 *
 * Translatable copy is seeded into `state.i18n` from PHP rather than called via
 * `@wordpress/i18n` in the module, so the strings stay in the text domain and
 * the module has no runtime i18n dependency.
 */

import { store, getElement } from '@wordpress/interactivity';

// Import the portal stylesheet so @wordpress/scripts compiles the SCSS, adds
// vendor prefixes, and emits the stylesheet (style-customer-portal.css) plus
// its RTL variant into build/modules/. The portal asset loader enqueues the
// compiled CSS; the JS module itself carries no runtime style dependency.
import './style.scss';

const STORE_NAMESPACE = 'woocommerce-subscriptions-lite/customer-portal';

/**
 * POST a lifecycle action to `{restBase}{contractId}/{action}` with the REST nonce.
 *
 * @param {Object} currentState The store state.
 * @param {string} action       The action segment ('cancel' | 'hold' | 'reactivate').
 * @param {Object} body         The JSON request body.
 * @return {Promise<void>} Resolves on success; rejects with an Error on failure.
 */
function performAction( currentState, action, body ) {
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
			yield runLifecycle( 'cancel', {
				at_period_end: state.atPeriodEnd,
			} );
		},

		/**
		 * Submit the hold/pause action.
		 */
		*submitHold() {
			yield runLifecycle( 'hold', {} );
		},

		/**
		 * Submit the reactivate action.
		 */
		*submitReactivate() {
			yield runLifecycle( 'reactivate', {} );
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
 * @param {string} action The action segment.
 * @param {Object} body   The JSON request body.
 * @return {Promise<void>} Resolves when the submit completes (success or handled failure).
 */
function runLifecycle( action, body ) {
	if ( state.submitting ) {
		return Promise.resolve();
	}
	// The cancel action surfaces failures inside its modal (`state.error`); the
	// in-page Pause / Reactivate buttons surface them in the detail-actions
	// region (`state.actionError`). Keeping the two fields separate stops the
	// two `role="alert"` regions from cross-rendering the same message.
	const errorField = action === 'cancel' ? 'error' : 'actionError';
	state.submitting = true;
	state[ errorField ] = '';

	return performAction( state, action, body ).then(
		() => {
			refresh();
		},
		( err ) => {
			state.submitting = false;
			state[ errorField ] = formatError( action, err && err.message );
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
	let base = '';
	switch ( action ) {
		case 'hold':
			base = copy.holdError || '';
			break;
		case 'reactivate':
			base = copy.reactivateError || '';
			break;
		default:
			base = copy.cancelError || '';
			break;
	}

	const suffix = copy.errorSuffix || '';
	const message = detail ? `${ base } ${ detail }` : base;
	return suffix ? `${ message } ${ suffix }` : message;
}
