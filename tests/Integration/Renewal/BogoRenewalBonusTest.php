<?php
/**
 * Integration tests for the BOGO bonus on renewal orders, through a real
 * engine renewal (`Subscriptions::renew_now()`) and the Bootstrap-wired listener.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Subscriptions;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Gateway\GatewayCapabilities;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Checkout\ContractFactory;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\PlanRepository;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\BogoRenewalBonus;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Order_Item_Product;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Renewal\BogoRenewalBonus
 */
final class BogoRenewalBonusTest extends LiteIntegrationTestCase {

	private const GATEWAY = 'lite-test-approving';

	public function set_up(): void {
		parent::set_up();
		GatewayCapabilities::declare( self::GATEWAY, [ GatewayCapabilities::RECURRING ] );
		add_action(
			'woocommerce_subscriptions_engine_scheduled_payment_' . self::GATEWAY,
			static function ( $amount, $renewal_order ): void {
				unset( $amount );
				if ( $renewal_order instanceof WC_Order && $renewal_order->needs_payment() ) {
					$renewal_order->payment_complete();
				}
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_actions( 'woocommerce_subscriptions_engine_scheduled_payment_' . self::GATEWAY );
		parent::tear_down();
	}

	public function test_the_listener_is_wired_once_by_bootstrap(): void {
		global $wp_filter;

		$count = 0;
		foreach ( $wp_filter['woocommerce_subscriptions_engine_renewal_order_created']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof BogoRenewalBonus ) {
					++$count;
				}
			}
		}

		$this->assertSame( 1, $count );
	}

	public function test_an_all_cycles_bogo_doubles_the_renewal_line_money_neutrally(): void {
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );

		$order = $this->renew( $contract_id );
		$item  = $this->only_line( $order );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 4, $item->get_quantity(), '2 paid units earn 2 bonus units.' );
		$this->assertSame( 39.98, (float) $item->get_subtotal() );
		$this->assertSame( 39.98, (float) $item->get_total() );
		$this->assertSame( 39.98, (float) $order->get_total() );

		// The bonus quantity is persisted, not only set on the in-memory item.
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 4, $this->only_line( $reloaded )->get_quantity() );
	}

	public function test_a_fractional_paid_quantity_earns_a_fractional_bonus(): void {
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ], 'woocommerce-subscriptions-lite', 1.5 );

		$item = $this->only_line( $this->renew( $contract_id ) );

		$this->assertSame( 3.0, $item->get_quantity(), '1.5 paid units earn 1.5 bonus units, not a rounded 2.' );
	}

	public function test_a_first_cycle_only_bogo_grants_nothing_on_the_cycle_two_renewal(): void {
		$contract_id = $this->sign_up(
			[
				'policies' => [
					[
						'type'            => 'bogo',
						'duration_cycles' => 1,
					],
				],
			]
		);

		$order = $this->renew( $contract_id );

		$this->assertSame( 2, $this->only_line( $order )->get_quantity() );
		$this->assertSame( 39.98, (float) $order->get_total() );
	}

	public function test_a_bogo_starting_at_cycle_three_grants_nothing_on_the_cycle_two_renewal(): void {
		$contract_id = $this->sign_up(
			[
				'policies' => [
					[
						'type'           => 'bogo',
						'starting_cycle' => 3,
					],
				],
			]
		);

		$this->assertSame( 2, $this->only_line( $this->renew( $contract_id ) )->get_quantity() );
	}

	public function test_the_snapshot_terms_apply_after_the_live_plan_is_deleted(): void {
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );

		$contract = Subscriptions::get( $contract_id );
		$this->assertNotNull( $contract );
		( new PlanRepository() )->delete( $contract->get_selling_plan_id() );

		$this->assertSame( 4, $this->only_line( $this->renew( $contract_id ) )->get_quantity() );
	}

	public function test_a_snapshot_without_terms_ignores_a_bogo_added_to_the_live_plan_later(): void {
		$contract_id = $this->sign_up( null );

		$contract = Subscriptions::get( $contract_id );
		$this->assertNotNull( $contract );
		$plans = new PlanRepository();
		$plan  = $plans->find( $contract->get_selling_plan_id() );
		$this->assertInstanceOf( Plan::class, $plan );
		$plan->set_pricing_policy( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );
		$this->assertTrue( $plans->update( $plan ) );

		$this->assertSame( 2, $this->only_line( $this->renew( $contract_id ) )->get_quantity() );
	}

	public function test_a_contract_owned_by_another_extension_is_left_alone(): void {
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ], 'other-extension' );

		$this->assertSame( 2, $this->only_line( $this->renew( $contract_id ) )->get_quantity() );
	}

	public function test_terms_without_bogo_leave_the_quantity_unchanged(): void {
		$contract_id = $this->sign_up(
			[
				'policies' => [
					[
						'type'  => 'percentage',
						'value' => 10,
					],
				],
			]
		);

		$order = $this->renew( $contract_id );

		$this->assertSame( 2, $this->only_line( $order )->get_quantity() );
		$this->assertSame( 39.98, (float) $order->get_total() );
	}

	/**
	 * Sign up a contract for `$quantity` x 19.99 on a monthly plan with the given terms.
	 *
	 * @param array<string, mixed>|null $pricing_policy Plan pricing payload.
	 * @param string                    $extension_slug Plan owner.
	 * @param int|float                 $quantity       Origin line quantity.
	 * @return int Contract id.
	 */
	private function sign_up( ?array $pricing_policy, string $extension_slug = 'woocommerce-subscriptions-lite', $quantity = 2 ): int {
		$plan  = $this->make_plan(
			'month',
			1,
			null,
			[
				'pricing_policy' => $pricing_policy,
				'extension_slug' => $extension_slug,
			]
		);
		$order = $this->create_subscription_order(
			$this->create_customer(),
			[
				'quantity'       => $quantity,
				'price'          => '19.99',
				'payment_method' => self::GATEWAY,
			]
		);

		return (int) ( new ContractFactory() )->create_from_order( $order, $plan )->get_id();
	}

	/**
	 * Run the cycle-2 renewal through the engine facade.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function renew( int $contract_id ): WC_Order {
		$order = Subscriptions::renew_now( $contract_id );
		$this->assertInstanceOf( WC_Order::class, $order );

		return $order;
	}

	/**
	 * The order's single product line.
	 *
	 * @param WC_Order $order Order.
	 */
	private function only_line( WC_Order $order ): WC_Order_Item_Product {
		$items = array_values( $order->get_items( 'line_item' ) );
		$this->assertCount( 1, $items );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $items[0] );

		return $items[0];
	}
}
