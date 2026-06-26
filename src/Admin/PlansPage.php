<?php
/**
 * Admin page for managing subscription plans.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Dedicated WooCommerce submenu page for global subscription plans.
 */
final class PlansPage {

	const CAPABILITY = 'manage_woocommerce';

	const MENU_SLUG = 'wc-subscriptions-lite-plans';

	private const SCRIPT_HANDLE = 'wc-subscriptions-lite-admin-react';

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		$instance = new self();

		add_action( 'admin_menu', [ $instance, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_assets' ] );
	}

	/**
	 * Register the WooCommerce submenu item.
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Subscription Plans', 'woocommerce-subscriptions-lite' ),
			__( 'Subscription Plans', 'woocommerce-subscriptions-lite' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Enqueue the React app only on the plan manager page.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset_path = Package::get_path() . '/build/scripts/admin-react.asset.php';
		$asset      = is_readable( $asset_path ) ? require $asset_path : [
			'dependencies' => [],
			'version'      => Package::get_version(),
		];

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			Package::get_url() . '/build/scripts/admin-react.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? Package::get_version(),
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'woocommerce-subscriptions-lite',
			Package::get_path() . '/languages'
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wcSubscriptionsLitePlans = ' . wp_json_encode(
				[
					'restBase'      => '/wc/v3/subscriptions-engine/plans',
					'extensionSlug' => 'woocommerce-subscriptions-lite',
					'defaultStatus' => 'active',
					'definitions'   => $this->get_plan_data_definitions(),
				]
			) . ';',
			'before'
		);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wp-dataviews' );

		$style_path = Package::get_path() . '/build/scripts/style-admin-react.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				Package::get_url() . '/build/scripts/style-admin-react.css',
				[ 'wp-components', 'woocommerce_admin_styles' ],
				$asset['version'] ?? Package::get_version()
			);
			wp_style_add_data( self::SCRIPT_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Render the page shell.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage subscription plans.', 'woocommerce-subscriptions-lite' ) );
		}

		echo '<div class="wrap woocommerce wc-subscriptions-lite-plans-page">';
		echo '<div id="wc-subscriptions-lite-plan-manager" class="wc-subscriptions-lite-plan-manager"></div>';
		echo '</div>';
	}

	/**
	 * Get the plan data definitions.
	 *
	 * @return array
	 */
	protected function get_plan_data_definitions(): array {
		return [
			'statuses'       => [
				[
					'value' => Plan::STATUS_ACTIVE,
					'label' => __( 'Active', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value' => Plan::STATUS_ARCHIVED,
					'label' => __( 'Archived', 'woocommerce-subscriptions-lite' ),
				],
			],
			'billing_units'  => [
				[
					'value'    => 'day',
					'label'    => __( 'Day', 'woocommerce-subscriptions-lite' ),
					'singular' => __( 'day', 'woocommerce-subscriptions-lite' ),
					'plural'   => __( 'days', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value'    => 'week',
					'label'    => __( 'Week', 'woocommerce-subscriptions-lite' ),
					'singular' => __( 'week', 'woocommerce-subscriptions-lite' ),
					'plural'   => __( 'weeks', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value'    => 'month',
					'label'    => __( 'Month', 'woocommerce-subscriptions-lite' ),
					'singular' => __( 'month', 'woocommerce-subscriptions-lite' ),
					'plural'   => __( 'months', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value'    => 'year',
					'label'    => __( 'Year', 'woocommerce-subscriptions-lite' ),
					'singular' => __( 'year', 'woocommerce-subscriptions-lite' ),
					'plural'   => __( 'years', 'woocommerce-subscriptions-lite' ),
				],
			],
			'pricing_types'  => [
				[
					'value' => 'percentage',
					'label' => __( 'Percentage', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value' => 'fixed_amount',
					'label' => __( 'Fixed amount', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value' => 'price',
					'label' => __( 'Fixed price', 'woocommerce-subscriptions-lite' ),
				],
			],
			'pricing_scopes' => [
				[
					'value' => 'all',
					'label' => __( 'All cycles', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value' => 'first',
					'label' => __( 'First cycle', 'woocommerce-subscriptions-lite' ),
				],
				[
					'value' => 'n_cycles',
					'label' => __( 'First N cycles', 'woocommerce-subscriptions-lite' ),
				],
			],
		];
	}
}
