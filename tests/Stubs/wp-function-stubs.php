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
	 * @return WooCommerce_Subscriptions_Lite_Test_Logger
	 */
	function wc_get_logger(): WooCommerce_Subscriptions_Lite_Test_Logger {
		return new WooCommerce_Subscriptions_Lite_Test_Logger();
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
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'][] = [
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
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'][] = [
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

if ( ! function_exists( 'plugin_dir_url' ) ) {
	/**
	 * Return a stable fake plugin URL.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/wp-content/plugins/woocommerce-subscriptions-lite/';
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Record a submenu page registration.
	 *
	 * @param string   $parent_slug Parent slug.
	 * @param string   $page_title  Page title.
	 * @param string   $menu_title  Menu title.
	 * @param string   $capability  Capability.
	 * @param string   $menu_slug   Menu slug.
	 * @param callable $callback    Render callback.
	 * @return string Hook suffix.
	 */
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, $callback ): string {
		$GLOBALS['woocommerce_subscriptions_lite_test_submenus'][] = [
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
			'callback'    => $callback,
		];
		return 'woocommerce_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Record a script enqueue.
	 *
	 * @param string            $handle    Handle.
	 * @param string            $src       Source.
	 * @param array<int,string> $deps      Dependencies.
	 * @param string            $ver       Version.
	 * @param bool              $in_footer Whether in footer.
	 */
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], string $ver = '', bool $in_footer = false ): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_enqueued_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	/**
	 * Record inline script.
	 *
	 * @param string $handle   Handle.
	 * @param string $data     Inline data.
	 * @param string $position Position.
	 */
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_inline_scripts'][ $handle ][] = compact( 'data', 'position' );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Record a style enqueue.
	 *
	 * @param string            $handle Handle.
	 * @param string            $src    Source.
	 * @param array<int,string> $deps   Dependencies.
	 * @param string            $ver    Version.
	 */
	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], string $ver = '' ): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_enqueued_styles'][ $handle ] = compact( 'src', 'deps', 'ver' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode wrapper.
	 *
	 * @param mixed $value Value.
	 * @return string|false
	 */
	function wp_json_encode( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This stub defines wp_json_encode().
		return json_encode( $value );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability check stub.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return ! empty( $GLOBALS['woocommerce_subscriptions_lite_test_can_manage_woocommerce'] )
			&& 'manage_woocommerce' === $capability;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Throw instead of exiting.
	 *
	 * @param string $message Message.
	 * @throws RuntimeException Always.
	 */
	function wp_die( string $message ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub throws instead of rendering.
		throw new RuntimeException( $message );
	}
}
