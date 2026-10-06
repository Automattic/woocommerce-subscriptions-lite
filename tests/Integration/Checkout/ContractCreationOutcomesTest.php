<?php
/**
 * Integration tests for ContractCreationHandler: recurring money facts of taxed,
 * discounted and multi-line orders, an activation write that fails, a contract
 * insert that fails, and a plan billing payload that does not parse.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Checkout;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ApplicabilityStore;
use Automattic\WooCommerce\SubscriptionsLite\Plans\ProductApplicability;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Simple;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler
 */
final class ContractCreationOutcomesTest extends LiteIntegrationTestCase {

	/**
	 * Add a product line with explicit subtotal, total and tax.
	 *
	 * @param WC_Order $order    The order.
	 * @param string   $subtotal Line subtotal (before discounts).
	 * @param string   $total    Line total (after discounts).
	 * @param string   $tax      Line tax on the total.
	 */
	private function add_line( WC_Order $order, string $subtotal, string $total, string $tax = '0' ): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Coffee' );
		$product->set_regular_price( $subtotal );
		$product->save();

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( $subtotal );
		$item->set_total( $total );
		$item->set_taxes(
			[
				'subtotal' => [ 1 => $tax ],
				'total'    => [ 1 => $tax ],
			]
		);
		$order->add_item( $item );
	}

	/**
	 * A USD order with shipping, built from explicit line amounts.
	 *
	 * @param string $shipping     Shipping total.
	 * @param string $shipping_tax Shipping tax.
	 */
	private function new_order( string $shipping = '0', string $shipping_tax = '0' ): WC_Order {
		$order = wc_create_order( [ 'customer_id' => $this->create_customer() ] );
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'dummy' );
		$order->set_date_paid( '2026-01-15 00:00:00' );

		$line = new WC_Order_Item_Shipping();
		$line->set_method_title( 'Flat rate' );
		$line->set_total( $shipping );
		$line->set_taxes( [ 'total' => [ 1 => $shipping_tax ] ] );
		$order->add_item( $line );
		$order->set_shipping_total( $shipping );
		$order->set_shipping_tax( $shipping_tax );

		return $order;
	}

	/**
	 * Run the checkout mapping and read the contract back.
	 *
	 * @param WC_Order $order The order, saved by this call.
	 */
	private function create( WC_Order $order ): ContractView {
		$order->save();
		$plan = $this->make_plan();
		$this->stamp_plan( $order, $plan );

		$created = ( new ContractCreationHandler() )->create_contract( $order, $plan );
		$this->assertInstanceOf( ContractView::class, $created );

		$view = Contracts::get( $created->get_id() );
		$this->assertEquals( $view, $created, 'The returned view matches a fresh read.' );

		return $view;
	}

	public function test_a_taxed_discounted_order_records_its_recurring_money(): void {
		$order = $this->new_order( '5', '0.5' );
		$this->add_line( $order, '40', '36', '3.6' );

		$view = $this->create( $order );

		$this->assertSame( '45.10000000', $view->get_billing_total(), 'Line total + line tax + shipping + shipping tax.' );
		$this->assertSame( '4.10000000', $view->get_tax_total() );
		$this->assertSame( '4.00000000', $view->get_discount_total() );
		$this->assertSame( '5.00000000', $view->get_shipping_total() );
	}

	public function test_a_multi_line_order_sums_every_plan_line(): void {
		$order = $this->new_order( '2' );
		$this->add_line( $order, '10', '10', '1' );
		$this->add_line( $order, '15', '12', '1.2' );

		$view = $this->create( $order );

		$this->assertSame( '26.20000000', $view->get_billing_total() );
		$this->assertSame( '2.20000000', $view->get_tax_total() );
		$this->assertSame( '3.00000000', $view->get_discount_total() );
		$this->assertCount( 2, (array) $view->get_items() );
	}

	public function test_a_failed_activation_leaves_a_noted_draft(): void {
		$customer_id = $this->create_customer();
		$order       = $this->create_subscription_order( $customer_id );
		$order->set_status( 'processing' );
		$order->save();

		$plan = $this->make_plan();
		$this->stamp_plan( $order, $plan );
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				( new ApplicabilityStore() )->set( $item->get_product_id(), new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
			}
		}

		// Fail only the activation write (the one contract UPDATE that sets the next payment).
		$table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$break = static function ( string $query ) use ( $table ): string {
			$activation = 0 === strpos( ltrim( $query ), "UPDATE `{$table}`" ) && false !== strpos( $query, 'next_payment_gmt' );
			return $activation ? 'SELECT broken syntax (' : $query;
		};
		add_filter( 'query', $break );

		try {
			( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id() );
		} finally {
			remove_filter( 'query', $break );
		}

		$contracts = Contracts::find_by_origin_order( $order->get_id() );
		$this->assertCount( 1, $contracts );
		$draft = $contracts[0];
		$this->assertSame( ContractStatus::DRAFT, $draft->get_status() );
		$this->assertNull( $draft->get_next_payment_gmt() );
		$this->assertCount( 1, Contracts::get_cycles( (int) $draft->get_id() ) );

		$notes = array_map(
			static fn ( $note ): string => (string) $note->content,
			wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
		$this->assertNotEmpty(
			array_filter( $notes, static fn ( string $note ): bool => false !== strpos( $note, '#' . $draft->get_id() ) ),
			'An order note names the draft.'
		);
	}

	public function test_an_unparseable_plan_billing_payload_defers_the_order(): void {
		$order = $this->create_subscription_order( $this->create_customer() );
		$order->set_status( 'processing' );
		$order->save();

		// A digit-string interval: the strict billing parser contract creation uses
		// refuses it, so the line's plan does not resolve as billable.
		$plan = $this->make_unvalidated_plan(
			'month',
			1,
			null,
			[
				'billing_policy' => [
					'period'   => 'month',
					'interval' => '1',
				],
			]
		);
		$this->stamp_plan( $order, $plan );
		$this->make_lines_applicable( $order );

		( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id() );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertSame( ContractCreationHandler::REASON_PLAN_UNAVAILABLE, $this->deferral_reason( $order ), 'The stamped line whose plan is not billable defers the order.' );
		$this->assertNotEmpty( $this->notes_containing( $order, 'plan_unavailable' ), 'An order note records the deferral.' );
	}

	public function test_a_failed_contract_insert_defers_the_order_and_is_not_retried(): void {
		global $wpdb;

		$order = $this->create_subscription_order( $this->create_customer() );
		$order->set_status( 'processing' );
		$order->save();

		$this->stamp_plan( $order, $this->make_plan() );
		$this->make_lines_applicable( $order );

		$table = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS );
		$break = static function ( string $query ) use ( $table ): string {
			return 0 === strpos( ltrim( $query ), "INSERT INTO `{$table}`" ) ? 'SELECT broken syntax (' : $query;
		};
		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );

		try {
			( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id() );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $break );
		}

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ), 'The insert failed, so no contract exists.' );
		$this->assertSame( ContractCreationHandler::REASON_CREATION_FAILED, $this->deferral_reason( $order ) );
		$this->assertCount( 1, $this->notes_containing( $order, 'creation_failed' ) );

		// A later paid transition does not retry: the deferral flag holds it.
		( new ContractCreationHandler() )->create_contracts_for_order( $order->get_id() );

		$this->assertSame( [], Contracts::find_by_origin_order( $order->get_id() ) );
		$this->assertCount( 1, $this->notes_containing( $order, 'creation_failed' ) );
	}

	/**
	 * Make every product line's product accept every Lite plan.
	 *
	 * @param WC_Order $order The order.
	 */
	private function make_lines_applicable( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				( new ApplicabilityStore() )->set( $item->get_product_id(), new ProductApplicability( ProductApplicability::MODE_INHERIT_ALL ) );
			}
		}
	}

	/**
	 * The order's deferral reason, read fresh.
	 *
	 * @param WC_Order $order The order.
	 */
	private function deferral_reason( WC_Order $order ): string {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );

		return (string) $fresh->get_meta( ContractCreationHandler::CREATION_DEFERRED_META );
	}

	/**
	 * The order notes containing `$needle`.
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $needle Text to look for.
	 * @return array<int, string>
	 */
	private function notes_containing( WC_Order $order, string $needle ): array {
		$notes = array_map(
			static fn ( $note ): string => (string) $note->content,
			wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);

		return array_values( array_filter( $notes, static fn ( string $note ): bool => false !== strpos( $note, $needle ) ) );
	}
}
