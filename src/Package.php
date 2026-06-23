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
}
