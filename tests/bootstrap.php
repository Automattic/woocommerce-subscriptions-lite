<?php
/**
 * PHPUnit bootstrap for WooCommerce Subscriptions Lite.
 *
 * Scaffold stage: defines the plugin constants the package expects and loads
 * the Composer autoloader so unit tests can resolve package classes without
 * spinning up WordPress. WordPress / WooCommerce function stubs are added
 * here as the first feature modules land and need them.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'WC_SUBSCRIPTIONS_LITE_DIR' ) ) {
	define( 'WC_SUBSCRIPTIONS_LITE_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WC_SUBSCRIPTIONS_LITE_URL' ) ) {
	define( 'WC_SUBSCRIPTIONS_LITE_URL', 'https://example.test/wp-content/plugins/woocommerce-subscriptions-lite/' );
}
if ( ! defined( 'WC_SUBSCRIPTIONS_LITE_VERSION' ) ) {
	define( 'WC_SUBSCRIPTIONS_LITE_VERSION', '0.0.0-test' );
}

$wc_subs_lite_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $wc_subs_lite_autoload ) ) {
	require_once $wc_subs_lite_autoload;
}
