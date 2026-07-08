<?php
/**
 * Cart-side round-trip for the PDP picker's `_selling_plan_id` selection.
 *
 * WooCommerce cart hooks carry the chosen plan from PDP form-submit through the
 * cart and into the order's line-item meta:
 *
 *  1. `woocommerce_add_to_cart_validation` - reject the add when the posted plan
 *     id does not apply to the product (URL-tamper defense). Applicability is
 *     Lite-owned, so the engine is not a backstop; the resolver is the gate.
 *  2. `woocommerce_add_cart_item_data` - attach the validated plan id to the
 *     cart item (a plain int; part of the cart_id hash, so the same product on
 *     two plans is two rows, and it survives session restore with no hydration
 *     filter).
 *  3. `woocommerce_before_calculate_totals` - price the line at the plan's
 *     cycle-1 recurring amount so cart, checkout, and the order total reflect
 *     what the picker promised.
 *  4. `woocommerce_get_item_data` - a plan-name row under the line (relayed to
 *     the Blocks cart automatically). Frequency lives on the price, not here.
 *  5. `woocommerce_cart_item_price` / `_subtotal` - append the cadence to the
 *     classic price/subtotal ("$21.60 every 1 month").
 *  6. `woocommerce_checkout_create_order_line_item` - copy `_selling_plan_id`
 *     onto the order line item; `Checkout\ContractCreationHandler` (WOOSUBS-1774)
 *     reads it. Fires for both classic and Blocks checkout.
 *
 * Mixed-cart guards are out of scope (WOOSUBS-1775): this module keeps mixed
 * carts working, it does not police them.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Cart
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Cart;

use WC_Cart;
use WC_Product;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Cart-side selling-plan plumbing. Instance state is the two lookup seams; the
 * methods are pure over `( $_POST, $cart_item )`.
 */
final class CartPlanHooks {

	/**
	 * The one carrier key: `_selling_plan_id` on cart item data AND on the order
	 * line item, so the symbol is greppable end-to-end.
	 */
	public const CART_ITEM_KEY = '_selling_plan_id';

	/**
	 * Applicability-aware resolver: the plans that apply to a product. The
	 * add-to-cart validation gate.
	 *
	 * @var ProductPlanResolver
	 */
	private $resolver;

	/**
	 * Plan finder by id, for pricing/display of a line already in the cart.
	 * Scoped to Lite's slug.
	 *
	 * @var callable(int): ?Plan
	 */
	private $plan_finder;

	/**
	 * Construct the module with its lookup seams.
	 *
	 * @param ProductPlanResolver|null    $resolver    Applicability resolver; defaults to a real one.
	 * @param (callable(int): ?Plan)|null $plan_finder Plan-by-id finder; defaults to `PlanRepository::find()` scoped to Lite.
	 */
	public function __construct( ?ProductPlanResolver $resolver = null, ?callable $plan_finder = null ) {
		$this->resolver    = $resolver ?? new ProductPlanResolver();
		$this->plan_finder = $plan_finder ?? static function ( int $plan_id ): ?Plan {
			return ( new PlanRepository() )->find( $plan_id, Package::EXTENSION_SLUG );
		};
	}

	/**
	 * Wire the cart-side hooks. Called once from the Lite bootstrap.
	 */
	public static function register(): void {
		$instance = new self();
		add_filter( 'woocommerce_add_to_cart_validation', [ $instance, 'validate_plan' ], 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', [ $instance, 'add_cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ $instance, 'apply_plan_pricing' ], 10, 1 );
		add_filter( 'woocommerce_get_item_data', [ $instance, 'get_item_data' ], 10, 2 );
		add_filter( 'woocommerce_cart_item_price', [ $instance, 'cart_item_price' ], 10, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', [ $instance, 'cart_item_subtotal' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $instance, 'copy_to_line_item_meta' ], 10, 3 );
	}

