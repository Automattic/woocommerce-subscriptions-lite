<?php
/**
 * Integration tests for the admin row-action handlers.
 *
 * The handlers back the Renew now / Cancel actions on the admin subscriptions
 * screens: the decision method verifies the capability, then drives the engine
 * facade, returning a {@see RowActionResult}. The decision branches are driven
 * through the controller's own constructor seams (its public API); the hook
 * registration is asserted against the real hook table. The nonce is a
 * request-boundary concern (check_admin_referer), out of scope here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use RuntimeException;
use WC_Order;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult
 */
final class RowActionControllerTest extends LiteIntegrationTestCase {

	/**
	 * Captured log messages from the injected logger seam.
	 *
	 * @var array<int, string>
	 */
	private $logged = [];

	public function set_up(): void {
		parent::set_up();
		$this->logged = [];
	}

	/**
	 * A controller whose seams all pass, with spies on the engine + logger seams.
	 *
	 * @param array<string, mixed> $overrides Seam overrides: can, renew_return,
	 *                                        renew_calls, cancel_return, cancel_calls.
	 */
	private function make_controller( array $overrides = [] ): RowActionController {
		$can           = $overrides['can'] ?? true;
		$renew_return  = $overrides['renew_return'] ?? null;
		$cancel_return = $overrides['cancel_return'] ?? true;

		return new RowActionController(
			function ( int $id ) use ( $renew_return, &$overrides ): ?WC_Order {
				if ( isset( $overrides['renew_calls'] ) ) {
					$overrides['renew_calls'][] = $id;
				}
				if ( $renew_return instanceof \Throwable ) {
					throw $renew_return;
				}
				return $renew_return;
			},
			function ( int $id ) use ( $cancel_return, &$overrides ): bool {
				if ( isset( $overrides['cancel_calls'] ) ) {
					$overrides['cancel_calls'][] = $id;
				}
				if ( $cancel_return instanceof \Throwable ) {
					throw $cancel_return;
				}
				return (bool) $cancel_return;
			},
			static fn (): bool => (bool) $can,
			function ( string $message, array $context ): void {
				$this->logged[] = $message;
			}
		);
	}

	public function test_renew_now_runs_the_facade_for_an_authorised_request(): void {
		$renewal_order = wc_create_order();
		$renew_calls   = [];
		$controller    = $this->make_controller(
			[
				'renew_return' => $renewal_order,
				'renew_calls'  => &$renew_calls,
			]
		);

		$result = $controller->handle_renew_now( [ 'contract_id' => 100 ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 100 ], $renew_calls, 'The facade renewal runs for the authorised contract.' );
		$this->assertStringContainsString( $renewal_order->get_order_number(), $result->message(), 'The renewal order number is surfaced.' );
	}

	public function test_renew_now_reports_a_skipped_renewal_as_info(): void {
		// A null facade return means the engine skipped the renewal.
		$result = $this->make_controller( [ 'renew_return' => null ] )
			->handle_renew_now( [ 'contract_id' => 100 ] );

		$this->assertSame( RowActionResult::INFO, $result->type(), 'A skipped renewal is informational, not an error.' );
		$this->assertFalse( $result->is_success() );
	}

	public function test_renew_now_is_forbidden_without_the_capability(): void {
		$renew_calls = [];
		$controller  = $this->make_controller(
			[
				'can'         => false,
				'renew_calls' => &$renew_calls,
			]
		);

		$result = $controller->handle_renew_now( [ 'contract_id' => 100 ] );

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $renew_calls, 'An unauthorised request never reaches the facade.' );
	}

	public function test_renew_now_logs_the_exception_and_surfaces_a_generic_message(): void {
		$result = $this->make_controller( [ 'renew_return' => new RuntimeException( 'gateway down' ) ] )
			->handle_renew_now( [ 'contract_id' => 100 ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringNotContainsString( 'gateway down', $result->message(), 'The internal error detail is not leaked to the merchant.' );
		$this->assertStringContainsString( 'logs', $result->message(), 'The merchant is pointed at the logs.' );
		$this->assertCount( 1, $this->logged, 'The failure is logged once.' );
		$this->assertStringContainsString( 'gateway down', $this->logged[0], 'The full exception detail is logged.' );
	}

	public function test_cancel_runs_the_facade_for_an_authorised_request(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller(
			[
				'cancel_return' => true,
				'cancel_calls'  => &$cancel_calls,
			]
		);

		$result = $controller->handle_cancel( [ 'contract_id' => 100 ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 100 ], $cancel_calls, 'The facade cancel runs for the authorised contract.' );
	}

	public function test_cancel_reports_a_missing_contract_as_an_error(): void {
		// A false facade return means no such contract.
		$result = $this->make_controller( [ 'cancel_return' => false ] )
			->handle_cancel( [ 'contract_id' => 999 ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertFalse( $result->is_success() );
		$this->assertCount( 0, $this->logged, 'A plain not-found is not logged as a failure.' );
	}

	public function test_cancel_is_forbidden_without_the_capability(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller(
			[
				'can'          => false,
				'cancel_calls' => &$cancel_calls,
			]
		);

		$result = $controller->handle_cancel( [ 'contract_id' => 100 ] );

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $cancel_calls, 'An unauthorised request never reaches the facade.' );
	}

	public function test_cancel_logs_the_exception_and_surfaces_a_generic_message(): void {
		$result = $this->make_controller( [ 'cancel_return' => new RuntimeException( 'storage offline' ) ] )
			->handle_cancel( [ 'contract_id' => 100 ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringNotContainsString( 'storage offline', $result->message(), 'The internal error detail is not leaked to the merchant.' );
		$this->assertStringContainsString( 'logs', $result->message(), 'The merchant is pointed at the logs.' );
		$this->assertCount( 1, $this->logged, 'The failure is logged once.' );
		$this->assertStringContainsString( 'storage offline', $this->logged[0], 'The full exception detail is logged.' );
	}

	public function test_cancel_rejects_a_missing_contract_id_before_the_facade(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller( [ 'cancel_calls' => &$cancel_calls ] );

		$result = $controller->handle_cancel( [] );

		$this->assertSame( RowActionResult::ERROR, $result->type(), 'No contract id resolves to not found.' );
		$this->assertSame( [], $cancel_calls, 'A missing contract id never reaches the facade.' );
	}

	public function test_register_binds_the_admin_post_handlers(): void {
		RowActionController::register();

		$this->assertNotFalse( has_action( 'admin_post_woocommerce_subscriptions_lite_renew_now' ) );
		$this->assertNotFalse( has_action( 'admin_post_woocommerce_subscriptions_lite_cancel_admin' ) );
	}
}
