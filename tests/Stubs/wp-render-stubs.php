<?php
/**
 * Render/template helper doubles for the integration suite.
 *
 * These let the real portal render path (endpoints -> templates) run in-process
 * without booting WordPress: a working `wc_get_template` that includes the
 * package's own template files, escaping pass-throughs, endpoint-URL helpers,
 * and a `wp_interactivity_state` double that records the seeded state so a test
 * can assert it. Each is guarded so the file is inert under a real WordPress.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_interactivity_state' ) ) {
	/**
	 * Record (and merge) seeded Interactivity API state per namespace so a test
	 * can read back what the render path seeded.
	 *
	 * @param string                    $namespace Store namespace.
	 * @param array<string, mixed>|null $state     State to merge.
	 * @return array<string, mixed> The merged state for the namespace.
	 */
	function wp_interactivity_state( string $namespace, ?array $state = null ): array {
		if ( ! isset( $GLOBALS['wc_subs_lite_iapi_state'] ) ) {
			$GLOBALS['wc_subs_lite_iapi_state'] = [];
		}
		if ( ! isset( $GLOBALS['wc_subs_lite_iapi_state'][ $namespace ] ) ) {
			$GLOBALS['wc_subs_lite_iapi_state'][ $namespace ] = [];
		}
		if ( is_array( $state ) ) {
			$GLOBALS['wc_subs_lite_iapi_state'][ $namespace ] = array_merge(
				$GLOBALS['wc_subs_lite_iapi_state'][ $namespace ],
				$state
			);
		}
		return $GLOBALS['wc_subs_lite_iapi_state'][ $namespace ];
	}
}

if ( ! function_exists( 'wc_get_template' ) ) {
	/**
	 * Include a template file from a base path, extracting args into scope.
	 *
	 * @param string               $template_name Template path relative to the base.
	 * @param array<string, mixed> $args          Template args.
	 * @param string               $template_path Unused (theme override path).
	 * @param string               $default_path  Base path the template lives under.
	 */
	function wc_get_template( string $template_name, array $args = [], string $template_path = '', string $default_path = '' ): void {
		$file = rtrim( $default_path, '/' ) . '/' . $template_name;
		if ( ! is_readable( $file ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- test double mirroring wc_get_template behaviour.
		extract( $args );
		include $file;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * No-op action dispatcher for the render path.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Args (ignored).
	 */
	function do_action( string $hook, ...$args ): void {
		unset( $hook, $args );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Absolute integer cast.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Current user id stub, controllable via a global.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return isset( $GLOBALS['wc_subs_lite_current_user_id'] )
			? (int) $GLOBALS['wc_subs_lite_current_user_id']
			: 1;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Nonce stub.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( string $action = '-1' ): string {
		return 'nonce-' . md5( $action );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * REST URL stub.
	 *
	 * @param string $path REST path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	/**
	 * Page-permalink stub.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	function wc_get_page_permalink( string $page ): string {
		return 'https://example.test/' . $page . '/';
	}
}

if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
	/**
	 * Endpoint-URL stub.
	 *
	 * @param string $endpoint  Endpoint slug.
	 * @param string $value     Endpoint value.
	 * @param string $permalink Base permalink.
	 * @return string
	 */
	function wc_get_endpoint_url( string $endpoint, string $value = '', string $permalink = '' ): string {
		$url = rtrim( $permalink, '/' ) . '/' . $endpoint;
		if ( '' !== $value ) {
			$url .= '/' . $value;
		}
		return $url . '/';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Unslash pass-through (test input is never slashed).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Minimal query-arg stub: appends `key=value` with the right separator (the
	 * portal only adds a single pagination arg to already-built URLs).
	 *
	 * @param string     $key   Query key.
	 * @param int|string $value Query value.
	 * @param string     $url   Base URL.
	 * @return string
	 */
	function add_query_arg( string $key, $value, string $url ): string {
		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $separator . $key . '=' . rawurlencode( (string) $value );
	}
}

if ( ! function_exists( 'wc_print_notices' ) ) {
	/**
	 * Notice-print stub (no-op in tests).
	 */
	function wc_print_notices(): void {
	}
}

if ( ! function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
	/**
	 * Theme element-class stub (no theme button class in tests).
	 *
	 * @param string $element Element key.
	 * @return string
	 */
	function wc_wp_theme_get_element_class_name( string $element ): string {
		unset( $element );
		return '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape pass-through.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape pass-through.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Post-kses pass-through: the templates feed it trusted formatter output
	 * (address HTML with `<br/>` line breaks), which the render assertions
	 * inspect verbatim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_kses_post( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * URL escape pass-through.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Echoing translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Echoing attribute translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Attribute translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
