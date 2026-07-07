<?php
/**
 * Base test case for Lite integration tests.
 *
 * Schema is installed once in the bootstrap; WP_UnitTestCase wraps each test in
 * a transaction and rolls it back, so seeded rows do not leak between tests.
 *
 * Seeding philosophy: contracts are created through the REAL production path -
 * a WooCommerce order run through the engine's checkout factory - and moved to
 * other statuses through the engine's public facade verbs, so the data under
 * test is shaped exactly like production data. The engine's `Integration\`
 * classes used here (plan repositories, checkout factory) are a documented
 * test-only exemption from the "consume the engine via the `Api\` facade only"
 * production rule; the engine's own integration tests seed the same way.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanGroup;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\ContractFactory;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\OrderLinkage;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanGroupRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use WC_Order;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Lite integration test case: real-WordPress base class + seeding helpers.
 */
abstract class LiteIntegrationTestCase extends WP_UnitTestCase {

	/**
	 * Default billing address used when seeding contracts.
	 *
	 * @var array<string, string>
	 */
	protected const BILLING_ADDRESS = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '12 Analytical Row',
		'city'       => 'London',
		'postcode'   => 'N1 9GU',
		'country'    => 'GB',
		'email'      => 'ada@example.com',
		'phone'      => '020 7946 0018',
	];

	/**
	 * Default shipping address used when seeding contracts.
	 *
	 * @var array<string, string>
	 */
	protected const SHIPPING_ADDRESS = [
		'first_name' => 'Ada',
		'last_name'  => 'Lovelace',
		'address_1'  => '1 Engine Court',
		'city'       => 'London',
		'postcode'   => 'SW1A 1AA',
		'country'    => 'GB',
	];

	/**
	 * Create a customer user.
	 *
	 * @param array<string, mixed> $overrides WP user field overrides.
	 * @return int User id.
	 */
	protected function create_customer( array $overrides = [] ): int {
		return self::factory()->user->create(
			array_merge(
				[
					'role'       => 'customer',
					'user_email' => 'customer-' . wp_generate_password( 6, false ) . '@example.com',
				],
				$overrides
			)
		);
	}

	/**
	 * Persist a plan (and its group) and return the entity.
	 *
	 * @param string   $period     Billing period (day / week / month / year).
	 * @param int      $interval   Billing interval.
	 * @param int|null $max_cycles Maximum billing cycles, or null for open-ended.
	 */
	protected function make_plan( string $period = 'month', int $interval = 1, ?int $max_cycles = null ): Plan {
		$group_id = ( new PlanGroupRepository() )->insert( PlanGroup::create( [ 'name' => 'Lite Tests' ] ) );

		$plan = Plan::create(
			$group_id,
			[
				'name'           => ucfirst( $period ) . 'ly plan',
				'billing_policy' => new BillingPolicy( $period, $interval, null, $max_cycles, null ),
				'category'       => Plan::DEFAULT_CATEGORY,
				'extension_slug' => 'woocommerce-subscriptions-lite',
			]
		);
		( new PlanRepository() )->insert( $plan );

		return $plan;
	}

	/**
	 * Create a paid subscription order for a customer.
	 *
	 * Real product, real line item, real addresses - the item and address data
	 * the contract carries all originate here, as in production.
	 *
	 * @param int                  $customer_id Owning customer.
	 * @param array<string, mixed> $args        product_name, price, quantity, payment_method, payment_method_title, date_paid.
	 */
	protected function create_subscription_order( int $customer_id, array $args = [] ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( (string) ( $args['product_name'] ?? 'Monthly Coffee Box' ) );
		$product->set_regular_price( (string) ( $args['price'] ?? '19.99' ) );
		$product->save();

		$order = wc_create_order( [ 'customer_id' => $customer_id ] );
		$order->add_product( $product, (int) ( $args['quantity'] ?? 1 ) );
		$order->set_address( array_merge( self::BILLING_ADDRESS, (array) ( $args['billing'] ?? [] ) ), 'billing' );
		$order->set_address( array_merge( self::SHIPPING_ADDRESS, (array) ( $args['shipping'] ?? [] ) ), 'shipping' );
		$order->set_currency( 'USD' );
		$order->set_payment_method( (string) ( $args['payment_method'] ?? 'dummy' ) );
		$order->set_payment_method_title( (string) ( $args['payment_method_title'] ?? 'Dummy Payments' ) );
		$order->calculate_totals();
		$order->set_date_paid( (string) ( $args['date_paid'] ?? '2026-01-15 00:00:00' ) );
		$order->save();

		return $order;
	}

	/**
	 * Create a contract through the real checkout path and move it to the
	 * requested status through the engine facade.
	 *
	 * @param int                  $customer_id Owning customer.
	 * @param array<string, mixed> $args        status (active / on-hold / pending-cancellation / cancelled),
	 *                                          period, interval, max_cycles, plus the order args of
	 *                                          {@see self::create_subscription_order()}.
	 * @return int Contract id.
	 */
	protected function create_contract( int $customer_id, array $args = [] ): int {
		$plan  = $this->make_plan(
			(string) ( $args['period'] ?? 'month' ),
			(int) ( $args['interval'] ?? 1 ),
			isset( $args['max_cycles'] ) ? (int) $args['max_cycles'] : null
		);
		$order = $this->create_subscription_order( $customer_id, $args );

		$contract    = ( new ContractFactory() )->create_from_order( $order, $plan );
		$contract_id = (int) $contract->get_id();

		switch ( (string) ( $args['status'] ?? 'active' ) ) {
			case 'on-hold':
				Subscriptions::hold( $contract_id );
				break;
			case 'pending-cancellation':
				Subscriptions::cancel_at_period_end( $contract_id );
				break;
			case 'cancelled':
				Subscriptions::cancel( $contract_id );
				break;
			default:
				break;
		}

		return $contract_id;
	}

	/**
	 * Force a contract's raw status column - for states no facade verb produces
	 * (e.g. `expired`). Prefer {@see self::create_contract()} status transitions.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $status      Raw status value.
	 */
	protected function force_contract_status( int $contract_id, string $status ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test seeding shortcut for states without a transition verb.
		$wpdb->update(
			SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS ),
			[ 'status' => $status ],
			[ 'id' => $contract_id ]
		);
	}

	/**
	 * Create a renewal order linked to a contract, the shape the related-orders
	 * read queries for.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param int                  $customer_id Owning customer.
	 * @param array<string, mixed> $args        total, status, date_created.
	 */
	protected function create_renewal_order( int $contract_id, int $customer_id, array $args = [] ): WC_Order {
		$order = wc_create_order( [ 'customer_id' => $customer_id ] );
		$order->set_total( (string) ( $args['total'] ?? '19.99' ) );
		$order->set_status( (string) ( $args['status'] ?? 'completed' ) );
		if ( isset( $args['date_created'] ) ) {
			$order->set_date_created( (string) $args['date_created'] );
		}
		$order->update_meta_data( OrderLinkage::META_CONTRACT_ID, (string) $contract_id );
		$order->update_meta_data( OrderLinkage::META_RELATION_TYPE, 'renewal' );
		$order->save();

		return $order;
	}
}
