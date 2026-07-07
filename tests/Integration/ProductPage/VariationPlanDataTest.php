<?php
/**
 * Integration tests for the variation-payload plan data filter.
 *
 * The payload paths run END TO END: real variable products and variations,
 * plans resolved through Lite's plan resolver from real applicability meta,
 * and the bootstrap-registered filter dispatched through the real hook table.
 * The memoization and no-id edge cases observe and shape the resolution
 * through Lite's product-plans filter.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\ProductPage;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\PricingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\VariationPlanData;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use ReflectionProperty;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\ProductPage\VariationPlanData
 */
final class VariationPlanDataTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();

		// The per-request plans cache is per-suite-process here; reset it so a
		// parent product id from one test can never satisfy another test's
		// resolution.
		$cache = new ReflectionProperty( VariationPlanData::class, 'plans_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, [] );
	}

	public function tear_down(): void {
		remove_all_filters( ProductPlanResolver::PRODUCT_PLANS_FILTER );

		parent::tear_down();
	}

	/**
	 * Persist a Lite-owned monthly plan with a 10% discount, or without a
	 * pricing policy when $discount is false.
	 *
	 * @param bool $discount Whether the plan carries the 10% discount.
	 */
	private function discounted_plan( bool $discount = true ): Plan {
		$plan = Plan::create(
			[
				'name'           => 'Monthly',
				'billing_policy' => new BillingPolicy( 'month', 1, null, null, null ),
				'pricing_policy' => $discount ? new PricingPolicy(
					[
						[
							'type'  => 'percentage',
							'value' => 10.0,
						],
					],
					[]
				) : null,
				'extension_slug' => Package::EXTENSION_SLUG,
			]
		);
		( new PlanRepository() )->insert( $plan );

		return $plan;
	}

	/**
	 * Create a saved variable product marked as selling on all plans.
	 */
	private function subscribable_parent(): WC_Product_Variable {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Coffee Box' );
		$parent->save();

		( new ApplicabilityStore() )->set(
			$parent->get_id(),
			new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL, [], true )
		);

		return $parent;
	}

	/**
	 * Create a saved variation of a parent at the given price.
	 *
	 * @param WC_Product_Variable $parent Parent variable product.
	 * @param string              $price  Variation price.
	 */
	private function variation_of( WC_Product_Variable $parent, string $price ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( $price );
		$variation->save();

		return $variation;
	}

	/**
	 * Dispatch a variation payload through the real hook table (the
	 * bootstrap-registered filter instance with its default facade wiring).
	 *
	 * @param array<string, mixed> $data      Payload going in.
	 * @param WC_Product_Variable  $parent    Parent variable product.
	 * @param WC_Product_Variation $variation Variation being serialized.
	 * @return array<string, mixed> Filtered payload.
	 */
	private function dispatch_payload( array $data, WC_Product_Variable $parent, WC_Product_Variation $variation ): array {
		return (array) apply_filters( 'woocommerce_available_variation', $data, $parent, $variation );
	}

	public function test_payload_is_untouched_when_no_plans_resolve(): void {
		$this->discounted_plan();

		// A real plan exists storewide, but the parent keeps the default
		// applicability (disable), so nothing resolves for it.
		$parent = new WC_Product_Variable();
		$parent->set_name( 'One-time Box' );
		$parent->save();
		$variation = $this->variation_of( $parent, '30.00' );

		$data   = [ 'variation_id' => $variation->get_id() ];
		$result = $this->dispatch_payload( $data, $parent, $variation );

		$this->assertSame( $data, $result );
		$this->assertArrayNotHasKey( 'subscriptions_lite', $result );
	}

	public function test_option_html_is_keyed_by_plan_id_and_priced_per_variation(): void {
		$plan      = $this->discounted_plan();
		$parent    = $this->subscribable_parent();
		$expensive = $this->dispatch_payload( [], $parent, $this->variation_of( $parent, '30.00' ) );
		$cheap     = $this->dispatch_payload( [], $parent, $this->variation_of( $parent, '20.00' ) );

		$plan_id = (int) $plan->get_id();
		$this->assertSame( [ $plan_id ], array_keys( $expensive['subscriptions_lite']['option_html'] ) );
		$this->assertSame(
			'$27.00 every 1 month (10% off)',
			html_entity_decode( wp_strip_all_tags( $expensive['subscriptions_lite']['option_html'][ $plan_id ] ), ENT_QUOTES )
		);
		$this->assertSame(
			'$18.00 every 1 month (10% off)',
			html_entity_decode( wp_strip_all_tags( $cheap['subscriptions_lite']['option_html'][ $plan_id ] ), ENT_QUOTES )
		);
	}

	/**
	 * A plan appended through the resolver's filter without a persisted id
	 * cannot be keyed into the option map and is skipped.
	 */
	public function test_plans_without_an_id_are_skipped(): void {
		$saved   = $this->discounted_plan();
		$unsaved = Plan::create(
			[
				'name'           => 'Unsaved',
				'billing_policy' => new BillingPolicy( 'month', 1, null, null, null ),
				'extension_slug' => Package::EXTENSION_SLUG,
			]
		);

		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans ) use ( $unsaved ): array {
				$plans[] = $unsaved;

				return $plans;
			}
		);

		$parent = $this->subscribable_parent();
		$result = ( new VariationPlanData() )->filter_available_variation( [], $parent, $this->variation_of( $parent, '10.00' ) );

		$this->assertSame( [ (int) $saved->get_id() ], array_keys( $result['subscriptions_lite']['option_html'] ) );
	}

	/**
	 * The resolver's filter fires once per resolution, so counting its calls
	 * observes the memoization: one resolution per parent product.
	 */
	public function test_plan_resolution_is_memoized_per_parent_product(): void {
		$this->discounted_plan();
		$resolved_ids = [];
		add_filter(
			ProductPlanResolver::PRODUCT_PLANS_FILTER,
			static function ( array $plans, int $product_id ) use ( &$resolved_ids ): array {
				$resolved_ids[] = $product_id;

				return $plans;
			},
			10,
			2
		);

		$filter   = new VariationPlanData();
		$parent_a = $this->subscribable_parent();
		$parent_b = $this->subscribable_parent();
		$filter->filter_available_variation( [], $parent_a, $this->variation_of( $parent_a, '30.00' ) );
		$filter->filter_available_variation( [], $parent_a, $this->variation_of( $parent_a, '20.00' ) );
		$filter->filter_available_variation( [], $parent_b, $this->variation_of( $parent_b, '10.00' ) );

		$this->assertSame(
			[ $parent_a->get_id(), $parent_b->get_id() ],
			$resolved_ids,
			'One resolution per parent product, not per variation.'
		);
	}

	/**
	 * The view module injects option_html via innerHTML, so the payload
	 * strings pass through wp_kses_post - markup a filtered wc_price() smuggles
	 * in is reduced to its safe subset.
	 */
	public function test_option_html_passes_through_kses(): void {
		$this->discounted_plan( false );
		$parent    = $this->subscribable_parent();
		$variation = $this->variation_of( $parent, '30.00' );

		add_filter(
			'wc_price',
			static function (): string {
				return '<script>alert(1)</script><span class="amount">$30.00</span>';
			}
		);

		$result = $this->dispatch_payload( [], $parent, $variation );

		$option_html = implode( '', $result['subscriptions_lite']['option_html'] );
		$this->assertStringNotContainsString( '<script', $option_html, 'Script tags never reach the innerHTML sink.' );
		$this->assertStringContainsString( '<span class="amount">$30.00</span>', $option_html, 'The allowed price markup survives.' );
	}
}
