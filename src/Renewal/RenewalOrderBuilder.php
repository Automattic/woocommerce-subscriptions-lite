<?php
/**
 * RenewalOrderBuilder - builds the order that bills one renewal cycle of a Lite contract.
 *
 * The order carries the contract's recurring lines, addresses and payment method, the
 * gateway data of the origin order (its meta minus per-order keys, and its payment
 * tokens) so the gateway can charge it off-session, and BOGO bonus units for the cycle.
 * The total is the cycle's expected total. The order is returned as a saved draft: the
 * caller links it to the cycle first and only then makes it a live pending order.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Renewal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Renewal;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsLite\Contracts\AddressFields;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ContractPlans;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PriceCalculator;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PricingTerms;
use Throwable;
use WC_Meta_Data;
use WC_Order;
use WC_Order_Item_Product;
use WC_Payment_Token;
use WC_Payment_Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal order builder.
 */
final class RenewalOrderBuilder {

	/**
	 * `created_via` of Lite renewal orders: how settlement recognises its own orders.
	 */
	public const CREATED_VIA = 'woocommerce_subscriptions_lite_renewal';

	/**
	 * Order meta holding the renewed contract id, for display.
	 */
	public const CONTRACT_META = '_wcsl_renewal_contract_id';

	/**
	 * Origin-order meta keys that belong to that one order and are never copied.
	 */
	private const EXCLUDED_META_KEYS = [
		'_paid_date',
		'_date_paid',
		'_completed_date',
		'_date_completed',
		'_edit_last',
		'_edit_lock',
		'_order_key',
		'_wc_points_earned',
		'_transaction_id',
		'_payment_method',
		'_payment_method_title',
		'_created_via',
		'_order_stock_reduced',
		'_new_order_email_sent',
		'_recorded_sales',
		'_recorded_coupon_usage_counts',
		'_download_permissions_granted',
		'_cart_hash',
	];

	/**
	 * Origin-order meta key prefixes that are never copied (Lite's own markers, subscription
	 * bookkeeping, order attribution).
	 */
	private const EXCLUDED_META_PREFIXES = [ '_wcsl_', '_subscription_', '_wc_order_attribution_' ];

	private const LOG_SOURCE = 'woocommerce-subscriptions-lite';

	/**
	 * Build the draft renewal order for `$cycle` of `$contract`.
	 *
	 * @param ContractView $contract The contract being renewed (read with its items and addresses).
	 * @param CycleView    $cycle    The cycle the order bills.
	 * @return WC_Order|null The saved `checkout-draft` order; null when it could not be built (logged).
	 */
	public function build( ContractView $contract, CycleView $cycle ): ?WC_Order {
		$contract_id = $contract->get_id();
		$order       = wc_create_order(
			[
				'customer_id' => (int) $contract->get_customer_id(),
				'status'      => OrderStatus::CHECKOUT_DRAFT,
				'created_via' => self::CREATED_VIA,
			]
		);

		if ( is_wp_error( $order ) ) {
			$this->log_error( sprintf( 'wc_create_order() failed for contract %d: %s', $contract_id, $order->get_error_message() ), $contract_id );
			return null;
		}

		try {
			$order->set_currency( $cycle->get_currency() );
			$order->set_payment_method( (string) $contract->get_payment_method() );
			$order->set_payment_method_title( (string) $contract->get_payment_method_title() );
			$this->set_addresses( $order, $contract );
			$this->add_lines( $order, $contract, $cycle );
			$order->set_total( $cycle->get_expected_total() );
			$this->copy_gateway_data( $order, $contract );
			$order->update_meta_data( self::CONTRACT_META, (string) $contract_id );
			$order->save();
		} catch ( Throwable $e ) {
			$this->log_error( sprintf( 'cannot build the renewal order for contract %d (a product may have been deleted): %s', $contract_id, $e->getMessage() ), $contract_id );
			$order->delete( true );
			return null;
		}

		return $order;
	}

	/**
	 * Copy the contract's billing and shipping addresses onto the order.
	 *
	 * @param WC_Order     $order    The renewal order.
	 * @param ContractView $contract The contract.
	 */
	private function set_addresses( WC_Order $order, ContractView $contract ): void {
		$addresses = $contract->get_addresses() ?? [];
		$fields    = array_flip( AddressFields::FIELDS );

		if ( isset( $addresses['billing'] ) ) {
			$order->set_billing_address( array_filter( array_intersect_key( $addresses['billing'], $fields ), 'is_string' ) );
		}
		if ( isset( $addresses['shipping'] ) ) {
			$order->set_shipping_address( array_filter( array_intersect_key( $addresses['shipping'], $fields ), 'is_string' ) );
		}
	}

