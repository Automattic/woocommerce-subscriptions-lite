<?php
/**
 * PHPUnit bootstrap for the customer-portal integration suite.
 *
 * The integration suite exercises the DEFAULT, non-mocked portal wiring end to
 * end - the real {@see \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\Endpoints}
 * render path through the real provider resolver, fixture provider, view-model,
 * and the actual template files - asserting the seeded iAPI state and rendered
 * markup. This is the M0 learning made concrete: unit tests that mock the seams
 * miss wiring bugs, so this suite wires the real components together.
 *
 * It does not boot a full WordPress; it loads the Composer autoloader plus the
 * unit doubles AND a richer set of render/template helper doubles
 * (`wc_get_template`, `wp_interactivity_state`, escaping, endpoint URLs) so the
 * real render path runs in-process and its output can be asserted.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$wc_subs_lite_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $wc_subs_lite_autoload ) ) {
	require_once $wc_subs_lite_autoload;
}

require_once __DIR__ . '/Stubs/class-woocommerce-subscriptions-lite-test-logger.php';
require_once __DIR__ . '/Stubs/wp-function-stubs.php';
require_once __DIR__ . '/Stubs/wp-render-stubs.php';
require_once __DIR__ . '/Stubs/class-wc-order.php';
require_once __DIR__ . '/Stubs/class-wc-order-item-product.php';
