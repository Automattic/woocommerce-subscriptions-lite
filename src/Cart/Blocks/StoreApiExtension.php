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
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Package;
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
	 * Reads Lite's selling plans by id.
	 *
	 * @var PlanRepository
	 */
	private PlanRepository $plans;

	/**
	 * Build the extension over the plan store it reads.
	 *
	 * @param PlanRepository|null $plans Plan store; defaults to a new repository.
	 */
	public function __construct( ?PlanRepository $plans = null ) {
		$this->plans = $plans ?? new PlanRepository();
	}

	/**
	 * Register the per-item and cart-level endpoint data, if core's Store API is
	 * present.
	 */
	public function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => self::DATA_NAMESPACE,
				'data_callback'   => [ $this, 'get_item_data' ],
				'schema_callback' => [ $this, 'get_item_schema' ],
				'schema_type'     => ARRAY_A,
			]
		);

		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::DATA_NAMESPACE,
				'data_callback'   => [ $this, 'get_cart_data' ],
				'schema_callback' => [ $this, 'get_cart_schema' ],
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
		$plan = $this->resolve_line_plan( $cart_item );
		if ( ! $plan instanceof Plan ) {
			return [];
		}

		$policy  = $plan->get_billing_policy();
		$product = $cart_item['data'] ?? null;
		$base    = $product instanceof WC_Product ? (float) $product->get_regular_price() : 0.0;

		return [
			'plan_id'          => (int) $plan->get_id(),
			'plan_label'       => PlanOptionFormatter::plan_label( $plan ),
			'price_cadence'    => PlanOptionFormatter::cadence_suffix( $plan ),
			'billing_period'   => $policy->get_period(),
			'billing_interval' => $policy->get_interval(),
			'recurring_amount' => $plan->calculate_price( $base, 1 ),
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
			if ( $this->resolve_line_plan( $cart_item ) instanceof Plan ) {
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
	 * Resolve the `Plan` for a cart item, or null for a one-time line or a plan
	 * that no longer resolves. Scoped to Lite's own plans.
	 *
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @return Plan|null
	 */
	private function resolve_line_plan( array $cart_item ): ?Plan {
		$plan_id = isset( $cart_item[ CartPlanHooks::CART_ITEM_KEY ] ) ? (int) $cart_item[ CartPlanHooks::CART_ITEM_KEY ] : 0;
		if ( $plan_id <= 0 ) {
			return null;
		}
		return $this->plans->find( $plan_id, Package::EXTENSION_SLUG );
	}
}
