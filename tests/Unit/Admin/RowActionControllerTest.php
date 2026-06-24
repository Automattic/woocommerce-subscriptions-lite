<?php
/**
 * Unit tests for the admin row-action handlers.
 *
 * The handlers back the Renew now / Cancel actions on the admin subscriptions
 * screens: each verifies the capability and nonce, then drives the engine
 * through its public facade, returning a {@see RowActionResult}. These tests
 * inject fake renew / cancel / capability / nonce seams so every guard branch
 * runs without a booted WordPress and without the static facade.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WC_Order;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult
 */
final class RowActionControllerTest extends TestCase {

	/**
	 * A controller whose seams all pass, with spies on the engine seams.
	 *
	 * @param array<string, mixed> $overrides Seam overrides: can, nonce_ok,
	 *                                        renew_return, renew_calls,
	 *                                        cancel_return, cancel_calls.
	 */
	private function make_controller( array $overrides = [] ): RowActionController {
		$can           = $overrides['can'] ?? true;
		$nonce_ok      = $overrides['nonce_ok'] ?? true;
		$renew_return  = $overrides['renew_return'] ?? null;
		$cancel_return = $overrides['cancel_return'] ?? true;

		return new RowActionController(
			static function ( int $id ) use ( $renew_return, &$overrides ): ?WC_Order {
				if ( isset( $overrides['renew_calls'] ) ) {
					$overrides['renew_calls'][] = $id;
				}
				if ( $renew_return instanceof \Throwable ) {
					throw $renew_return;
				}
				return $renew_return;
			},
			static function ( int $id ) use ( $cancel_return, &$overrides ): bool {
				if ( isset( $overrides['cancel_calls'] ) ) {
					$overrides['cancel_calls'][] = $id;
				}
				if ( $cancel_return instanceof \Throwable ) {
					throw $cancel_return;
				}
				return (bool) $cancel_return;
			},
			static fn (): bool => (bool) $can,
			static fn ( string $nonce, string $action ): bool => (bool) $nonce_ok
		);
	}

	public function test_renew_now_runs_the_facade_for_an_authorised_request(): void {
		$renew_calls = [];
		$controller  = $this->make_controller(
			[
				'renew_return' => new WC_Order( 4242 ),
				'renew_calls'  => &$renew_calls,
			]
		);

		$result = $controller->handle_renew_now(
			[
				'contract_id' => 100,
				'nonce'       => 'ok',
			]
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 100 ], $renew_calls, 'The facade renewal runs for the authorised contract.' );
		$this->assertStringContainsString( '4242', $result->message(), 'The renewal order number is surfaced.' );
	}

	public function test_renew_now_reports_a_skipped_renewal_as_info(): void {
		// A null facade return means the engine skipped the renewal.
		$result = $this->make_controller( [ 'renew_return' => null ] )
			->handle_renew_now(
				[
					'contract_id' => 100,
					'nonce'       => 'ok',
				]
			);

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

		$result = $controller->handle_renew_now(
			[
				'contract_id' => 100,
				'nonce'       => 'ok',
			]
		);

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $renew_calls, 'An unauthorised request never reaches the facade.' );
	}

	public function test_renew_now_is_forbidden_with_a_bad_nonce(): void {
		$renew_calls = [];
		$controller  = $this->make_controller(
			[
				'nonce_ok'    => false,
				'renew_calls' => &$renew_calls,
			]
		);

		$result = $controller->handle_renew_now(
			[
				'contract_id' => 100,
				'nonce'       => 'bad',
			]
		);

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $renew_calls, 'A bad nonce never reaches the facade.' );
	}

	public function test_renew_now_catches_an_engine_throwable_as_an_error(): void {
		$result = $this->make_controller( [ 'renew_return' => new RuntimeException( 'gateway down' ) ] )
			->handle_renew_now(
				[
					'contract_id' => 100,
					'nonce'       => 'ok',
				]
			);

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringContainsString( 'gateway down', $result->message() );
	}

	public function test_cancel_runs_the_facade_for_an_authorised_request(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller(
			[
				'cancel_return' => true,
				'cancel_calls'  => &$cancel_calls,
			]
		);

		$result = $controller->handle_cancel(
			[
				'contract_id' => 100,
				'nonce'       => 'ok',
			]
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 100 ], $cancel_calls, 'The facade cancel runs for the authorised contract.' );
	}

	public function test_cancel_reports_a_missing_contract_as_an_error(): void {
		// A false facade return means no such contract.
		$result = $this->make_controller( [ 'cancel_return' => false ] )
			->handle_cancel(
				[
					'contract_id' => 999,
					'nonce'       => 'ok',
				]
			);

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertFalse( $result->is_success() );
	}

	public function test_cancel_is_forbidden_without_the_capability(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller(
			[
				'can'          => false,
				'cancel_calls' => &$cancel_calls,
			]
		);

		$result = $controller->handle_cancel(
			[
				'contract_id' => 100,
				'nonce'       => 'ok',
			]
		);

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $cancel_calls, 'An unauthorised request never reaches the facade.' );
	}

	public function test_cancel_is_forbidden_with_a_bad_nonce(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller(
			[
				'nonce_ok'     => false,
				'cancel_calls' => &$cancel_calls,
			]
		);

		$result = $controller->handle_cancel(
			[
				'contract_id' => 100,
				'nonce'       => 'bad',
			]
		);

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( [], $cancel_calls, 'A bad nonce never reaches the facade.' );
	}

	public function test_cancel_rejects_a_missing_contract_id_before_the_facade(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller( [ 'cancel_calls' => &$cancel_calls ] );

		$result = $controller->handle_cancel( [ 'nonce' => 'ok' ] );

		$this->assertSame( RowActionResult::ERROR, $result->type(), 'No contract id resolves to not found.' );
		$this->assertSame( [], $cancel_calls, 'A missing contract id never reaches the facade.' );
	}

	public function test_register_binds_the_admin_post_handlers(): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'] = [];

		RowActionController::register();

		$names = array_map(
			static fn ( array $h ): string => (string) $h['hook'],
			$GLOBALS['woocommerce_subscriptions_lite_test_hooks']
		);

		$this->assertContains( 'admin_post_wc_subscriptions_lite_renew_now', $names );
		$this->assertContains( 'admin_post_wc_subscriptions_lite_cancel_admin', $names );
	}
}
