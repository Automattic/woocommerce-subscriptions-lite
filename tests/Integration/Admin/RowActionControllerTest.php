<?php
/**
 * Integration tests for the admin row-action handlers.
 *
 * The handlers back the Renew now / Cancel actions on the admin subscriptions
 * screens: the decision method verifies the capability, then runs the renewal or
 * cancel, returning a {@see RowActionResult}. Renew now runs Lite's real renewal over
 * real contracts; the cancel branches are driven through the controller's constructor
 * seams (its public API); the hook
 * registration is asserted against the real hook table. The nonce is a
 * request-boundary concern (check_admin_referer), out of scope here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Admin\MetaBoxes\Actions;
use Automattic\WooCommerce\SubscriptionsLite\Admin\PageController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;
use Automattic\WooCommerce\SubscriptionsLite\Package;
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
	 * A controller whose seams all pass, with spies on the cancel + logger seams.
	 *
	 * @param array<string, mixed> $overrides Seam overrides: can, cancel_return, cancel_calls.
	 */
	private function make_controller( array $overrides = [] ): RowActionController {
		$can           = $overrides['can'] ?? true;
		$cancel_return = $overrides['cancel_return'] ?? true;

		return new RowActionController(
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

	public function test_renew_now_renews_an_active_contract_and_reports_the_order(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		$result = $this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $this->charge_count() );
		$order = wc_get_order( $this->charged_order_ids[0] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertStringContainsString( '#' . $order->get_order_number(), $result->message(), 'The renewal order number is surfaced.' );
	}

	public function test_renew_now_reports_a_customer_hold_as_not_renewable(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract(
			$this->create_customer(),
			[
				'payment_method' => 'cod',
				'status'         => 'on-hold',
			]
		);

		$result = $this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::INFO, $result->type(), 'A skipped renewal is informational, not an error.' );
		$this->assertStringContainsString( 'not currently renewable', $result->message() );
		$this->assertSame( 0, $this->charge_count() );
	}

	public function test_renew_now_retries_a_failed_renewal_on_hold(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->support_engine_charges( 'cod', 'paid' );

		$result = $this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 2, $this->charge_count() );
		$this->assertSame( $this->charged_order_ids[0], $this->charged_order_ids[1], 'The failed order is charged again.' );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_renew_now_reports_a_failed_charge_as_an_error(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		$result = $this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( 1, $this->charge_count() );
	}

	public function test_renew_now_reports_a_manual_renewal_order_as_awaiting_payment(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'bacs' ] );

		$result = $this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::INFO, $result->type() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_renew_now_is_forbidden_without_the_capability(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		$result = $this->make_controller( [ 'can' => false ] )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( 0, $this->charge_count(), 'An unauthorised request never renews.' );
		$this->assertCount( 1, Contracts::get_cycles( $contract_id ) );
	}

	public function test_renew_now_logs_the_exception_and_surfaces_a_generic_message(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		// A gateway capability lookup that throws, on the real renewal path.
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function () {
				throw new RuntimeException( 'gateway lookup failed' );
			},
			20
		);

		$result = $this->make_controller()->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringNotContainsString( 'gateway lookup failed', $result->message(), 'The internal error detail is not leaked to the merchant.' );
		$this->assertStringContainsString( 'logs', $result->message(), 'The merchant is pointed at the logs.' );
		$this->assertCount( 1, $this->logged, 'The failure is logged once.' );
		$this->assertStringContainsString( 'gateway lookup failed', $this->logged[0], 'The full exception detail is logged.' );
	}

	public function test_cancel_runs_the_canceller_for_an_authorised_request(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller(
			[
				'cancel_return' => true,
				'cancel_calls'  => &$cancel_calls,
			]
		);

		$result = $controller->handle_cancel( [ 'contract_id' => 100 ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( [ 100 ], $cancel_calls, 'The canceller runs for the authorised contract.' );
	}

	public function test_a_draft_offers_cancel_and_the_real_cancel_resolves_it(): void {
		$draft = Contracts::create( [ 'extension_slug' => Package::EXTENSION_SLUG ] );
		$id    = $draft->get_id();

		ob_start();
		Actions::output( $draft );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( PageController::ACTION_CANCEL, $html, 'The detail screen offers Cancel for a draft.' );
		$this->assertStringNotContainsString( PageController::ACTION_RENEW_NOW, $html, 'Renew now stays hidden for a draft.' );

		// Default canceller: Lite's cancel flow.
		$controller = new RowActionController( null, static fn (): bool => true );
		$result     = $controller->handle_cancel( [ 'contract_id' => $id ] );

		$this->assertTrue( $result->is_success() );
		$stored = Contracts::get( $id );
		$this->assertNotNull( $stored );
		$this->assertSame( ContractStatus::CANCELLED, $stored->get_status() );
	}

	public function test_the_real_cancel_runs_lite_s_cancel_flow(): void {
		$contract_id = $this->seed_contract();
		$fired       = 0;
		add_action(
			'woocommerce_subscriptions_lite_contract_cancelled',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$result = ( new RowActionController( null, static fn (): bool => true ) )
			->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $fired, 'The Lite cancelled action fires.' );
		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	public function test_the_real_cancel_reports_a_missing_contract_as_not_found(): void {
		$result = ( new RowActionController( null, static fn (): bool => true ) )
			->handle_cancel( [ 'contract_id' => 4242424 ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( 'Subscription not found.', $result->message() );
	}

	public function test_the_real_cancel_reports_a_status_it_cannot_cancel_as_a_failure(): void {
		$contract_id = $this->seed_contract( [ 'status' => ContractStatus::EXPIRED ] );

		$controller = new RowActionController(
			null,
			static fn (): bool => true,
			function ( string $message ): void {
				$this->logged[] = $message;
			}
		);

		$result = $controller->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertCount( 1, $this->logged, 'The refused cancel is logged.' );
		$this->assertSame( ContractStatus::EXPIRED, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_cancel_reports_a_missing_contract_as_an_error(): void {
		// A false canceller return means no such contract.
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
		$this->assertSame( [], $cancel_calls, 'An unauthorised request never reaches the canceller.' );
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

	public function test_cancel_rejects_a_missing_contract_id_before_the_canceller(): void {
		$cancel_calls = [];
		$controller   = $this->make_controller( [ 'cancel_calls' => &$cancel_calls ] );

		$result = $controller->handle_cancel( [] );

		$this->assertSame( RowActionResult::ERROR, $result->type(), 'No contract id resolves to not found.' );
		$this->assertSame( [], $cancel_calls, 'A missing contract id never reaches the canceller.' );
	}

	public function test_redirect_target_returns_the_origin_page_and_falls_back_to_the_list(): void {
		$controller = $this->make_controller();
		$call       = function ( $referer ) {
			return $this->redirect_target( $referer );
		};

		// An action triggered from the detail page returns to the detail page, not the list.
		$detail = admin_url( 'admin.php?page=wc-subscriptions-lite&action=view&id=7' );
		$this->assertSame( $detail, $call->call( $controller, $detail ) );

		// No local referer (missing or empty) falls back to the list.
		$this->assertSame( PageController::page_url(), $call->call( $controller, false ) );
		$this->assertSame( PageController::page_url(), $call->call( $controller, '' ) );
	}

	public function test_register_binds_the_admin_post_handlers(): void {
		RowActionController::register();

		$this->assertNotFalse( has_action( 'admin_post_woocommerce_subscriptions_lite_renew_now' ) );
		$this->assertNotFalse( has_action( 'admin_post_woocommerce_subscriptions_lite_cancel_admin' ) );
	}
}
