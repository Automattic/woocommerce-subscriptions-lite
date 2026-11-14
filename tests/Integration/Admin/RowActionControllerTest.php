<?php
/**
 * Integration tests for the admin row-action handlers.
 *
 * The handlers back the Renew now / Cancel actions on the admin subscriptions
 * screens: the decision method verifies the capability, then runs the renewal or
 * cancel, returning a {@see RowActionResult}. Every branch runs the real path: the
 * current user's capability, Lite's renewal and cancel over real contracts, and the
 * WooCommerce logger (captured through its message filter). Failures are reproduced
 * through WordPress hooks. The hook registration is asserted against the real hook
 * table. The nonce is a request-boundary concern (check_admin_referer), out of scope here.
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
	 * Lite cancelled actions fired since {@see self::count_cancelled_actions()}.
	 *
	 * @var int
	 */
	private $cancelled_actions = 0;

	/**
	 * Error messages logged under Lite's source since {@see self::set_up()}, per log handler.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $error_logs = [];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->error_logs = [];
		add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level, $context, $handler ) {
				if ( 'error' === $level && Package::EXTENSION_SLUG === ( $context['source'] ?? null ) ) {
					$this->error_logs[ spl_object_hash( $handler ) ][] = (string) $message;
				}
				return $message;
			},
			10,
			4
		);
	}

	/**
	 * The row-action failures the controller logged, as one log handler received them
	 * (the logger filters each message once per handler).
	 *
	 * @return array<int, string>
	 */
	private function get_failure_logs(): array {
		$logs = reset( $this->error_logs );

		return array_values(
			array_filter(
				false === $logs ? [] : $logs,
				static function ( string $message ): bool {
					return false !== strpos( $message, 'Admin subscription ' );
				}
			)
		);
	}

	/**
	 * Count the Lite cancelled actions fired from now on into {@see self::$cancelled_actions}.
	 */
	private function count_cancelled_actions(): void {
		$this->cancelled_actions = 0;
		add_action(
			'woocommerce_subscriptions_lite_contract_cancelled',
			function (): void {
				++$this->cancelled_actions;
			}
		);
	}

	public function test_renew_now_renews_an_active_contract_and_reports_the_order(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $this->charge_count() );
		$order = wc_get_order( $this->charged_order_ids[0] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertStringContainsString( '#' . $order->get_order_number(), $result->message(), 'The renewal order number is surfaced.' );
		$this->assert_lock_released( $contract_id );
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

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::INFO, $result->type(), 'A skipped renewal is informational, not an error.' );
		$this->assertSame( 0, $this->charge_count() );
	}

	public function test_renew_now_retries_a_failed_renewal_on_hold(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
		$this->support_engine_charges( 'cod', 'paid' );

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 2, $this->charge_count() );
		$this->assertSame( $this->charged_order_ids[0], $this->charged_order_ids[1], 'The failed order is charged again.' );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_renew_now_reports_a_failed_charge_as_an_error(): void {
		$this->support_engine_charges( 'cod', 'failed' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( 1, $this->charge_count() );
	}

	public function test_renew_now_reports_a_manual_renewal_order_as_awaiting_payment(): void {
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'bacs' ] );

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::INFO, $result->type() );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_renew_now_is_forbidden_without_the_capability(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		wp_set_current_user( $this->create_customer() );

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( 'You do not have permission to manage subscriptions.', $result->message() );
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

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringNotContainsString( 'gateway lookup failed', $result->message(), 'The internal error detail is not leaked to the merchant.' );
		$this->assertStringContainsString( 'logs', $result->message(), 'The merchant is pointed at the logs.' );
		$logs = $this->get_failure_logs();
		$this->assertCount( 1, $logs, 'The failure is logged once.' );
		$this->assertSame( sprintf( 'Admin subscription renew failed for #%d: gateway lookup failed', $contract_id ), $logs[0], 'The full exception detail is logged.' );
	}

	public function test_a_draft_offers_cancel_and_the_real_cancel_resolves_it(): void {
		$draft = $this->create_released_contract( [ 'extension_slug' => Package::EXTENSION_SLUG ] );
		$id    = $draft->get_id();

		ob_start();
		Actions::output( $draft );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( PageController::ACTION_CANCEL, $html, 'The detail screen offers Cancel for a draft.' );
		$this->assertStringNotContainsString( PageController::ACTION_RENEW_NOW, $html, 'Renew now stays hidden for a draft.' );

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => $id ] );

		$this->assertTrue( $result->is_success() );
		$stored = Contracts::get( $id );
		$this->assertNotNull( $stored );
		$this->assertSame( ContractStatus::CANCELLED, $stored->get_status() );
	}

	public function test_cancel_runs_lite_s_cancel_flow_for_an_authorised_request(): void {
		$contract_id = $this->seed_contract();
		$this->count_cancelled_actions();

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( sprintf( 'Subscription #%d cancelled.', $contract_id ), $result->message() );
		$this->assertSame( 1, $this->cancelled_actions, 'The Lite cancelled action fires.' );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $contract_id )->get_status() );
		$this->assertNull( $this->get_contract( $contract_id )->get_next_payment_gmt() );
		$this->assert_lock_released( $contract_id );
	}

	public function test_cancel_reports_a_missing_contract_as_not_found(): void {
		$this->count_cancelled_actions();

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => 4242424 ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( 'Subscription not found.', $result->message() );
		$this->assertSame( 0, $this->cancelled_actions );
		$this->assertSame( [], $this->get_failure_logs(), 'A plain not-found is not logged as a failure.' );
	}

	public function test_cancel_reports_a_status_it_cannot_cancel_as_a_failure(): void {
		$contract_id = $this->seed_contract( [ 'status' => ContractStatus::EXPIRED ] );

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertSame( 'The subscription could not be cancelled. Check the WooCommerce logs for details.', $result->message() );
		$this->assertCount( 1, $this->get_failure_logs(), 'The refused cancel is logged.' );
		$this->assertSame( ContractStatus::EXPIRED, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_cancel_is_forbidden_without_the_capability(): void {
		$contract_id = $this->seed_contract();
		$this->count_cancelled_actions();
		wp_set_current_user( $this->create_customer() );

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertTrue( $result->is_forbidden() );
		$this->assertSame( 'You do not have permission to manage subscriptions.', $result->message() );
		$this->assertSame( 0, $this->cancelled_actions, 'An unauthorised request never reaches the cancel.' );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_cancel_logs_the_exception_and_surfaces_a_generic_message(): void {
		$contract_id = $this->seed_contract();
		// A cancelled-action callback that throws, on the real cancel path.
		add_action(
			'woocommerce_subscriptions_lite_contract_cancelled',
			static function (): void {
				throw new RuntimeException( 'storage offline' );
			}
		);

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::ERROR, $result->type() );
		$this->assertStringNotContainsString( 'storage offline', $result->message(), 'The internal error detail is not leaked to the merchant.' );
		$this->assertStringContainsString( 'logs', $result->message(), 'The merchant is pointed at the logs.' );
		$logs = $this->get_failure_logs();
		$this->assertCount( 1, $logs, 'The failure is logged once.' );
		$this->assertSame( sprintf( 'Admin subscription cancel failed for #%d: storage offline', $contract_id ), $logs[0], 'The full exception detail is logged.' );
		$this->assert_lock_released( $contract_id );
	}

	public function test_cancel_rejects_a_missing_contract_id_before_the_cancel(): void {
		$this->count_cancelled_actions();

		$result = ( new RowActionController() )->handle_cancel( [] );

		$this->assertSame( RowActionResult::ERROR, $result->type(), 'No contract id resolves to not found.' );
		$this->assertSame( 'Subscription not found.', $result->message() );
		$this->assertSame( 0, $this->cancelled_actions, 'A missing contract id never reaches the cancel.' );
		$this->assertSame( [], $this->get_failure_logs() );
	}

	public function test_redirect_target_returns_the_origin_page_and_falls_back_to_the_list(): void {
		$controller = new RowActionController();
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

	public function test_renew_now_under_another_operations_lock_reports_the_operation_in_progress(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->create_contract( $this->create_customer(), [ 'payment_method' => 'cod' ] );
		$this->hold_lock( $contract_id, 'engine_due_dispatch' );

		$result = ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::INFO, $result->type() );
		$this->assertSame( sprintf( 'Another change to subscription #%d is in progress (renewal). Try again shortly.', $contract_id ), $result->message() );
		$this->assertSame( [], $this->get_failure_logs(), 'A conflict is not an error.' );
		$this->assertSame( 0, $this->charge_count() );
		$this->assertCount( 1, Contracts::get_cycles( $contract_id ), 'Nothing renewed.' );
	}

	public function test_cancel_under_another_operations_lock_reports_the_operation_in_progress(): void {
		$contract_id = $this->seed_contract();
		$this->hold_lock( $contract_id, 'renewal' );

		$result = ( new RowActionController() )->handle_cancel( [ 'contract_id' => $contract_id ] );

		$this->assertSame( RowActionResult::INFO, $result->type() );
		$this->assertSame( sprintf( 'Another change to subscription #%d is in progress (renewal). Try again shortly.', $contract_id ), $result->message() );
		$this->assertSame( [], $this->get_failure_logs() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}
}
