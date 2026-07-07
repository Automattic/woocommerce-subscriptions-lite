<?php
/**
 * WooCommerce Settings > Subscriptions tab hosting the plans manager.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Admin
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the global subscription plans manager inside the WooCommerce
 * Settings > Subscriptions tab.
 */
final class SettingsPage {

	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Slug of the WooCommerce settings tab that hosts the plans manager.
	 *
	 * Matches the premium plugin's tab slug so the URL and UX line up.
	 */
	const TAB_SLUG = 'subscriptions';

	private const SCRIPT_HANDLE = 'wc-subscriptions-lite-admin-react';

	/**
	 * Hook suffix of the WooCommerce settings screen.
	 */
	private const SETTINGS_HOOK_SUFFIX = 'woocommerce_page_wc-settings';

	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		$instance = new self();

		add_filter( 'woocommerce_settings_tabs_array', [ $instance, 'add_settings_tab' ], 50 );
		add_action( 'woocommerce_settings_' . self::TAB_SLUG, [ $instance, 'render' ] );
		add_action( 'admin_enqueue_scripts', [ $instance, 'enqueue_assets' ] );
	}

	/**
	 * Add the Subscriptions tab to the WooCommerce settings tabs.
	 *
	 * @param array<string,string> $tabs Existing settings tabs.
	 * @return array<string,string> Tabs including the Subscriptions tab.
	 */
	public function add_settings_tab( array $tabs ): array {
		$tabs[ self::TAB_SLUG ] = __( 'Subscriptions', 'woocommerce-subscriptions-lite' );

		return $tabs;
	}

	/**
	 * Enqueue the React app only on the Subscriptions settings tab.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::SETTINGS_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the tab only to gate asset loading; no state is changed.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( self::TAB_SLUG !== $tab ) {
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

		$config_json = wp_json_encode(
			[
				'restBase'      => '/wc/v3/subscriptions-engine/plans',
				'extensionSlug' => 'woocommerce-subscriptions-lite',
				'defaultStatus' => 'active',
				'definitions'   => $this->get_plan_data_definitions(),
				'currency'      => [
					'code'              => get_woocommerce_currency(),
					// The symbol getter returns HTML entities; decode server-side
					// so JS can render it as plain text.
					'symbol'            => html_entity_decode( get_woocommerce_currency_symbol() ),
					'position'          => get_option( 'woocommerce_currency_pos', 'left' ),
					'thousandSeparator' => wc_get_price_thousand_separator(),
					'decimalSeparator'  => wc_get_price_decimal_separator(),
					'decimals'          => wc_get_price_decimals(),
				],
			]
		);

		if ( false === $config_json ) {
			$config_json = '{}';
		}

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.wcSubscriptionsLitePlans = ' . $config_json . ';',
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
	 * Render the React mount point inside the Subscriptions settings tab.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage subscription plans.', 'woocommerce-subscriptions-lite' ) );
		}

		// The plans manager is REST-driven, so there are no form settings to
		// persist. Suppress WooCommerce's default "Save changes" button.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce-owned global read by the settings template.
		$GLOBALS['hide_save_button'] = true;

		?>
		<div class="wc-subscriptions-lite-settings-tab">
			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<h2 class="wc-subscriptions-lite-plans-list-label">
								<?php esc_html_e( 'Storewide subscription plans', 'woocommerce-subscriptions-lite' ); ?>
							</h2>
							<p class="wc-subscriptions-lite-plans-list-description">
								<?php esc_html_e( 'Create a set of subscription plans that can be easily added to simple and variable products.', 'woocommerce-subscriptions-lite' ); ?>
							</p>
						</th>
						<td class="item-description">
							<div id="wc-subscriptions-lite-plan-manager" class="wc-subscriptions-lite-plan-manager"></div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
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
