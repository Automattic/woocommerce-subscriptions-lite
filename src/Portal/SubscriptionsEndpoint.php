<?php
/**
 * SubscriptionsEndpoint - the My Account subscriptions list (Slice 0).
 *
 * Registers a `/my-account/subscriptions/` page that lists the logged-in
 * customer's contracts and, for cancelable ones, renders the authenticated
 * cancel form handled by {@see CancelHandler}. This is the minimal portal
 * surface the walking skeleton needs: enough to reach the cancel action. The
 * full round-1 portal (detail page, reactivate, pagination, rich formatting) is
 * a later widening step.
 *
 * No Store API and no `ContractQuery` (which the engine does not expose yet):
 * the customer's contracts are discovered from the public surface by reading the
 * order <-> contract linkage meta off the customer's own orders, then loading
 * each contract by id. Lite reads only the engine's public surface and owns no
 * schema.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Portal
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Portal;

use WC_Order;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\OrderLinkage;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository;

defined( 'ABSPATH' ) || exit;

/**
 * My Account subscriptions list endpoint.
 */
final class SubscriptionsEndpoint {

	/**
	 * The My Account rewrite-endpoint slug.
	 */
	const ENDPOINT = 'subscriptions';

	/**
	 * Wire the endpoint into WooCommerce's My Account framework.
	 *
	 * Idempotent; called once from the package bootstrap.
	 */
	public static function register(): void {
		$instance = new self();
		add_action( 'init', [ $instance, 'add_endpoint' ] );
		add_filter( 'woocommerce_get_query_vars', [ $instance, 'add_query_var' ] );
		add_filter( 'woocommerce_account_menu_items', [ $instance, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $instance, 'render' ] );
	}

	/**
	 * Register the rewrite endpoint on every load (WC core does the same for its
	 * own endpoints). The wrapper plugin's activation hook flushes rules so the
	 * slug resolves on first visit.
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	/**
	 * Add the slug to WooCommerce's query-vars so the rewrite parser recognises it.
	 *
	 * @param array<string, string> $vars Existing WC query vars.
	 * @return array<string, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Insert a `Subscriptions` link into the My Account menu, after Orders.
	 *
	 * @param array<string, string> $items Existing menu items.
	 * @return array<string, string>
	 */
	public function add_menu_item( array $items ): array {
		$new_items = [];
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ] = __( 'Subscriptions', 'woocommerce-subscriptions-lite' );
			}
		}
		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = __( 'Subscriptions', 'woocommerce-subscriptions-lite' );
		}
		return $new_items;
	}

	/**
	 * Render the list for the current customer.
	 *
	 * Builds presentation-ready rows (status label, cancel-form fields) so the
	 * template stays pure presentation.
	 */
	public function render(): void {
		$customer_id = get_current_user_id();
		$rows        = $customer_id > 0 ? $this->build_rows( $this->find_contracts( $customer_id ) ) : [];

		wc_get_template(
			'myaccount/subscriptions.php',
			[
				'rows'          => $rows,
				'cancel_url'    => admin_url( 'admin-post.php' ),
				'cancel_action' => CancelHandler::ACTION,
				'nonce_field'   => CancelHandler::NONCE_FIELD,
				'nonce_action'  => CancelHandler::NONCE_ACTION,
			],
			'',
			WC_SUBSCRIPTIONS_LITE_DIR . 'templates/'
		);
	}

	/**
	 * Find the logged-in customer's contracts via their orders' linkage meta.
	 *
	 * Reads the parent orders the customer owns, pulls the contract id off each
	 * order's engine-written linkage meta, and loads the unique contracts by id.
	 * This is the public-surface stand-in for a customer-scoped contract query
	 * until the engine exposes one.
	 *
	 * @param int $customer_id The logged-in customer id.
	 * @return array<int, Contract>
	 */
	private function find_contracts( int $customer_id ): array {
		$orders = wc_get_orders(
			[
				'limit'       => -1,
				'customer_id' => $customer_id,
				'type'        => 'shop_order',
				'status'      => 'any',
				'meta_key'    => OrderLinkage::META_CONTRACT_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);

		$repository = new ContractRepository();
		$contracts  = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( OrderLinkage::RELATION_PARENT !== $order->get_meta( OrderLinkage::META_RELATION_TYPE ) ) {
				continue;
			}

			$contract_id = (int) $order->get_meta( OrderLinkage::META_CONTRACT_ID );
			if ( $contract_id <= 0 || isset( $contracts[ $contract_id ] ) ) {
				continue;
			}

			$contract = $repository->find( $contract_id );
			if ( $contract instanceof Contract && $contract->get_customer_id() === $customer_id ) {
				$contracts[ $contract_id ] = $contract;
			}
		}

		return array_values( $contracts );
	}

	/**
	 * Shape contracts into presentation rows for the template.
	 *
	 * @param array<int, Contract> $contracts The customer's contracts.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_rows( array $contracts ): array {
		$rows = [];
		foreach ( $contracts as $contract ) {
			$status = $contract->get_status();
			$rows[] = [
				'id'           => (int) $contract->get_id(),
				'status'       => $status,
				'status_label' => $this->status_label( $status ),
				'total'        => wp_strip_all_tags( wc_price( (float) $contract->get_billing_total(), [ 'currency' => $contract->get_currency() ] ) ),
				'next_payment' => $this->format_date( $contract->get_next_payment_gmt() ),
				'cancelable'   => in_array( $status, [ ContractStatus::ACTIVE, ContractStatus::ON_HOLD ], true ),
			];
		}
		return $rows;
	}

	/**
	 * Customer-facing status label.
	 *
	 * @param string $status Status slug.
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case ContractStatus::ACTIVE:
				return __( 'Active', 'woocommerce-subscriptions-lite' );
			case ContractStatus::ON_HOLD:
				return __( 'On hold', 'woocommerce-subscriptions-lite' );
			case ContractStatus::PENDING_CANCELLATION:
				return __( 'Cancels soon', 'woocommerce-subscriptions-lite' );
			case ContractStatus::CANCELLED:
				return __( 'Cancelled', 'woocommerce-subscriptions-lite' );
			case ContractStatus::EXPIRED:
				return __( 'Expired', 'woocommerce-subscriptions-lite' );
			default:
				return ucfirst( str_replace( '-', ' ', $status ) );
		}
	}

	/**
	 * Format a GMT timestamp string into the site's date format. Empty string on null.
	 *
	 * @param string|null $gmt GMT timestamp ('Y-m-d H:i:s') or null.
	 */
	private function format_date( ?string $gmt ): string {
		if ( null === $gmt || '' === $gmt ) {
			return '';
		}
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}
}