	/**
	 * Block the add when the selected plan id does not apply to this product.
	 * One-time purchases (no plan selected) always pass.
	 *
	 * `woocommerce_add_to_cart_validation` fires from the front-end form,
	 * `WC_AJAX::add_to_cart`, the Store API `CartController`, and session
	 * restore - every external entry point. The classic/AJAX paths carry the
	 * selection in `$_POST`; the Store API carries it in the `$cart_item_data`
	 * body, so both are checked. Direct programmatic `WC()->cart->add_to_cart()`
	 * does not fire this filter, which is why {@see self::add_cart_item_data()}
	 * re-checks. Trailing filter args are unused but keep the last (used)
	 * `$cart_item_data` in position.
	 *
	 * @param bool                 $passed         Whether prior validators passed.
	 * @param int                  $product_id     Parent product id (parent for variables).
	 * @param int                  $quantity       Add-to-cart quantity (unused).
	 * @param int                  $variation_id   Variation id (unused).
	 * @param array<mixed>         $variations     Variation attributes (unused).
	 * @param array<string, mixed> $cart_item_data Store API cart item data body.
	 */
	public function validate_plan( bool $passed, int $product_id, int $quantity = 0, int $variation_id = 0, array $variations = [], array $cart_item_data = [] ): bool {
		$plan_id = $this->candidate_plan_id( $cart_item_data );
		if ( null === $plan_id ) {
			return $passed;
		}
		if ( ! $this->plan_applies( $plan_id, $product_id ) ) {
			wc_add_notice(
				__( 'The selected subscription plan is not available for this product.', 'woocommerce-subscriptions-lite' ),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * Attach a validated plan id to the cart item.
	 *
	 * Any client-supplied `_selling_plan_id` is stripped first, then re-added
	 * only when it passes applicability - so a crafted Store API `cart_item_data`
	 * body (which seeds this accumulator verbatim and does not populate `$_POST`)
	 * cannot inject an unvalidated plan. This is also the defense for trusted
	 * server-side adds that bypass the validation filter.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data accumulator.
	 * @param int                  $product_id     Parent product id (plans attach to the parent).
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( array $cart_item_data, int $product_id ): array {
		$plan_id = $this->candidate_plan_id( $cart_item_data );
		unset( $cart_item_data[ self::CART_ITEM_KEY ] );
		if ( null !== $plan_id && $this->plan_applies( $plan_id, $product_id ) ) {
			$cart_item_data[ self::CART_ITEM_KEY ] = $plan_id;
		}
		return $cart_item_data;
	}

	/**
	 * Price each plan line at its cycle-1 recurring amount.
	 *
	 * Reads the base from `get_regular_price()` (never the already-mutated
	 * `get_price()`) so the discount does not compound across the multiple
	 * `woocommerce_before_calculate_totals` fires per request, and mutates only
	 * the cart's product clone (`$cart_item['data']`) so plan state never smears
	 * across rows. A plan that no longer resolves leaves the price untouched.
	 *
	 * @param WC_Cart $cart Current cart.
	 */
	public function apply_plan_pricing( WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $cart_item ) {
			$plan = $this->line_plan( $cart_item );
			if ( ! $plan instanceof Plan ) {
				continue;
			}

			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$base_price = (float) $product->get_regular_price();
			$discounted = $plan->calculate_price( $base_price, 1 );
			if ( $discounted !== $base_price ) {
				$product->set_price( (string) $discounted );
			}
		}
	}

	/**
	 * Add a plan-name row under the cart line. Frequency is not repeated here -
	 * it rides the price (see {@see self::cart_item_price()}). Relayed into the
	 * Blocks cart by core's Store API.
	 *
	 * @param array<int, array{key:string,value:string,display?:string}> $item_data Existing rows.
	 * @param array<string, mixed>                                       $cart_item Cart item row.
	 * @return array<int, array{key:string,value:string,display?:string}>
	 */
	public function get_item_data( array $item_data, array $cart_item ): array {
		$plan = $this->line_plan( $cart_item );
		if ( ! $plan instanceof Plan ) {
			return $item_data;
		}
		$item_data[] = [
			'key'     => __( 'Subscription', 'woocommerce-subscriptions-lite' ),
			'value'   => PlanOptionFormatter::plan_label( $plan ),
			'display' => '',
		];
		return $item_data;
	}

	/**
	 * Append the cadence to the classic cart/checkout unit price
	 * ("$21.60 every 1 month"). The price value is already the mutated
	 * recurring amount from {@see self::apply_plan_pricing()}.
	 *
	 * @param string               $price_html Formatted price HTML.
	 * @param array<string, mixed> $cart_item  Cart item row.
	 * @return string
	 */
	public function cart_item_price( string $price_html, array $cart_item ): string {
		return $this->append_cadence( $price_html, $cart_item );
	}

	/**
	 * Append the cadence to the classic cart/checkout line subtotal.
	 *
	 * @param string               $subtotal_html Formatted subtotal HTML.
	 * @param array<string, mixed> $cart_item     Cart item row.
	 * @return string
	 */
	public function cart_item_subtotal( string $subtotal_html, array $cart_item ): string {
		return $this->append_cadence( $subtotal_html, $cart_item );
	}

	/**
	 * Copy `_selling_plan_id` onto the order line item. Same key as the cart
	 * item; consumed by `ContractCreationHandler`.
	 *
	 * Gated on the same {@see self::line_plan()} resolution the pricing and
	 * display use: a line whose plan no longer resolves is priced as one-time,
	 * so it must not carry plan meta either (else the order would look like a
	 * subscription the shopper was not charged for). The three consumers of a
	 * line - price, order meta, and the Blocks subscription flag - stay in
	 * agreement.
	 *
	 * @param \WC_Order_Item_Product $order_item    Order line item being created.
	 * @param string                 $cart_item_key Cart item key (unused).
	 * @param array<string, mixed>   $values        Cart item row.
	 */
	public function copy_to_line_item_meta( $order_item, string $cart_item_key, array $values ): void {
		$plan = $this->line_plan( $values );
		if ( ! $plan instanceof Plan ) {
			return;
		}
		$order_item->add_meta_data( self::CART_ITEM_KEY, (int) $plan->get_id(), true );
	}

	/**
	 * Append " {cadence}" to a formatted price string for a plan line.
	 *
	 * @param string               $html      Formatted price/subtotal HTML.
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @return string
	 */
	private function append_cadence( string $html, array $cart_item ): string {
		$plan = $this->line_plan( $cart_item );
		if ( ! $plan instanceof Plan ) {
			return $html;
		}
		return $html . ' ' . esc_html( PlanOptionFormatter::cadence_suffix( $plan ) );
	}

	/**
	 * Resolve the `Plan` for a cart item, or null for a one-time line or a plan
	 * that no longer resolves.
	 *
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @return Plan|null
	 */
	private function line_plan( array $cart_item ): ?Plan {
		$plan_id = isset( $cart_item[ self::CART_ITEM_KEY ] ) ? (int) $cart_item[ self::CART_ITEM_KEY ] : 0;
		if ( $plan_id <= 0 ) {
			return null;
		}
		return ( $this->plan_finder )( $plan_id );
	}

	/**
	 * Whether `$plan_id` is among the plans that apply to `$product_id` under
	 * its Lite applicability mode - the same set the PDP picker renders.
	 *
	 * @param int $plan_id    Candidate plan id (from POST or the Store API body).
	 * @param int $product_id Parent product id being added.
	 */
	private function plan_applies( int $plan_id, int $product_id ): bool {
		foreach ( $this->resolver->get_plans_for_product( $product_id ) as $plan ) {
			if ( $plan instanceof Plan && $plan->get_id() === $plan_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The selected plan id for this add-to-cart, from `$_POST` (classic + AJAX)
	 * or, failing that, the Store API `cart_item_data` body. Null when neither
	 * carries a positive id. Whichever source it comes from, the value is only
	 * ever trusted after {@see self::plan_applies()} - this method just reads and
	 * sanitizes the candidate.
	 *
	 * @param array<string, mixed> $cart_item_data Store API cart item data body.
	 */
	private function candidate_plan_id( array $cart_item_data ): ?int {
		$posted = self::posted_plan_id();
		if ( null !== $posted ) {
			return $posted;
		}
		if ( isset( $cart_item_data[ self::CART_ITEM_KEY ] ) && is_scalar( $cart_item_data[ self::CART_ITEM_KEY ] ) ) {
			$plan_id = absint( $cart_item_data[ self::CART_ITEM_KEY ] );
			return $plan_id > 0 ? $plan_id : null;
		}
		return null;
	}

	/**
	 * Read `_selling_plan_id` from the add-to-cart POST. Null when absent (the
	 * one-time radio leaves the `<select>` disabled, so the field is not sent)
	 * or non-positive. The `is_string` guard rejects array-shaped tampers
	 * (`_selling_plan_id[]=99`), which `absint()` would otherwise coerce to 1.
	 *
	 * No nonce: WooCommerce's add-to-cart form carries none by design; the
	 * validation hook defends tampered values.
	 */
	private static function posted_plan_id(): ?int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Woo add-to-cart carries no nonce by design; absint() sanitizes.
		$raw = isset( $_POST[ self::CART_ITEM_KEY ] ) && is_string( $_POST[ self::CART_ITEM_KEY ] )
			? wp_unslash( $_POST[ self::CART_ITEM_KEY ] )
			: '';
		// phpcs:enable
		if ( '' === $raw ) {
			return null;
		}
		$plan_id = absint( $raw );
		return $plan_id > 0 ? $plan_id : null;
	}
}
