<?php
/**
 * Plugin Name: WooCommerce Subscriptions Lite
 * Plugin URI: https://github.com/Automattic/woocommerce-subscriptions-lite
 * Description: Free subscriptions for WooCommerce, built on the WooCommerce subscriptions engine. Early development scaffold - not functional yet.
 * Author: Automattic
 * Author URI: https://automattic.com
 * Version: 0.0.1-dev
 * Requires PHP: 7.4
 * Requires at least: 6.9
 * WC requires at least: 10.0
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woocommerce-subscriptions-lite
 * Requires Plugins: woocommerce
 *
 * @package Automattic\WooCommerce\SubscriptionsLite
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite;

defined( 'ABSPATH' ) || exit;

define( 'WC_SUBSCRIPTIONS_LITE_VERSION', '0.0.1-dev' );
define( 'WC_SUBSCRIPTIONS_LITE_FILE', __FILE__ );
define( 'WC_SUBSCRIPTIONS_LITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_SUBSCRIPTIONS_LITE_URL', plugin_dir_url( __FILE__ ) );

/*
 * Load order, mirroring the engine/Action Scheduler contract:
 *
 *   1. Composer autoloader (PSR-4 for this package and its dependencies).
 *   2. The version-register shims, each via an EXPLICIT require - never through
 *      Composer "files" autoload, which dedupes identical vendored copies and
 *      silently skips registration for the second consumer. Each shim records
 *      its copy's version + directory; the highest version is resolved once at
 *      plugins_loaded priority 0.
 *   3. On plugins_loaded priority 5 (after resolution at priority 0), run the
 *      runtime guards and, if they pass, boot the feature modules.
 *
 * The engine ships its own version-register shim in a later phase. Until that
 * lands, the engine require below is guarded by file_exists so this wrapper
 * loads cleanly against an engine build that has not yet added the shim.
 */

if ( ! file_exists( WC_SUBSCRIPTIONS_LITE_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WooCommerce Subscriptions Lite: dependencies are missing. Run composer install in the plugin directory.', 'woocommerce-subscriptions-lite' );
			echo '</p></div>';
		}
	);
	return;
}
require_once WC_SUBSCRIPTIONS_LITE_DIR . 'vendor/autoload.php';

// Explicitly require the engine's version-register shim (never via Composer
// files autoload). The path is the conventional vendored location; the engine
// adds this file in a later phase, so the require is guarded until then.
$wc_subscriptions_lite_engine_shim = WC_SUBSCRIPTIONS_LITE_DIR . 'vendor/automattic/woocommerce-subscriptions-engine/version-register.php';
if ( file_exists( $wc_subscriptions_lite_engine_shim ) ) {
	require_once $wc_subscriptions_lite_engine_shim;
}
unset( $wc_subscriptions_lite_engine_shim );

// Explicitly require this package's own version-register shim so Premium (which
// vendors Lite) and this wrapper resolve to a single highest-version copy.
require_once WC_SUBSCRIPTIONS_LITE_DIR . 'version-register.php';

/**
 * Run the runtime guards and boot the feature modules.
 *
 * Stands down with an admin notice instead of fataling when a dependency is
 * missing: the Requires Plugins header is UI-only and does not guarantee the
 * dependency is present at runtime (wp-cli, partial deploys, and direct DB
 * edits all bypass it).
 *
 * Priority 5 runs after the version registries resolve at priority 0.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Guard: WooCommerce must be active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'WooCommerce Subscriptions Lite requires WooCommerce to be active.', 'woocommerce-subscriptions-lite' );
					echo '</p></div>';
				}
			);
			return;
		}

		// Guard: the engine must have resolved a version via its shim.
		//
		// Probe by function name, not class, to avoid coupling to the engine
		// API while it is still taking shape. This is a soft guard: if the
		// engine ships no shim yet, the probe is absent and Lite proceeds
		// (nothing depends on the engine yet). Once the shim exists, an
		// unresolved version makes Lite stand down instead of fatal.
		//
		// TODO: require the engine and check its version against Lite's floor
		// once Lite consumes the engine surface.
		$engine_probe = 'wc_subscriptions_engine_active_version';
		if ( function_exists( $engine_probe ) && null === \call_user_func( $engine_probe ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'WooCommerce Subscriptions Lite could not resolve the subscriptions engine.', 'woocommerce-subscriptions-lite' );
					echo '</p></div>';
				}
			);
			return;
		}

		Bootstrap::init();
	},
	5
);

/*
 * Flush rewrite rules on activation/deactivation so the My Account customer
 * portal endpoints resolve on first visit. The endpoint slugs are registered by
 * the Portal feature module (a later phase); flushing here is harmless before
 * those endpoints exist and avoids a stale-rewrite gap once they do.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		// TODO: register the portal endpoints before flushing once the Portal
		// module lands, so their rewrite rules are present at flush time.
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);
