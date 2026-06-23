<?php
/**
 * PHPUnit bootstrap for WooCommerce Subscriptions Lite.
 *
 * Scaffold stage: defines ABSPATH and loads the Composer autoloader so unit
 * tests can resolve package classes without spinning up WordPress. WordPress /
 * WooCommerce function stubs are added here as the first feature modules land
 * and need them.
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

// WordPress / WooCommerce doubles for the unit suite. The slice handlers are
// thin drivers over the engine's public surface; these let them run without a
// booted WordPress. Each definition is guarded so the files are inert under a
// real WordPress (e.g. a future integration suite). The logger class loads
// before the function stubs because `wc_get_logger()` returns it.
require_once __DIR__ . '/Stubs/class-woocommerce-subscriptions-lite-test-logger.php';
require_once __DIR__ . '/Stubs/wp-function-stubs.php';
require_once __DIR__ . '/Stubs/class-wc-order.php';
require_once __DIR__ . '/Stubs/class-wc-order-item-product.php';
