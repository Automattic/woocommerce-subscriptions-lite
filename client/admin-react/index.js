/**
 * Admin entry point for WooCommerce Subscriptions Lite.
 */

import { createRoot } from '@wordpress/element';
import { PlansApp } from './plans/app';
import './style.scss';

const mount = document.getElementById( 'wc-subscriptions-lite-plan-manager' );

if ( mount ) {
	createRoot( mount ).render( <PlansApp /> );
}
