<?php
/**
 * Version-register shim for the woocommerce-subscriptions-lite package.
 *
 * Multiple copies of Lite can be present on one site (the wrapper plugin ships
 * one; a Premium plugin may vendor another). This shim resolves exactly one at
 * runtime - the highest version wins - mirroring the engine package.
 *
 * Each consumer requires its own copy explicitly from its bootstrap, never via
 * Composer "files" autoload: Composer dedupes identical vendored copies, so the
 * second consumer's copy would never load. All definitions are guarded, so any
 * number of includes is safe.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wc_subscriptions_lite_register' ) ) {
	$GLOBALS['wc_subscriptions_lite_registry'] = [];

	/**
	 * Record one vendored copy of the Lite package.
	 *
	 * @param string $version Package version (from this copy's composer metadata).
	 * @param string $dir     Absolute path to this copy's package root (the
	 *                        directory containing `src/`).
	 * @return void
	 */
	function wc_subscriptions_lite_register( $version, $dir ) {
		$GLOBALS['wc_subscriptions_lite_registry'][ $version ] = $dir;
	}

	/**
	 * Return the version of the Lite copy that won resolution, or null before
	 * resolution has run (i.e. before `plugins_loaded` priority 0).
	 *
	 * @return string|null
	 */
	function wc_subscriptions_lite_active_version() {
		return isset( $GLOBALS['wc_subscriptions_lite_active_version'] )
			? $GLOBALS['wc_subscriptions_lite_active_version']
			: null;
	}

	/**
	 * Resolve and initialize exactly one copy: the highest registered version.
	 *
	 * Run resolution on `plugins_loaded` priority 0, which is after all active
	 * plugins have loaded their files and registered any Subscriptions Lite
	 * versions. Once the version has been picked, create a PSR-4 autoloader for
	 * the chosen version's `src/` directory.
	 *
	 * @return void
	 */
	function wc_subscriptions_lite_initialize() {
		if ( ! isset( $GLOBALS['wc_subscriptions_lite_registry'] ) ) {
			return;
		}
		$registry = $GLOBALS['wc_subscriptions_lite_registry'];
		if ( empty( $registry ) ) {
			return;
		}

		uksort( $registry, 'version_compare' );
		$winner_version = array_key_last( $registry );
		$winner_dir     = $registry[ $winner_version ];

		$GLOBALS['wc_subscriptions_lite_active_version'] = $winner_version;

		// Bind the namespace to the resolved winner with a dedicated autoloader
		// rather than each copy's own Composer autoloader. Every vendored copy
		// maps `Automattic\WooCommerce\SubscriptionsLite\` to its own `src/`, so
		// whichever Composer autoloader registered first would win - not the
		// highest version. One autoloader pointed at the winner keeps the
		// namespace resolving to a single copy.
		spl_autoload_register(
			static function ( $class_name ) use ( $winner_dir ) {
				$prefix = 'Automattic\\WooCommerce\\SubscriptionsLite\\';
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$path     = $winner_dir . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $path ) ) {
					require $path;
				}
			}
		);
	}

	add_action( 'plugins_loaded', 'wc_subscriptions_lite_initialize', 0 );
}

// The ONLY per-copy line: the version literal is stamped by the release build
// to match this copy's composer.json. `__DIR__` is the package root because
// this file sits alongside `src/`.
wc_subscriptions_lite_register( '0.0.1', __DIR__ );
