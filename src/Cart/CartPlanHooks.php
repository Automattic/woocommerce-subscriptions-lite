<?php
/**
 * Cart-side round-trip for the PDP picker's `_wcsl_selling_plan_id` selection.
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
 *  4. `woocommerce_get_item_data` - a plan-label row under the line (relayed to
 *     the Blocks cart automatically). Frequency lives on the price, not here.
 *  5. `woocommerce_cart_item_price` / `_subtotal` - append the cadence to the
 *     classic price/subtotal ("$21.60 / month").
 *  6. `woocommerce_checkout_create_order_line_item` - copy `_wcsl_selling_plan_id`
 *     onto the order line item; `Checkout\ContractCreationHandler`
 *     reads it. Fires for both classic and Blocks checkout.
 *  7. `woocommerce_check_cart_items` / `woocommerce_store_api_validate_cart_item` -
 *     refuse checkout while a line's plan could not be read for its pricing.
 *
 * A failed plan read fails closed where money is decided: the add is refused,
 * checkout is refused or stopped, and the shopper never sees the database error.
 *
 * Mixed-cart guards are out of scope: this module keeps mixed
 * carts working, it does not police them.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Cart
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Cart;

use Exception;
use RuntimeException;
use WC_Cart;
use WC_Product;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductPlanResolver;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Cart-side selling-plan plumbing. Instance state is the plan resolver and the
 * lines whose plan could not be read for their pricing in this request.
 */
final class CartPlanHooks {

	/**
	 * The one carrier key: `_wcsl_selling_plan_id` on cart item data AND on the order
	 * line item, so the symbol is greppable end-to-end.
	 */
	public const SELLING_PLAN_ID_KEY = '_wcsl_selling_plan_id';

	/**
	 * Applicability-aware resolver: the plans that apply to a product. The
	 * add-to-cart validation gate.
	 *
	 * @var ProductPlanResolver
	 */
	private $resolver;

	/**
	 * Keys of the cart items whose plan could not be read while pricing them in this request:
	 * their price may not be the plan's, so checkout is refused.
	 *
	 * @var array<string, true>
	 */
	private $unpriced_cart_items = [];

