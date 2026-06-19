<?php
/**
 * Global-namespace WordPress / WooCommerce function doubles for the unit suite.
 *
 * The Lite slice handlers call a small, fixed set of WordPress and WooCommerce
 * functions (logging, hook registration, i18n). The unit suite does not boot
 * WordPress, so this file defines just those functions in the global namespace.
 * Each is guarded by `function_exists()` so the file is inert under a real
 * WordPress.
 *
 * Hook registrations are captured in a global so tests can assert that a
 * `register()` bound the expected hook; tests reset the global in `setUp()`.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'wc_get_logger' ) ) {
	/**
	 * Return the logger double.
	 *
	 * @return WC_Subscriptions_Lite_Test_Logger
	 */
	function wc_get_logger(): WC_Subscriptions_Lite_Test_Logger {
		return new WC_Subscriptions_Lite_Test_Logger();
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Record an action registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted argument count.
	 * @return bool
	 */
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['wc_subscriptions_lite_test_hooks'][] = [
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Record a filter registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted argument count.
	 * @return bool
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['wc_subscriptions_lite_test_hooks'][] = [
			'type'          => 'filter',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/**
	 * Report that no action has fired yet (so engine boot defers schema install).
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	function did_action( string $hook ): int {
		return 0;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Pass-through translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Pass-through escaping translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
