<?php
/**
 * Main package class for WooCommerce Subscriptions Lite.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite;

defined( 'ABSPATH' ) || exit;

/**
 * Package entry point.
 *
 * Exposes the package version and root path and boots the feature modules.
 * Mirrors the WooCommerce Subscriptions Engine package shape so the same
 * accessors are available across the engine-family packages. Path and version
 * are read through these static accessors rather than global constants.
 */
final class Package {

	/**
	 * Package version. Kept in sync with the plugin header.
	 *
	 * Read via get_version(); intended consumers are the version-register shim
	 * (highest-version-wins registration) and asset cache-busting. No caller yet
	 * at the skeleton stage.
	 */
	const VERSION = '0.0.1-dev';

	/**
	 * Boot the package's feature modules.
	 */
	public static function init(): void {
		Bootstrap::init();
	}

	/**
	 * Return the version of the package.
	 */
	public static function get_version(): string {
		return self::VERSION;
	}

	/**
	 * Return the absolute path to the package root (no trailing slash).
	 */
	public static function get_path(): string {
		return dirname( __DIR__ );
	}

	/**
	 * Return the public URL to the package root (no trailing slash).
	 *
	 * Derived from this file's location so it resolves correctly wherever the
	 * package is installed (plugin directory, or bundled inside a host). Used
	 * for enqueuing the package's own assets with a cache-busting version.
	 *
	 * `plugins_url( '', __DIR__ )` returns the URL of `dirname( __DIR__ )`, the
	 * package root (this file lives in `src/`).
	 */
	public static function get_url(): string {
		return untrailingslashit( plugins_url( '', __DIR__ ) );
	}
}
