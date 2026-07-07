<?php
/**
 * PlanPicker - the PDP plan picker on the add-to-cart form.
 *
 * Renders the one-time vs subscribe choice and the plan select on
 * `woocommerce_before_add_to_cart_button` for simple and variable products
 * that resolve at least one selling plan. The markup is a server-rendered,
 * theme-overridable template (templates/single-product/plan-picker.php); its
 * behavior rides on the `woocommerce-subscriptions-lite/plan-picker`
 * Interactivity API store built to `build/modules/plan-picker-view.js`. The
 * chosen plan posts as `_selling_plan_id` - the key the checkout slice reads.
 *
 * Plans and applicability come from the engine's public
 * {@see \Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans} facade;
 * Lite owns no applicability schema.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\ProductPage
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\ProductPage;

use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Package;

defined( 'ABSPATH' ) || exit;

/**
 * PDP plan picker renderer and asset wiring.
 *
 * Construct via the no-arg constructor in production (facade + template
 * defaults); tests inject fake resolver / reader / renderer seams to exercise
 * the gating and template args without a database.
 */
final class PlanPicker {

	/**
	 * Script-module id for the Interactivity API view module.
	 */
	const MODULE_ID = 'woocommerce-subscriptions-lite/plan-picker-view';

	/**
	 * Handle for the picker's structural stylesheet.
	 */
	const STYLE_HANDLE = 'wc-subscriptions-lite-plan-picker';

	/**
	 * Template rendered into the add-to-cart form.
	 */
	const TEMPLATE = 'single-product/plan-picker.php';

	/**
	 * Product types that may render the picker - the same two that may carry
	 * applicability on the engine side. Shared with the admin panel's save
	 * gate ({@see \Automattic\WooCommerce\SubscriptionsLite\Admin\ProductPlansPanel})
	 * so the two lists cannot drift.
	 *
	 * @var array<int, string>
	 */
	const SUPPORTED_PRODUCT_TYPES = [ 'simple', 'variable' ];

	/**
	 * Plans resolver. Production: the facade resolution scoped to Lite's slug.
	 *
	 * @var callable(int): array<int, \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan>
	 */
	private $plans_resolver;

	/**
	 * Applicability reader (for the allow-one-time flag). Production: the facade read.
	 *
	 * @var callable(int): ProductApplicability
	 */
	private $applicability_reader;

	/**
	 * Template renderer; echoes the template for the given args. Production:
	 * wc_get_template() over the package's templates directory.
	 *
	 * @var callable(array<string, mixed>): void
	 */
	private $template_renderer;

	/**
	 * Construct the picker.
	 *
	 * @param (callable(int): array<int, mixed>)|null     $plans_resolver       Plans resolver; defaults to the facade.
	 * @param (callable(int): ProductApplicability)|null  $applicability_reader Applicability reader; defaults to the facade.
	 * @param (callable(array<string, mixed>): void)|null $template_renderer    Template renderer; defaults to wc_get_template().
	 */
	public function __construct(
		?callable $plans_resolver = null,
		?callable $applicability_reader = null,
		?callable $template_renderer = null
	) {
		$this->plans_resolver       = $plans_resolver ?? static function ( int $product_id ): array {
			return SellingPlans::for_product( $product_id, Package::EXTENSION_SLUG );
		};
		$this->applicability_reader = $applicability_reader ?? static function ( int $product_id ): ProductApplicability {
			return SellingPlans::get_product_applicability( $product_id );
		};
		$this->template_renderer    = $template_renderer ?? static function ( array $args ): void {
			wc_get_template( self::TEMPLATE, $args, '', Package::get_path() . '/templates/' );
		};
	}

	/**
	 * Wire the render and asset hooks. Called once from the bootstrap.
	 *
	 * WooCommerce core fires `woocommerce_before_add_to_cart_button` inside
	 * the add-to-cart form for both simple and variable products (classic,
	 * block, and shortcode product pages alike), so the picker's fields
	 * always post with the form.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'woocommerce_before_add_to_cart_button', [ $instance, 'print_picker' ] );
		add_action( 'wp_enqueue_scripts', [ $instance, 'enqueue_assets' ] );
	}

	/**
	 * Echo the picker for the product being rendered.
	 */
	public function print_picker(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value it prints.
		echo $this->render( $product );
	}

	/**
	 * Render the picker for a product.
	 *
	 * Returns the empty string when there is nothing to render - unsupported
	 * product type or no resolved plans - and never emits whitespace, so
	 * products sold one-time only are untouched.
	 *
	 * @param WC_Product $product Product the PDP is rendering.
	 * @return string Picker HTML, or '' when nothing should render.
	 */
	public function render( WC_Product $product ): string {
		if ( ! in_array( $product->get_type(), self::SUPPORTED_PRODUCT_TYPES, true ) ) {
			return '';
		}

		$product_id = (int) $product->get_id();

		$plans = ( $this->plans_resolver )( $product_id );
		if ( empty( $plans ) ) {
			return '';
		}

		$applicability = ( $this->applicability_reader )( $product_id );

		ob_start();
		( $this->template_renderer )(
			[
				'product'          => $product,
				'plans'            => $plans,
				// A variable product reports its minimum variation price - the
				// seed for the first paint; variation selection swaps in the
				// per-variation strings from the variation payload.
				'base_price'       => (float) $product->get_price(),
				'is_variable'      => 'variable' === $product->get_type(),
				'one_time_allowed' => $applicability->allows_one_time(),
			]
		);

		return (string) ob_get_clean();
	}

	/**
	 * Enqueue the picker view module and stylesheet on product singulars only.
	 *
	 * The module build externalizes `@wordpress/interactivity` as an
	 * import-mapped dependency (listed in the generated asset file) and emits
	 * the RTL stylesheet variant, registered via the `rtl` style data.
	 */
	public function enqueue_assets(): void {
		if ( ! is_product() ) {
			return;
		}

		$asset_path = Package::get_path() . '/build/modules/plan-picker-view.asset.php';
		$asset      = is_readable( $asset_path ) ? (array) include $asset_path : [];
		$version    = isset( $asset['version'] ) ? (string) $asset['version'] : Package::get_version();

		wp_enqueue_script_module(
			self::MODULE_ID,
			Package::get_url() . '/build/modules/plan-picker-view.js',
			isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : [],
			$version
		);

		wp_enqueue_style(
			self::STYLE_HANDLE,
			Package::get_url() . '/build/modules/style-plan-picker-view.css',
			[],
			$version
		);
		wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );
	}
}
