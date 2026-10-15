<?php
/**
 * End-to-end conflict scenarios between Lite's flows under the engine's contract lock,
 * driven through the due dispatcher, the order status hooks and the customer action route.
 * Concurrency is simulated by running the second flow from inside the first one (one
 * database connection), as the test transaction requires.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Reactivation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalProcessor;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\Settlement;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @coversNothing
 */
final class LockScenariosTest extends LiteIntegrationTestCase {

	/**
	 * When a contract that is due now was paid (see RenewalScenariosTest).
	 */
	private const PAID_A_PERIOD_AGO = '-33 days';

	public function set_up(): void {
		global $wp_rest_server;

		parent::set_up();

		// A fresh server per test fires `rest_api_init` again, so the engine's routes are present.
		$wp_rest_server = null;
		rest_get_server();
	}

	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The wall clock (UTC).
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * An active monthly contract that is due now, paid with `cod`.
	 *
	 * @param int $customer_id Owning customer.
	 */
	private function make_due_contract( int $customer_id ): int {
		$paid_at = ( new DateTimeImmutable( 'today ' . self::PAID_A_PERIOD_AGO, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );

		return $this->create_contract(
			$customer_id,
			[
				'payment_method' => 'cod',
				'date_paid'      => $paid_at,
			]
		);
	}

	/**
	 * The contract's newest cycle.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function head( int $contract_id ): CycleView {
		$cycles = Contracts::get_cycles( $contract_id, 1 );
		$this->assertCount( 1, $cycles );

		return $cycles[0];
	}

	/**
	 * Lite renewal orders.
	 *
	 * @return array<int, WC_Order>
	 */
	private function renewal_orders(): array {
		$orders = wc_get_orders(
			[
				'created_via' => RenewalOrderBuilder::CREATED_VIA,
				'status'      => 'any',
				'limit'       => -1,
			]
		);

		return is_array( $orders ) ? array_values( $orders ) : [];
	}

	/**
	 * POST a customer action as the portal does.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $action      Action slug.
	 */
	private function post_action( int $contract_id, string $action ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/v3/subscriptions-engine/contracts/' . $contract_id . '/action' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'action'         => $action,
					'extension_slug' => Package::EXTENSION_SLUG,
					'action_args'    => [],
				]
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Make cycle writes that bill a cycle throw, as if the request died right after the
	 * charge, until the returned callback is called.
	 *
	 * @return callable(): void Stops the interruption.
	 */
	private function interrupt_billing_writes(): callable {
		$cycles    = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CYCLES );
		$interrupt = static function ( $query ) use ( $cycles ) {
			if ( is_string( $query ) && 1 === preg_match( "/^UPDATE `?{$cycles}`? /", $query ) && false !== strpos( $query, "'billed'" ) ) {
				throw new RuntimeException( 'The request died.' );
			}
			return $query;
		};
		add_filter( 'query', $interrupt );

		return static function () use ( $interrupt ): void {
			remove_filter( 'query', $interrupt );
		};
	}

	/**
	 * (b) A customer hold arriving while a renewal holds the lock is refused with the retry
	 * conflict, and the renewal completes.
	 */
	public function test_a_customer_hold_during_a_renewal_is_refused_and_the_renewal_completes(): void {
		$this->support_engine_charges( 'cod' );
		$customer_id = $this->create_customer();
		$contract_id = $this->make_due_contract( $customer_id );
		$response    = null;
		add_action(
			'woocommerce_subscriptions_engine_scheduled_payment_cod',
			function () use ( $contract_id, $customer_id, &$response ): void {
				if ( null === $response ) {
					wp_set_current_user( $customer_id );
					$response = $this->as_another_request(
						function () use ( $contract_id ): WP_REST_Response {
							return $this->post_action( $contract_id, 'hold' );
						}
					);
					wp_set_current_user( 0 );
				}
			},
			5
		);

		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_lite_contract_locked', $response->get_data()['code'] ?? null );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertSame( $this->head( $contract_id )->get_ends_at_gmt(), $contract->get_next_payment_gmt() );
		$this->assertNull( $contract->get_lock_state() );
	}

	/**
	 * (c) A reactivation while the due handler clears a held contract's due moment is
	 * refused, so nothing re-arms behind the dispatcher; after the release it succeeds.
	 */
	public function test_a_reactivation_during_the_due_handler_is_refused_and_nothing_re_arms_behind_it(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->seed_contract(
			[
				'status'           => ContractStatus::ON_HOLD,
				'next_payment_gmt' => $this->now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' ),
				'selling_plan_id'  => (int) $this->make_plan()->get_id(),
			]
		);
		$this->store_hold_anchor( $contract_id, $this->now()->modify( '+10 days' )->format( 'Y-m-d H:i:s' ) );
		$conflict = null;
		add_filter(
			'woocommerce_logger_log_message',
			function ( $message ) use ( $contract_id, &$conflict ) {
				if ( null === $conflict && false !== strpos( (string) $message, 'only active contracts renew' ) ) {
					try {
						$this->as_another_request(
							static function () use ( $contract_id ): void {
								( new Reactivation() )->reactivate( $contract_id );
							}
						);
						$conflict = false;
					} catch ( ContractLockedException $e ) {
						$conflict = $e;
					}
				}
				return $message;
			}
		);

		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );

