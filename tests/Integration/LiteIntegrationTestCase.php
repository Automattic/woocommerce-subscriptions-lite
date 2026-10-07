<?php
/**
 * Base test case for Lite integration tests.
 *
 * Schema is installed once in the bootstrap; WP_UnitTestCase wraps each test in
 * a transaction and rolls it back, so seeded rows do not leak between tests.
 *
 * Seeding philosophy: contracts are created through the REAL production path -
 * a WooCommerce order mapped by Lite's checkout handler onto the engine's
 * contracts facade - and moved to other statuses through the engine's public
 * facade verbs, so the data under test is shaped exactly like production data.
 * Plans are created through the engine's plan write facade and read back as
 * views, as production code reads them. The engine's `Integration\` classes
 * used here (order linkage for renewal orders) are a documented test-only
 * exemption from the "consume the engine via the `Api\` facade only" rule.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\SellingPlans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\OrderLinkage;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Package;
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
	 * Create a plan through the engine plan facade and return its view.
	 *
	 * @param string               $period     Billing period (day / week / month / year).
	 * @param int                  $interval   Billing interval.
	 * @param int|null             $max_cycles Maximum billing cycles, or null for open-ended.
	 * @param array<string, mixed> $overrides  Facade create keys (name, status, extension_slug, billing_policy, pricing_policy, delivery_policy).
	 */
	protected function make_plan( string $period = 'month', int $interval = 1, ?int $max_cycles = null, array $overrides = [] ): PlanView {
		$args = array_merge(
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'name'           => ucfirst( $period ) . 'ly plan',
				'billing_policy' => [
					'period'     => $period,
					'interval'   => $interval,
					'max_cycles' => $max_cycles,
				],
			],
			$overrides
		);

		$plan = ( new SellingPlans( [ (string) $args['extension_slug'] ] ) )->get_plan( Plans::create( $args ) );
		$this->assertNotNull( $plan );

		return $plan;
	}

	/**
	 * Create a plan with the plan validation action unhooked, as a row written
	 * before a validation rule existed would be stored. Same arguments as
	 * {@see self::make_plan()}.
	 *
	 * @param string               $period     Billing period.
	 * @param int                  $interval   Billing interval.
	 * @param int|null             $max_cycles Maximum billing cycles.
	 * @param array<string, mixed> $overrides  Facade create keys.
	 */
	protected function make_unvalidated_plan( string $period = 'month', int $interval = 1, ?int $max_cycles = null, array $overrides = [] ): PlanView {
		return $this->without_plan_validation(
			function () use ( $period, $interval, $max_cycles, $overrides ): PlanView {
				return $this->make_plan( $period, $interval, $max_cycles, $overrides );
			}
		);
	}

	/**
	 * Update a plan with the plan validation action unhooked, as an out-of-band
	 * write would store it.
	 *
	 * @param int                  $id   Plan id.
	 * @param array<string, mixed> $args Facade update keys.
	 */
	protected function update_plan_unvalidated( int $id, array $args ): void {
		$this->assertTrue(
			$this->without_plan_validation(
				static function () use ( $id, $args ): bool {
					return Plans::update( $id, $args );
				}
			)
		);
	}

	/**
	 * Run `$write` with the plan validation action unhooked, then restore it.
	 *
	 * @template T
	 * @param callable(): T $write The write.
	 * @return T
	 */
	private function without_plan_validation( callable $write ) {
		global $wp_filter;

		$hook  = 'woocommerce_subscriptions_engine_validate_plan';
		$saved = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
		remove_all_actions( $hook );

		try {
			return $write();
		} finally {
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the hook this helper unhooked.
			}
		}
	}

	/**
	 * Set a plan's status through the engine plan facade.
	 *
	 * @param int    $id     Plan id.
	 * @param string $status A registered plan status.
	 */
	protected function set_plan_status( int $id, string $status ): void {
		$this->assertTrue( Plans::update( $id, [ 'status' => $status ] ) );
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
		$order->add_product( $product, $args['quantity'] ?? 1 );
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

		$this->stamp_plan( $order, $plan );

		$contract = ( new ContractCreationHandler() )->create_contract( $order, $plan );
		$this->assertInstanceOf( ContractView::class, $contract );
		$contract_id = $contract->get_id();

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
	 * Stamp every order line with `$plan`, as the cart writer does at checkout.
	 *
	 * @param WC_Order $order The order.
	 * @param PlanView $plan  The selling plan.
	 */
	protected function stamp_plan( WC_Order $order, PlanView $plan ): void {
		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( CartPlanHooks::SELLING_PLAN_ID_KEY, (string) $plan->get_id() );
			$item->save();
		}
	}

	/**
	 * Set a contract's status directly - for states no lifecycle verb produces
	 * (e.g. `expired`). Prefer {@see self::create_contract()} status transitions.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $status      A registered contract status.
	 */
	protected function force_contract_status( int $contract_id, string $status ): void {
		Contracts::update( $contract_id, [ 'status' => $status ] );
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
