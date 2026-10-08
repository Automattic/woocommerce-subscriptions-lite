<?php
/**
 * Store API endpoint data for subscription cart lines (Cart/Checkout Blocks).
 *
 * A thin, structured extension of WooCommerce *core's* Store API - the engine
 * exposes no Store API; the Blocks are consumer UI. Per-item data drives the
 * checkout filters (frequency suffix); a cart-level `has_subscriptions` flag
 * drives the "Total due today" relabel. The structured fields are the
 * forward-compatible contract: Premium adds trial/fee/divergence fields and a
 * recurring-totals panel additively on top of this, without Lite carrying any
 * recurring-carts machinery.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Cart\Blocks
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Cart\Blocks;

use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Lite's per-item and cart-level Store API data.
 */
final class StoreApiExtension {

	/**
	 * Extension namespace - the key the front-end reads under
	 * `cartItem.extensions[...]` / `cart.extensions[...]`.
	 */
	private const DATA_NAMESPACE = Package::EXTENSION_SLUG;

	/**
	 * Resolves a line's plan with the rule cart pricing uses.
	 *
	 * @var ProductPlanResolver
	 */
	private ProductPlanResolver $resolver;

	/**
	 * Build the extension over the plan resolver.
	 */
	public function __construct() {
		$this->resolver = new ProductPlanResolver();
	}

	/**
	 * Register the per-item and cart-level endpoint data, if core's Store API is
	 * present.
	 */
	public static function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		$instance = new self();

		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => self::DATA_NAMESPACE,
				'data_callback'   => [ $instance, 'get_item_data' ],
				'schema_callback' => [ $instance, 'get_item_schema' ],
				'schema_type'     => ARRAY_A,
			]
		);

		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::DATA_NAMESPACE,
				'data_callback'   => [ $instance, 'get_cart_data' ],
				'schema_callback' => [ $instance, 'get_cart_schema' ],
				'schema_type'     => ARRAY_A,
			]
		);
	}

	/**
	 * Build the per-item payload for a plan line; `[]` for one-time lines.
	 *
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @return array<string, mixed>
	 */
	public function get_item_data( array $cart_item ): array {
		$plan  = $this->resolve_line_plan( $cart_item );
		$terms = null === $plan ? null : BillingTerms::from_plan( $plan );
		if ( null === $plan || null === $terms ) {
			return [];
		}

		$product = $cart_item['data'] ?? null;
		$base    = $product instanceof WC_Product ? (float) $product->get_regular_price() : 0.0;

		return [
			'plan_id'          => (int) $plan->get_id(),
			'plan_label'       => PlanOptionFormatter::plan_label( $plan ),
			'price_cadence'    => PlanOptionFormatter::cadence_suffix( $plan ),
			'billing_period'   => $terms->get_period(),
			'billing_interval' => $terms->get_interval(),
			'recurring_amount' => PriceCalculator::for_plan( $plan )->unit_price( $base, 1 ),
		];
	}

	/**
	 * Describe the per-item payload for the Store API schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return [
			'plan_id'          => [
				'description' => __( 'Selling plan id applied to this line.', 'woocommerce-subscriptions-lite' ),
				'type'        => 'integer',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'plan_label'       => [
				'description' => __( 'Display name of the applied selling plan.', 'woocommerce-subscriptions-lite' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'price_cadence'    => [
				'description' => __( 'Cadence suffix for the line price (e.g. "/ month").', 'woocommerce-subscriptions-lite' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'billing_period'   => [
				'description' => __( 'Billing period (day, week, month, year).', 'woocommerce-subscriptions-lite' ),
				'type'        => 'string',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'billing_interval' => [
				'description' => __( 'Number of periods between renewals.', 'woocommerce-subscriptions-lite' ),
				'type'        => 'integer',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			'recurring_amount' => [
				'description' => __( 'Recurring charge per billing period.', 'woocommerce-subscriptions-lite' ),
				'type'        => 'number',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * Build the cart-level payload: whether any line is a subscription. The
	 * "Total due today" relabel trigger. Not a recurring-totals array.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cart_data(): array {
		$has  = false;
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : [];
		foreach ( $cart as $cart_item ) {
			// Resolve the plan (not just the raw meta) so the flag agrees with
			// pricing: a line whose plan no longer resolves is priced as one-time
			// and must not read as a subscription here.
			if ( $this->resolve_line_plan( $cart_item ) instanceof PlanView ) {
				$has = true;
				break;
			}
		}
		return [ 'has_subscriptions' => $has ];
	}

	/**
	 * Describe the cart-level payload for the Store API schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cart_schema(): array {
		return [
			'has_subscriptions' => [
				'description' => __( 'Whether the cart contains at least one subscription line.', 'woocommerce-subscriptions-lite' ),
				'type'        => 'boolean',
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * Resolve the plan for a cart item ({@see ProductPlanResolver::get_line_plan()}),
	 * or null for a one-time line.
	 *
	 * @param array<string, mixed> $cart_item Cart item row.
	 */
	private function resolve_line_plan( array $cart_item ): ?PlanView {
		$plan_id = isset( $cart_item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] ) ? (int) $cart_item[ CartPlanHooks::SELLING_PLAN_ID_KEY ] : 0;

		return $plan_id > 0 ? $this->resolver->get_line_plan( $plan_id ) : null;
	}
}
