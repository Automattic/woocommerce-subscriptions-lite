<?php
/**
 * Cart/Checkout Blocks integration - enqueues the checkout-filters script.
 *
 * A WooCommerce Blocks `IntegrationInterface` so the script loads only with the
 * Cart and Checkout blocks (not on every front-end page). The script registers
 * `registerCheckoutFilters` that read {@see StoreApiExtension}'s per-item and
 * cart-level data to append the billing cadence to the price and relabel the
 * order total "Total due today".
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Cart\Blocks
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Cart\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the checkout-filters bundle with the Cart and Checkout blocks.
 */
final class Integration implements IntegrationInterface {

	private const SCRIPT_HANDLE = 'woocommerce-subscriptions-lite-checkout-filters';

	/**
	 * Register the integration with the Cart and Checkout block registries, if
	 * WooCommerce Blocks is present.
	 */
	public static function register(): void {
		if ( ! interface_exists( IntegrationInterface::class ) ) {
			return;
		}

		$add = static function ( $registry ): void {
			$registry->register( new self() );
		};
		add_action( 'woocommerce_blocks_cart_block_registration', $add );
		add_action( 'woocommerce_blocks_checkout_block_registration', $add );
	}

	/**
	 * Integration name.
	 */
	public function get_name(): string {
		return Package::EXTENSION_SLUG;
	}

	/**
	 * Register the checkout-filters script from the build output.
	 */
	public function initialize(): void {
		$asset_path = Package::get_path() . '/build/scripts/checkout-filters.asset.php';
		$asset      = is_readable( $asset_path ) ? require $asset_path : [
			'dependencies' => [],
			'version'      => Package::get_version(),
		];

		wp_register_script(
			self::SCRIPT_HANDLE,
			Package::get_url() . '/build/scripts/checkout-filters.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? Package::get_version(),
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'woocommerce-subscriptions-lite',
			Package::get_path() . '/languages'
		);
	}

	/**
	 * Front-end script handles the blocks should load.
	 *
	 * @return string[]
	 */
	public function get_script_handles(): array {
		return [ self::SCRIPT_HANDLE ];
	}

	/**
	 * Editor script handles (none - this is a storefront-only integration).
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles(): array {
		return [];
	}

	/**
	 * Data passed to the script (none - all data rides the Store API extension).
	 *
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return [];
	}
}