		$this->assertInstanceOf( ContractLockedException::class, $conflict );
		$this->assertSame( 'due', $conflict->get_operation() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt(), 'The handler cleared the due moment and nothing re-armed it.' );

		$reactivated = ( new Reactivation() )->reactivate( $contract_id );
		$this->assertNotNull( $reactivated );
		$this->assertSame( ContractStatus::ACTIVE, $reactivated->get_status() );
		$this->assertNotNull( $reactivated->get_next_payment_gmt() );
		$this->assertSame( 0, $this->charge_count() );
	}

	/**
	 * (d) Two paid signals for one checkout order, the second racing past the origin-order
	 * lookup, create one contract.
	 */
	public function test_a_double_paid_signal_on_checkout_creates_one_contract(): void {
		$customer_id = $this->create_customer();
		$plan        = $this->make_plan();
		$order       = $this->create_subscription_order( $customer_id );
		$this->stamp_plan( $order, $plan );
		$hide = static function ( string $query ): string {
			return str_replace( 'WHERE origin_order_id = ', 'WHERE 1 = 0 AND origin_order_id = ', $query );
		};

		$order->update_status( 'processing' );
		add_filter( 'query', $hide );
		try {
			$order->update_status( 'completed' );
		} finally {
			remove_filter( 'query', $hide );
		}

		$this->assertCount( 1, Contracts::list_for_customer( $customer_id ) );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( ContractCreationHandler::CREATION_DEFERRED_META ) );
	}

	/**
	 * (e) The request dies after the charge, before the cycle is billed: the dispatcher keeps
	 * the lock; after it expires the next dispatch resumes the cycle, finds the order paid
	 * and settles without a second charge.
	 */
	public function test_a_crash_after_the_charge_settles_on_the_next_dispatch_without_a_second_charge(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_due_contract( $this->create_customer() );
		$now         = $this->now();
		$resume      = $this->interrupt_billing_writes();

		$this->assertSame( 0, $this->run_due_dispatch( $now ), 'The handler died.' );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertTrue( $this->renewal_orders()[0]->is_paid(), 'Charged but not recorded.' );
		$this->assertSame( 'due', $this->get_contract( $contract_id )->get_lock_state()['operation'] ?? null, 'The lock is kept.' );
		$this->assertSame( 0, $this->run_due_dispatch( $now ), 'Closed while the lock lives.' );
		$resume();

		$this->assertSame( 1, $this->run_due_dispatch_after_locks_expire( $now ) );

		$this->assertSame( 1, $this->charge_count() );
		$this->assertCount( 1, $this->renewal_orders() );
		$head = $this->head( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertSame( $head->get_ends_at_gmt(), $this->get_contract( $contract_id )->get_next_payment_gmt() );
	}

	/**
	 * (f) A due handler that throws before writing anything keeps the due moment; the
	 * contract is redispatched once the lock expires.
	 */
	public function test_a_throwing_due_handler_is_redispatched_after_the_lock_expires(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_due_contract( $this->create_customer() );
		$due_before  = $this->get_contract( $contract_id )->get_next_payment_gmt();
		$now         = $this->now();
		$cycles      = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CYCLES );
		$interrupt   = static function ( $query ) use ( $cycles ) {
			if ( is_string( $query ) && 1 === preg_match( "/^INSERT INTO `?{$cycles}`? /", $query ) ) {
				throw new RuntimeException( 'The request died.' );
			}
			return $query;
		};
		add_filter( 'query', $interrupt );

		try {
			$this->assertSame( 0, $this->run_due_dispatch( $now ) );
		} finally {
			remove_filter( 'query', $interrupt );
		}

		$this->assertSame( $due_before, $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The due moment is kept.' );
		$this->assertCount( 1, Contracts::get_cycles( $contract_id ) );
		$this->assertSame( 0, $this->run_due_dispatch( $now ) );

		$this->assertSame( 1, $this->run_due_dispatch_after_locks_expire( $now ) );

		$this->assertSame( 1, $this->charge_count() );
		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
	}

	/**
	 * A settlement whose own lock attempt conflicts (a paid webhook during another
	 * operation) is retried through Action Scheduler and settles after the release.
	 */
	public function test_a_paid_webhook_during_another_operation_settles_through_the_retry(): void {
		$this->support_engine_charges( 'cod', 'none' );
		$contract_id = $this->make_due_contract( $this->create_customer() );

		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );
		$order = $this->renewal_orders()[0];
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status(), 'Left for payment.' );

		$lock = $this->hold_lock( $contract_id, 'cancel' );
		$order->payment_complete();
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$this->assertNotFalse(
			as_has_scheduled_action(
				Settlement::RETRY_HOOK,
				[
					'order_id' => $order->get_id(),
					'paid'     => true,
				],
				Settlement::RETRY_GROUP
			)
		);

		$lock->release();
		do_action( Settlement::RETRY_HOOK, $order->get_id() );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	/**
	 * The scheduled moments of the pending charge guards (unpaid settlement actions) for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array<int, int> Unix timestamps.
	 */
	private function pending_settlements( int $order_id ): array {
		$actions = as_get_scheduled_actions(
			[
				'hook'   => Settlement::RETRY_HOOK,
				'args'   => [
					'order_id' => $order_id,
					'paid'     => false,
				],
				'group'  => Settlement::RETRY_GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);

		return array_values(
			array_map(
				static function ( $action ): int {
					return (int) $action->get_schedule()->get_date()->getTimestamp();
				},
				$actions
			)
		);
	}

	/**
	 * A Renew now charge on a held contract (no due moment) whose request dies after the
	 * gateway marked the order paid is settled by the guard scheduled before the charge.
	 */
	public function test_a_charge_cut_short_on_a_held_contract_is_settled_by_its_guard(): void {
		$this->support_engine_charges( 'cod', 'none' );
		$contract_id = $this->make_due_contract( $this->create_customer() );
		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status(), 'Left for payment.' );
		$order = $this->renewal_orders()[0];

		$this->support_engine_charges( 'cod', 'paid' );
		$resume = $this->interrupt_billing_writes();
		$lock   = $this->hold_lock( $contract_id, RenewalProcessor::OPERATION );
		try {
			// Registered but never released here: the request dies holding it.
			HeldLocks::register( $lock );
			( new RenewalProcessor() )->renew_now( $contract_id );
			$this->fail( 'Expected the request to die.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'The request died.', $e->getMessage() );
		} finally {
			HeldLocks::unregister( $lock );
			$resume();
		}

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid(), 'Charged but not recorded.' );
		$this->assertSame( CycleStatus::PENDING, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ON_HOLD, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt(), 'No due moment: the due scan never repairs it.' );
		$guards = $this->pending_settlements( $order->get_id() );
		$this->assertCount( 1, $guards );
		$this->assertGreaterThanOrEqual( time() + ContractLock::TTL_SECONDS - 60, $guards[0], 'Scheduled past the lock expiry.' );
		$charges = $this->charge_count();

		$this->expire_held_locks();
		do_action( Settlement::RETRY_HOOK, $order->get_id() );

		$this->assertSame( CycleStatus::BILLED, $this->head( $contract_id )->get_status() );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::ACTIVE, $contract->get_status() );
		$this->assertNotNull( $contract->get_next_payment_gmt(), 'Re-armed.' );
		$this->assertSame( $charges, $this->charge_count(), 'The guard never charges.' );
	}

	/**
	 * The guard of a charge that settled normally writes nothing when it runs.
	 */
	public function test_a_charge_guard_on_a_settled_renewal_writes_nothing(): void {
		$this->support_engine_charges( 'cod' );
		$contract_id = $this->make_due_contract( $this->create_customer() );
		$this->assertSame( 1, $this->run_due_dispatch( $this->now() ) );
		$order  = $this->renewal_orders()[0];
		$before = $this->get_contract( $contract_id );
		$head   = $this->head( $contract_id );
		$this->assertSame( CycleStatus::BILLED, $head->get_status() );
		$this->assertCount( 1, $this->pending_settlements( $order->get_id() ), 'The guard stays scheduled.' );
		$paid = did_action( 'woocommerce_subscriptions_lite_renewal_paid' );

		$this->expire_held_locks();
		do_action( Settlement::RETRY_HOOK, $order->get_id() );

		$after = $this->get_contract( $contract_id );
		$this->assertSame( $before->get_status(), $after->get_status() );
		$this->assertSame( $before->get_next_payment_gmt(), $after->get_next_payment_gmt() );
		$this->assertSame( $before->get_last_payment_gmt(), $after->get_last_payment_gmt() );
		$this->assertEquals( $head, $this->head( $contract_id ) );
		$this->assertSame( $paid, did_action( 'woocommerce_subscriptions_lite_renewal_paid' ), 'No second paid action.' );
		$this->assertSame( 1, $this->charge_count() );
		$this->assertCount( 1, $this->renewal_orders() );
	}
}