	/**
	 * Construct the module with the applicability resolver it gates adds through.
	 *
	 * @param ProductPlanResolver|null $resolver Applicability resolver; defaults to a real one.
	 */
	public function __construct( ?ProductPlanResolver $resolver = null ) {
		$this->resolver = $resolver ?? new ProductPlanResolver();
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
		add_action( 'woocommerce_check_cart_items', [ $instance, 'check_cart_items' ] );
		add_action( 'woocommerce_store_api_validate_cart_item', [ $instance, 'validate_cart_item' ], 10, 2 );
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
		$plan_id = $this->get_candidate_plan_id_for_cart_item( $cart_item_data );
		if ( null === $plan_id ) {
			return $passed;
		}
		try {
			$applicable = $this->resolver->is_plan_applicable_to_product( $plan_id, $product_id );
		} catch ( RuntimeException $e ) {
			self::log_read_failure( sprintf( 'failed to read the plans of product %d on add to cart', $product_id ), $e );
			wc_add_notice( self::get_add_failed_message(), 'error' );
			return false;
		}
		if ( ! $applicable ) {
			wc_add_notice(
				__( 'The selected subscription plan is not available for this product.', 'woocommerce-subscriptions-lite' ),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * Validate the selected plan and attach it to the cart item. This method -
	 * not `validate_plan()` - is what makes the stored key trustworthy, so it
	 * cannot assume the input is already clean.
	 *
	 * `validate_plan()` only rejects (with a notice) on the customer-facing add
	 * paths and never modifies this accumulator, and it does not fire at all on a
	 * direct `WC()->cart->add_to_cart()`. So any client-supplied
	 * `_wcsl_selling_plan_id` - which the Store API seeds into this accumulator from
	 * the request body verbatim - is dropped first and re-added only when it
	 * applies to the product. The stored value is one validated here, on every
	 * add path.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data accumulator.
	 * @param int                  $product_id     Parent product id (plans attach to the parent).
	 * @return array<string, mixed>
	 * @throws Exception When the plans cannot be read: `WC_Cart::add_to_cart()` shows the message
	 *                   and adds nothing, rather than adding the product as a one-time purchase.
	 */
	public function add_cart_item_data( array $cart_item_data, int $product_id ): array {
		$plan_id = $this->get_candidate_plan_id_for_cart_item( $cart_item_data );
		unset( $cart_item_data[ self::SELLING_PLAN_ID_KEY ] );
		if ( null === $plan_id ) {
			return $cart_item_data;
		}
		try {
			$applicable = $this->resolver->is_plan_applicable_to_product( $plan_id, $product_id );
		} catch ( RuntimeException $e ) {
			self::log_read_failure( sprintf( 'failed to read the plans of product %d on add to cart', $product_id ), $e );
			throw new Exception( esc_html( self::get_add_failed_message() ) );
		}
		if ( $applicable ) {
			$cart_item_data[ self::SELLING_PLAN_ID_KEY ] = $plan_id;
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
	 * across rows. A plan that no longer resolves leaves the price untouched; one that
	 * cannot be read does too, without throwing, and the line is recorded so checkout is
	 * refused. A later pass that reads the plan clears the record. The display filters
	 * still read the plan themselves and throw while it cannot be read.
	 *
	 * @param WC_Cart $cart Current cart.
	 */
	public function apply_plan_pricing( WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			try {
				$plan = $this->resolve_line_plan( $cart_item );
			} catch ( RuntimeException $e ) {
				self::log_read_failure( sprintf( 'failed to read the plan of cart item %s for its price', $cart_item_key ), $e );
				$this->unpriced_cart_items[ (string) $cart_item_key ] = true;
				continue;
			}
			unset( $this->unpriced_cart_items[ (string) $cart_item_key ] );
			if ( ! $plan instanceof PlanView ) {
				continue;
			}

			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$base_price = (float) $product->get_regular_price();
			$discounted = PriceCalculator::for_plan( $plan )->unit_price( $base_price, 1 );
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
		$plan = $this->resolve_line_plan( $cart_item );
		if ( ! $plan instanceof PlanView ) {
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
	 * Copy `_wcsl_selling_plan_id` onto the order line item. Same key as the cart
	 * item; consumed by `ContractCreationHandler`.
	 *
	 * Gated on the same {@see self::resolve_line_plan()} resolution the pricing and
	 * display use: a line whose plan no longer resolves is priced as one-time,
	 * so it must not carry plan meta either (else the order would look like a
	 * subscription the shopper was not charged for). The three consumers of a
	 * line - price, order meta, and the Blocks subscription flag - stay in
	 * agreement.
	 *
	 * @param \WC_Order_Item_Product $order_item    Order line item being created.
	 * @param string                 $cart_item_key Cart item key, for the log.
	 * @param array<string, mixed>   $values        Cart item row.
	 * @throws Exception When the plan cannot be read: classic and Store API checkout stop and show
	 *                   the message, so nothing is charged for an order without its plan.
	 */
	public function copy_to_line_item_meta( $order_item, string $cart_item_key, array $values ): void {
		try {
			$plan = $this->resolve_line_plan( $values );
		} catch ( RuntimeException $e ) {
			self::log_read_failure( sprintf( 'failed to read the plan of cart item %s at checkout', $cart_item_key ), $e );
			throw new Exception( esc_html( self::get_checkout_failed_message() ) );
		}
		if ( ! $plan instanceof PlanView ) {
			return;
		}
		$order_item->add_meta_data( self::SELLING_PLAN_ID_KEY, (int) $plan->get_id(), true );
	}

	/**
	 * Refuse classic checkout submission while a line's plan could not be read for its pricing
	 * in this request: checkout calculates the totals before this check. The cart and checkout
	 * pages run this check before their totals, so their render may show no notice.
	 */
	public function check_cart_items(): void {
		$cart = WC()->cart;
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( array_keys( $cart->get_cart() ) as $cart_item_key ) {
			if ( isset( $this->unpriced_cart_items[ (string) $cart_item_key ] ) ) {
				wc_add_notice( self::get_checkout_failed_message(), 'error' );
				return;
			}
		}
	}

	/**
	 * Refuse Store API (block) checkout for a line whose plan could not be read for its pricing
	 * in this request.
	 *
	 * @param WC_Product           $product   Product of the cart item (unused).
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @throws RouteException When the line's plan could not be read for its pricing.
	 */
	public function validate_cart_item( $product, array $cart_item = [] ): void {
		$cart_item_key = (string) ( $cart_item['key'] ?? '' );
		if ( isset( $this->unpriced_cart_items[ $cart_item_key ] ) ) {
			throw new RouteException( 'woocommerce_subscriptions_lite_plan_read_failed', esc_html( self::get_checkout_failed_message() ), 409 );
		}
	}

	/**
	 * Append " {cadence}" to a formatted price string for a plan line.
	 *
	 * @param string               $html      Formatted price/subtotal HTML.
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @return string
	 */
	private function append_cadence( string $html, array $cart_item ): string {
		$plan = $this->resolve_line_plan( $cart_item );
		if ( ! $plan instanceof PlanView ) {
			return $html;
		}
		// Wrap the suffix so it is styleable and does not inherit stray styling
		// from a preceding closed price element.
		return $html . ' <span class="wc-subscriptions-lite-cadence">' . esc_html( PlanOptionFormatter::cadence_suffix( $plan ) ) . '</span>';
	}

	/**
	 * The notice for an add to cart refused because the plans could not be read.
	 */
	private static function get_add_failed_message(): string {
		return __( 'The subscription could not be added right now. Please try again.', 'woocommerce-subscriptions-lite' );
	}

	/**
	 * The error for a checkout refused because a line's plan could not be read.
	 */
	private static function get_checkout_failed_message(): string {
		return __( 'Your subscription could not be processed right now. Please try again.', 'woocommerce-subscriptions-lite' );
	}

	/**
	 * Log a failed plan read with the engine message, which customer-facing text never shows.
	 *
	 * @param string           $context What failed, without the class prefix.
	 * @param RuntimeException $error   The read failure.
	 */
	private static function log_read_failure( string $context, RuntimeException $error ): void {
		wc_get_logger()->error( sprintf( 'CartPlanHooks: %s: %s', $context, $error->getMessage() ), [ 'source' => Package::EXTENSION_SLUG ] );
	}

	/**
	 * Resolve the plan for a cart item ({@see ProductPlanResolver::get_line_plan()}),
	 * or null for a one-time line.
	 *
	 * @param array<string, mixed> $cart_item Cart item row.
	 * @throws RuntimeException When the plan cannot be read.
	 */
	private function resolve_line_plan( array $cart_item ): ?PlanView {
		$plan_id = isset( $cart_item[ self::SELLING_PLAN_ID_KEY ] ) ? (int) $cart_item[ self::SELLING_PLAN_ID_KEY ] : 0;

		return $plan_id > 0 ? $this->resolver->get_line_plan( $plan_id ) : null;
	}

	/**
	 * The selected plan id for this add-to-cart, from `$_POST` (classic + AJAX)
	 * or, failing that, the Store API `cart_item_data` body. Null when neither
	 * carries a positive id. Whichever source it comes from, the value is only
	 * ever trusted after `ProductPlanResolver::is_plan_applicable_to_product()` - this
	 * method just reads and sanitizes the candidate.
	 *
	 * @param array<string, mixed> $cart_item_data Store API cart item data body.
	 */
	private function get_candidate_plan_id_for_cart_item( array $cart_item_data ): ?int {
		$posted = self::get_posted_plan_id();
		if ( null !== $posted ) {
			return $posted;
		}
		if ( isset( $cart_item_data[ self::SELLING_PLAN_ID_KEY ] ) && is_scalar( $cart_item_data[ self::SELLING_PLAN_ID_KEY ] ) ) {
			$plan_id = absint( $cart_item_data[ self::SELLING_PLAN_ID_KEY ] );
			return $plan_id > 0 ? $plan_id : null;
		}
		return null;
	}

	/**
	 * Read `_wcsl_selling_plan_id` from the add-to-cart POST. Null when absent (the
	 * one-time radio leaves the `<select>` disabled, so the field is not sent)
	 * or non-positive. The `is_string` guard rejects array-shaped tampers
	 * (`_wcsl_selling_plan_id[]=99`), which `absint()` would otherwise coerce to 1.
	 *
	 * No nonce: WooCommerce's add-to-cart form carries none by design; the
	 * validation hook defends tampered values.
	 */
	private static function get_posted_plan_id(): ?int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Woo add-to-cart carries no nonce by design; absint() sanitizes.
		$raw = isset( $_POST[ self::SELLING_PLAN_ID_KEY ] ) && is_string( $_POST[ self::SELLING_PLAN_ID_KEY ] )
			? wp_unslash( $_POST[ self::SELLING_PLAN_ID_KEY ] )
			: '';
		// phpcs:enable
		if ( '' === $raw ) {
			return null;
		}
		$plan_id = absint( $raw );
		return $plan_id > 0 ? $plan_id : null;
	}
}
