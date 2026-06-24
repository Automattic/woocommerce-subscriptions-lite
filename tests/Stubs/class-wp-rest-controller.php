<?php
/**
 * Minimal WP_REST_Controller double for the unit suite.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	/**
	 * Minimal stand-in for WordPress's REST controller class.
	 */
	class WP_REST_Controller {
	}
}
