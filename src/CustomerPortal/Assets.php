<?php
/**
 * Assets - registers the customer portal's iAPI store module + styles.
 *
 * Enqueues the Interactivity API store (built from
 * `src/js/frontend/customer-portal-store.js`) as a script MODULE - the iAPI
 * runtime is a script module, and `@wordpress/interactivity` is externalised by
 * the WC dependency-extraction webpack plugin - and the portal stylesheet, only
 * on the two portal endpoints so nothing leaks onto other My Account pages.
 *
 * All paths resolve through {@see Package::get_url()} / {@see Package::get_path()}
 * (package-relative), so assets load whether the package ships as the standalone
 * Lite plugin or bundled inside the premium plugin under highest-version-wins.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\CustomerPortal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\CustomerPortal;

use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-portal asset registration.
 */
final class Assets {

	/**
	 * Script-module id for the iAPI store.
	 */
	const SCRIPT_MODULE_ID = 'woocommerce-subscriptions-lite-customer-portal';

	/**
	 * Style handle for the portal stylesheet.
	 */
	const STYLE_HANDLE = 'woocommerce-subscriptions-lite-customer-portal';

	/**
	 * Wire the asset enqueue. Idempotent; called once from the bootstrap.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'wp_enqueue_scripts', [ $instance, 'enqueue' ] );
	}

	/**
	 * Enqueue the portal assets, but only on the two portal endpoints.
	 */
	public function enqueue(): void {
		if ( ! $this->is_portal_page() ) {
			return;
		}

		$build_url  = Package::get_url() . '/build/modules';
		$build_path = Package::get_path() . '/build/modules';
		$version    = Package::get_version();

		// The iAPI store is a script module; `@wordpress/interactivity` is its
		// dependency, externalised by the build. Register + enqueue as a module
		// so the Interactivity API runtime resolves it.
		$asset = $this->read_asset_meta( $build_path . '/customer-portal.asset.php', $version );
		wp_register_script_module(
			self::SCRIPT_MODULE_ID,
			$build_url . '/customer-portal.js',
			$asset['dependencies'],
			$asset['version']
		);
		wp_enqueue_script_module( self::SCRIPT_MODULE_ID );

		// The portal stylesheet is compiled by @wordpress/scripts from the SCSS
		// the store entry imports, emitted as `style-customer-portal.css` (the
		// `style-` prefix is wp-scripts' convention for an entry's stylesheet);
		// the matching `style-customer-portal-rtl.css` is picked up via the
		// `rtl` style data below. It is versioned off the same asset-meta hash as
		// the module so a content change busts the cache.
		wp_enqueue_style(
			self::STYLE_HANDLE,
			$build_url . '/style-customer-portal.css',
			[],
			$asset['version']
		);
		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );
	}

	/**
	 * The REST base the iAPI store posts lifecycle actions to: the engine's
	 * contracts route - the one implementation of the lifecycle transitions,
	 * which consumers call rather than re-route.
	 *
	 * @return string Absolute REST base URL, no trailing contract id.
	 */
	public static function rest_base(): string {
		return rest_url( 'wc/v3/subscriptions-engine/contracts/' );
	}

	/**
	 * Whether the current request is one of the two portal endpoints.
	 */
	private function is_portal_page(): bool {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}
		global $wp_query;
		if ( ! isset( $wp_query ) || ! isset( $wp_query->query_vars ) ) {
			return false;
		}
		return isset( $wp_query->query_vars[ Endpoints::LIST_ENDPOINT ] )
			|| isset( $wp_query->query_vars[ Endpoints::DETAIL_ENDPOINT ] );
	}

	/**
	 * Read the build's asset-meta file (dependencies + version), with a safe
	 * fallback when the file is absent (e.g. before a build).
	 *
	 * @param string $asset_file Absolute path to the `*.asset.php` file.
	 * @param string $fallback   Fallback version when the file is missing.
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private function read_asset_meta( string $asset_file, string $fallback ): array {
		$meta = is_readable( $asset_file ) ? require $asset_file : [];
		return [
			'dependencies' => isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : [],
			'version'      => isset( $meta['version'] ) && is_string( $meta['version'] ) ? $meta['version'] : $fallback,
		];
	}
}
