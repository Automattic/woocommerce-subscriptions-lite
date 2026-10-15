<?php
/**
 * Integration tests for the BOGO bonus units the renewal order builder adds for the
 * billed cycle.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle\Renewal;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Order;
use WC_Order_Item_Product;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder
 */
final class RenewalOrderBogoTest extends LiteIntegrationTestCase {

	public function test_an_all_cycles_bogo_doubles_the_renewal_line_money_neutrally(): void {
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );

		$order = $this->renew( $contract_id );
		$item  = $this->only_line( $order );

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
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ], Package::EXTENSION_SLUG, 1.5 );

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

	public function test_a_bogo_added_to_the_live_plan_later_applies_on_renewal(): void {
		$contract_id = $this->sign_up( null );

		$this->set_live_pricing_policy( $contract_id, [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );

		$this->assertSame( 4, $this->only_line( $this->renew( $contract_id ) )->get_quantity() );
	}

	public function test_a_bogo_removed_from_the_live_plan_grants_nothing(): void {
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );

		$this->set_live_pricing_policy( $contract_id, null );

		$this->assertSame( 2, $this->only_line( $this->renew( $contract_id ) )->get_quantity() );
	}

	public function test_a_bogo_on_an_archived_plan_still_applies_on_renewal(): void {
		$contract_id = $this->sign_up( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] );
		$contract    = Contracts::get( $contract_id );
		$this->assertNotNull( $contract );

		$this->set_plan_status( (int) $contract->get_selling_plan_id(), PlanStatus::ARCHIVED );

		$this->assertSame( 4, $this->only_line( $this->renew( $contract_id ) )->get_quantity(), 'Archiving stops new sign-ups, not the bonus of existing subscribers.' );
	}

	public function test_a_plan_owned_by_another_extension_grants_nothing(): void {
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
	 * Replace the pricing payload of the contract's live plan.
	 *
	 * @param int                       $contract_id    Contract id.
	 * @param array<string, mixed>|null $pricing_policy New pricing payload.
	 */
	private function set_live_pricing_policy( int $contract_id, ?array $pricing_policy ): void {
		$contract = Contracts::get( $contract_id );
		$this->assertNotNull( $contract );
		$this->assertInstanceOf(
			PlanView::class,
			Plans::update(
				(int) $contract->get_selling_plan_id(),
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'pricing_policy' => $pricing_policy,
				]
			)
		);
	}

	/**
	 * Sign up a contract for `$quantity` x 19.99 on a monthly plan with the given terms,
	 * through Lite's checkout mapping.
	 *
	 * @param array<string, mixed>|null $pricing_policy Plan pricing payload.
	 * @param string                    $extension_slug Plan owner.
	 * @param int|float                 $quantity       Origin line quantity.
	 * @return int Contract id.
	 */
	private function sign_up( ?array $pricing_policy, string $extension_slug = Package::EXTENSION_SLUG, $quantity = 2 ): int {
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
				'payment_method' => 'cod',
			]
		);

		$this->stamp_plan( $order, $plan );

		$contract = ( new ContractCreationHandler() )->create_contract( $order, $plan );
		$this->assertNotNull( $contract );

		return $contract->get_id();
	}

	/**
	 * Build the cycle-2 renewal order.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function renew( int $contract_id ): WC_Order {
		$cycle = Contracts::add_cycle(
			$contract_id,
			[
				'status'         => CycleStatus::PENDING,
				'count'          => 2,
				'starts_at_gmt'  => '2026-02-15 00:00:00',
				'ends_at_gmt'    => '2026-03-15 00:00:00',
				'expected_total' => '39.98',
				'currency'       => 'USD',
			]
		);
		$order = ( new RenewalOrderBuilder() )->build( $this->get_contract( $contract_id ), $cycle );
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
