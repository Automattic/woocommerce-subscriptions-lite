<?php
/**
 * Version-register shim for the woocommerce-subscriptions-lite package.
 *
 * Lite is a shared package: the wrapper plugin ships it, and a Premium plugin
 * may vendor its own copy too. Multiple copies can therefore be present on one
 * site at once. This shim makes that safe by resolving exactly one copy at
 * runtime - the highest version wins - mirroring how the engine package
 * resolves itself.
 *
 * Every consumer requires its OWN vendored copy of this file EXPLICITLY
 * (`require .../version-register.php` from the plugin bootstrap). It is NOT
 * wired through Composer "files" autoload on purpose: Composer dedupes
 * files-autoload entries by md5(package . ':' . path), which is identical for
 * every vendored copy of this package, so the second consumer's copy would
 * silently never be included. Action Scheduler avoids files autoload for the
 * same reason.
 *
 * All definitions are guarded so any number of copies can be included safely:
 * the first include wins the function definitions, every include registers its
 * own copy's version + directory, and the highest version is initialized once.
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
	 * Runs on `plugins_loaded` priority 0, after every active plugin has
	 * required its vendored shim at plugin-load time. Registers a PSR-4-style
	 * autoloader pointed at the winning copy's `src/` directory so the
	 * `Automattic\WooCommerce\SubscriptionsLite\` namespace always resolves to
	 * a single copy, regardless of which Composer autoloaders are present.
	 *
	 * @return void
	 */
	function wc_subscriptions_lite_initialize() {
		$registry = $GLOBALS['wc_subscriptions_lite_registry'];
		if ( empty( $registry ) ) {
			return;
		}

		uksort( $registry, 'version_compare' );
		$winner_version = array_key_last( $registry );
		$winner_dir     = $registry[ $winner_version ];

		$GLOBALS['wc_subscriptions_lite_active_version'] = $winner_version;

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
