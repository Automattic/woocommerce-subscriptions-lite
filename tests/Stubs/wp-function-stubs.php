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

if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
	/**
	 * WooCommerce's default paid statuses - the engine's completion listeners read
	 * these at registration time.
	 *
	 * @return array<int, string>
	 */
	function wc_get_is_paid_statuses(): array {
		return [ 'processing', 'completed' ];
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
	 * Record a filter registration AND register the callback for dispatch.
	 *
	 * Recording into `woocommerce_subscriptions_lite_test_hooks` keeps the
	 * hook-binding assertions in the unit suite working; the separate dispatch
	 * registry lets {@see apply_filters()} actually run the callback, so a test
	 * can substitute a filtered value (e.g. the portal data provider) the same
	 * way production does.
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

		// Register for dispatch by apply_filters() below.
		$GLOBALS['woocommerce_subscriptions_lite_test_filters'][ $hook ][ $priority ][] = $callback;

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

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Report an admin context so the bootstrap exercises its admin wiring.
	 *
	 * Overridable per test via the `woocommerce_subscriptions_lite_test_is_admin`
	 * global for cases that need the non-admin branch.
	 *
	 * @return bool
	 */
	function is_admin(): bool {
		return $GLOBALS['woocommerce_subscriptions_lite_test_is_admin'] ?? true;
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

if ( ! function_exists( 'plugins_url' ) ) {
	/**
	 * Return a stable fake plugin URL.
	 *
	 * @param string $path   Optional path.
	 * @param string $plugin Plugin file or directory.
	 * @return string
	 */
	function plugins_url( string $path = '', string $plugin = '' ): string {
		$base = 'https://example.test/wp-content/plugins/woocommerce-subscriptions-lite';
		$path = ltrim( $path, '/' );

		return '' === $path ? $base : $base . '/' . $path;
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Remove trailing slashes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
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

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	/**
	 * Record script translations.
	 *
	 * @param string $handle Script handle.
	 * @param string $domain Text domain.
	 * @param string $path   Language directory path.
	 * @return bool
	 */
	function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
		$GLOBALS['woocommerce_subscriptions_lite_test_script_translations'][ $handle ] = compact( 'domain', 'path' );
		return true;
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

if ( ! function_exists( 'wp_style_add_data' ) ) {
	/**
	 * Record style metadata.
	 *
	 * @param string $handle Style handle.
	 * @param string $key    Metadata key.
	 * @param mixed  $value  Metadata value.
	 * @return bool
	 */
	function wp_style_add_data( string $handle, string $key, $value ): bool {
		$GLOBALS['woocommerce_subscriptions_lite_test_style_data'][ $handle ][ $key ] = $value;
		return true;
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

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Pass-through escaping stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Pass-through attribute-escaping stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Plural-aware translation stub: returns singular for count 1, else plural.
	 *
	 * @param string $single Singular text.
	 * @param string $plural Plural text.
	 * @param int    $number The count.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stub: runs any callbacks {@see add_filter()} registered for the hook
	 * (priority-ordered), else returns the value unchanged.
	 *
	 * Only the first argument is passed to callbacks, which is all the Lite
	 * filters that tests exercise need (e.g. the portal data-provider filter).
	 *
	 * @param string $hook  Filter name.
	 * @param mixed  $value The value being filtered.
	 * @param mixed  ...$args Extra filter args (unused by the registered callbacks).
	 * @return mixed
	 */
	function apply_filters( string $hook, $value = null, ...$args ) {
		unset( $args );
		if ( ! isset( $GLOBALS['woocommerce_subscriptions_lite_test_filters'][ $hook ] ) ) {
			return $value;
		}
		$by_priority = $GLOBALS['woocommerce_subscriptions_lite_test_filters'][ $hook ];
		ksort( $by_priority );
		foreach ( $by_priority as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value );
			}
		}
		return $value;
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option stub: returns a fixed date format, the default otherwise.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		if ( 'date_format' === $name ) {
			return 'Y-m-d';
		}
		return $default;
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	/**
	 * Date-format stub backed by gmdate so tests are timezone-stable.
	 *
	 * @param string   $format    PHP date format.
	 * @param int|null $timestamp Unix timestamp.
	 * @return string
	 */
	function date_i18n( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, null === $timestamp ? time() : $timestamp );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags stub.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	function wp_strip_all_tags( string $text ): string {
		return trim( (string) wp_strip_all_tags_inner( $text ) );
	}

	/**
	 * Inner strip helper kept separate so the guard above stays a one-liner.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	function wp_strip_all_tags_inner( string $text ): string {
		return preg_replace( '/<[^>]*>/', '', $text );
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * Minimal price-format stub: `{CUR}{amount}` with two decimals.
	 *
	 * @param float                $amount Amount.
	 * @param array<string, mixed> $args   Format args (reads `currency`).
	 * @return string
	 */
	function wc_price( float $amount, array $args = [] ): string {
		$currency = isset( $args['currency'] ) && is_string( $args['currency'] ) && '' !== $args['currency']
			? $args['currency']
			: 'USD';
		return $currency . number_format( $amount, 2 );
	}
}

if ( ! function_exists( 'wc_get_order_status_name' ) ) {
	/**
	 * Minimal order-status-label stub: humanizes the slug (real WooCommerce maps
	 * registered statuses to their display names; the portal only needs a label).
	 *
	 * @param string $status Order status slug (with or without the `wc-` prefix).
	 * @return string
	 */
	function wc_get_order_status_name( string $status ): string {
		$slug = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		return ucfirst( str_replace( '-', ' ', $slug ) );
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Minimal WooCommerce-instance stub: exposes only `countries` with the one
	 * formatter the portal view-model calls. The stub joins non-empty address
	 * lines with `<br/>` (real WooCommerce applies per-country formats; the
	 * portal only needs deterministic display lines).
	 *
	 * @return object
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Mirrors WooCommerce core's real function name.
		static $wc = null;
		if ( null === $wc ) {
			$countries = new class() {
				/**
				 * Format an address field array into display HTML.
				 *
				 * @param array<string, string> $fields WC-style address fields.
				 */
				public function get_formatted_address( array $fields ): string {
					$not_blank = static function ( string $part ): bool {
						return '' !== trim( $part );
					};

					$name     = trim( ( $fields['first_name'] ?? '' ) . ' ' . ( $fields['last_name'] ?? '' ) );
					$locality = implode(
						' ',
						array_filter(
							[
								$fields['city'] ?? '',
								$fields['state'] ?? '',
								$fields['postcode'] ?? '',
							],
							$not_blank
						)
					);
					$lines    = array_filter(
						[
							$name,
							$fields['company'] ?? '',
							$fields['address_1'] ?? '',
							$fields['address_2'] ?? '',
							$locality,
							$fields['country'] ?? '',
						],
						$not_blank
					);
					return implode( '<br/>', $lines );
				}
			};

			$wc            = new stdClass();
			$wc->countries = $countries;
		}
		return $wc;
	}
}