	/**
	 * Add one product line per contract item, with the cycle's BOGO bonus units added to
	 * the quantity (money-neutral: subtotal and total stay the contract's).
	 *
	 * @param WC_Order     $order    The renewal order.
	 * @param ContractView $contract The contract.
	 * @param CycleView    $cycle    The cycle being billed.
	 */
	private function add_lines( WC_Order $order, ContractView $contract, CycleView $cycle ): void {
		$terms      = PricingTerms::from_plan( ContractPlans::for_contract( $contract ) );
		$calculator = $terms->has_type( PricingTerms::TYPE_BOGO ) ? new PriceCalculator( $terms ) : null;

		foreach ( $contract->get_items() ?? [] as $item ) {
			$quantity = self::item_string( $item, 'quantity' );
			$bonus    = null === $calculator ? 0.0 : $calculator->bonus_quantity( (float) $quantity, (int) $cycle->get_count() );

			$line = new WC_Order_Item_Product();
			$line->set_name( self::item_string( $item, 'item_name' ) );
			$line->set_product_id( self::item_int( $item, 'product_id' ) );
			$line->set_variation_id( self::item_int( $item, 'variation_id' ) );
			// The stored quantity passes through as is (zero and fractional are valid); the
			// woocommerce_stock_amount filter decides precision.
			$line->set_quantity( $bonus > 0 ? (float) $quantity + $bonus : $quantity ); // @phpstan-ignore argument.type (docblock-only int; fractional quantities are valid)
			$line->set_subtotal( self::item_string( $item, 'subtotal' ) );
			$line->set_total( self::item_string( $item, 'total' ) );
			$order->add_item( $line );
		}
	}

	/**
	 * Copy the origin order's gateway data: its meta except per-order keys, and its payment
	 * tokens; then the contract's own token when the origin did not carry it.
	 *
	 * @param WC_Order     $order    The renewal order.
	 * @param ContractView $contract The contract.
	 */
	private function copy_gateway_data( WC_Order $order, ContractView $contract ): void {
		$token_ids       = [];
		$origin_order_id = $contract->get_origin_order_id();
		$origin_order    = null === $origin_order_id ? false : wc_get_order( $origin_order_id );

		if ( $origin_order instanceof WC_Order ) {
			foreach ( $origin_order->get_meta_data() as $meta ) {
				if ( $meta instanceof WC_Meta_Data && self::is_copied_meta_key( (string) $meta->key ) ) {
					$order->add_meta_data( (string) $meta->key, $meta->value );
				}
			}
			$token_ids = array_map( 'intval', $origin_order->get_payment_tokens() );
		}

		$contract_token_id = $contract->get_payment_token_id();
		if ( null !== $contract_token_id && ! in_array( $contract_token_id, $token_ids, true ) ) {
			$token_ids[] = $contract_token_id;
		}

		foreach ( $token_ids as $token_id ) {
			$token = WC_Payment_Tokens::get( $token_id );
			if ( $token instanceof WC_Payment_Token ) {
				$order->add_payment_token( $token );
			}
		}
	}

	/**
	 * Whether an origin-order meta key is copied onto renewal orders.
	 *
	 * @param string $key Meta key.
	 */
	private static function is_copied_meta_key( string $key ): bool {
		if ( in_array( $key, self::EXCLUDED_META_KEYS, true ) ) {
			return false;
		}

		foreach ( self::EXCLUDED_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read a contract-item field as a string, defaulting to empty when absent or non-scalar.
	 *
	 * @param array<string, mixed> $item The contract item row.
	 * @param string               $key  Field key.
	 */
	private static function item_string( array $item, string $key ): string {
		$value = $item[ $key ] ?? null;
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Read a contract-item field as an int, defaulting to 0 when absent or non-numeric.
	 *
	 * @param array<string, mixed> $item The contract item row.
	 * @param string               $key  Field key.
	 */
	private static function item_int( array $item, string $key ): int {
		$value = $item[ $key ] ?? null;
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Log an error under the Lite source.
	 *
	 * @param string $message     Message, without the class prefix.
	 * @param int    $contract_id Contract id.
	 */
	private function log_error( string $message, int $contract_id ): void {
		wc_get_logger()->error(
			'RenewalOrderBuilder: ' . $message,
			[
				'source'      => self::LOG_SOURCE,
				'contract_id' => $contract_id,
			]
		);
	}
}
