<?php
/**
 * Integration tests for the PDP plan picker.
 *
 * The picker runs END TO END: real products, plans resolved through the
 * engine's SellingPlans facade from real applicability meta, and the packaged
 * template rendered through the real wc_get_template() - so the gating, the
 * server-painted picker states, and the theme-override seam are all exercised
 * as in production.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\ProductPage;

use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanPicker;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Product;
use WC_Product_External;
use WC_Product_Simple;
use WC_Product_Variable;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanPicker
 */
final class PlanPickerTest extends LiteIntegrationTestCase {

	/**
	 * Path of the temporary theme-override template, when a test creates one.
	 *
	 * @var string|null
	 */
	private $override_template = null;

	public function tear_down(): void {
		if ( null !== $this->override_template && file_exists( $this->override_template ) ) {
			wp_delete_file( $this->override_template );
			$this->override_template = null;
		}
		parent::tear_down();
	}

	/**
	 * Create a saved product of the given class, priced and marked as selling
	 * on all storewide plans.
	 *
	 * @param string $product_class  Product class (a WC_Product subclass) to instantiate.
	 * @param bool   $allow_one_time Whether one-time purchase stays offered.
	 */
	private function subscribable_product( string $product_class = WC_Product_Simple::class, bool $allow_one_time = true ): WC_Product {
		$product = new $product_class();
		$product->set_name( 'Coffee Box' );
		if ( ! $product instanceof WC_Product_Variable ) {
			$product->set_regular_price( '24.00' );
		}
		$product->save();

		SellingPlans::set_product_applicability(
			$product->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL, [], $allow_one_time ),
			Package::EXTENSION_SLUG
		);

		return $product;
	}

	public function test_render_returns_empty_for_unsupported_product_types(): void {
		$this->make_plan();
		$product = new WC_Product_External();
		$product->set_name( 'Affiliate Gadget' );
		$product->save();

		$this->assertSame( '', ( new PlanPicker() )->render( $product ) );
	}

	public function test_render_returns_empty_when_no_plans_resolve(): void {
		$this->make_plan();

		// A real plan exists storewide, but the product's applicability keeps
		// the default (disable), so nothing resolves for it.
		$product = new WC_Product_Simple();
		$product->set_name( 'One-time Mug' );
		$product->set_regular_price( '24.00' );
		$product->save();

		$this->assertSame( '', ( new PlanPicker() )->render( $product ) );
	}

	public function test_render_paints_the_one_time_preselected_picker(): void {
		$plan    = $this->make_plan();
		$product = $this->subscribable_product();

		$html = ( new PlanPicker() )->render( $product );

		$this->assertStringContainsString( 'data-wp-interactive="woocommerce-subscriptions-lite/plan-picker"', $html );
		$this->assertStringContainsString( 'name="_selling_plan_id"', $html, 'The chosen plan posts as the key the checkout slice reads.' );
		$this->assertStringContainsString( 'value="' . (int) $plan->get_id() . '"', $html, 'Each plan renders as a select option.' );
		$this->assertStringContainsString( 'value="one-time"', $html );
		$this->assertStringContainsString( 'checked="checked"', $html, 'One-time purchase is preselected when allowed.' );
		$this->assertMatchesRegularExpression( '/^\s*hidden\s*$/m', $html, 'The plans block starts server-painted hidden.' );
		$this->assertStringContainsString(
			'$24.00 every 1 month',
			html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ),
			'The option text carries the plan price at the product price.'
		);
	}

	public function test_render_paints_subscribe_only_when_one_time_is_disallowed(): void {
		$this->make_plan();
		$product = $this->subscribable_product( WC_Product_Simple::class, false );

		$html = ( new PlanPicker() )->render( $product );

		$this->assertStringContainsString( 'name="_selling_plan_id"', $html );
		$this->assertStringNotContainsString( 'wc-subscriptions-lite-plan-picker__modes', $html, 'No mode radios without a one-time option.' );
		$this->assertStringNotContainsString( "disabled='disabled'", $html, 'The plan select starts enabled.' );
		$this->assertStringNotContainsString( 'disabled="disabled"', $html );
		$this->assertDoesNotMatchRegularExpression( '/^\s*hidden\s*$/m', $html, 'The plans block starts visible.' );
	}

	public function test_render_marks_variable_products(): void {
		$this->make_plan();
		$product = $this->subscribable_product( WC_Product_Variable::class );

		$html = ( new PlanPicker() )->render( $product );

		$this->assertStringContainsString( 'wc-subscriptions-lite-plan-picker--variable', $html );
		$this->assertStringContainsString( 'data-wp-init="callbacks.initVariationBridge"', $html );
	}

	/**
	 * The default renderer routes through wc_get_template(), so a theme copy
	 * of the template still wins - the `wc_get_template` filter stands in for
	 * the theme's located file here.
	 */
	public function test_the_template_stays_theme_overridable(): void {
		$this->make_plan();
		$product = $this->subscribable_product();

		$this->override_template = get_temp_dir() . 'wcsl-plan-picker-override.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the temp dir.
		file_put_contents( $this->override_template, '<p>OVERRIDDEN PICKER</p>' );

		$override = $this->override_template;
		add_filter(
			'wc_get_template',
			static function ( string $located, string $template_name ) use ( $override ): string {
				return PlanPicker::TEMPLATE === $template_name ? $override : $located;
			},
			10,
			2
		);

		$html = ( new PlanPicker() )->render( $product );

		$this->assertSame( '<p>OVERRIDDEN PICKER</p>', $html );
	}

	/**
	 * End to end through the real hook table: the bootstrap-registered picker
	 * prints for the global product when WooCommerce fires the add-to-cart
	 * form hook.
	 */
	public function test_the_picker_prints_on_the_add_to_cart_hook(): void {
		$this->make_plan();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- seeding the product global the PDP hook reads; unset below.
		$GLOBALS['product'] = $this->subscribable_product();

		ob_start();
		do_action( 'woocommerce_before_add_to_cart_button' );
		$html = (string) ob_get_clean();

		unset( $GLOBALS['product'] );

		$this->assertStringContainsString( 'name="_selling_plan_id"', $html );
	}
}
