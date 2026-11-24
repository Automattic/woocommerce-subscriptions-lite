<?php
/**
 * Base test case for Lite integration tests.
 *
 * Schema is installed once in the bootstrap; WP_UnitTestCase wraps each test in
 * a transaction and rolls it back, so seeded rows do not leak between tests.
 *
 * Seeding philosophy: contracts are created through the REAL production path -
 * a WooCommerce order mapped by Lite's checkout handler onto the engine's
 * contracts facade - and moved to other statuses through Lite's lifecycle
 * flows, so the data under test is shaped exactly like production data.
 * Plans are created through the engine's plan write facade and read back as
 * views, as production code reads them. The engine's `Integration\` classes
 * used here (the schema installer for raw rows, and the due dispatcher that tests
 * drive with a fixed clock) are a documented test-only exemption from the
 * "consume the engine via the `Api\` facade only" rule.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLock;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Dispatch\DueDispatcher;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionController;
use Automattic\WooCommerce\SubscriptionsLite\Admin\RowActionResult;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Payments\RenewalGateways;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Renewal\RenewalOrderBuilder;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WC_Order;
use WC_Product_Simple;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Lite integration test case: real-WordPress base class + seeding helpers.
 */
abstract class LiteIntegrationTestCase extends WP_UnitTestCase {

	/**
	 * Owner slug of the contracts {@see self::seed_foreign_contract()} seeds.
	 *
	 * @var string
	 */
	protected const FOREIGN_EXTENSION_SLUG = 'other-extension';

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
	 * Messages the WooCommerce logger received since {@see self::capture_log()}.
	 *
	 * @var array<int, string>
	 */
	protected $logged_messages = [];

	/**
	 * Record every message the WooCommerce logger receives from now on into
	 * {@see self::$logged_messages}.
	 */
	protected function capture_log(): void {
		$this->logged_messages = [];
		add_filter(
			'woocommerce_logger_log_message',
			function ( $message ) {
				$this->logged_messages[] = (string) $message;
				return $message;
			}
		);
	}

	/**
	 * Whether a captured log message contains `$needle`.
	 *
	 * @param string $needle Text to look for.
	 */
	protected function has_logged( string $needle ): bool {
		foreach ( $this->logged_messages as $message ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pending Lite Action Scheduler actions for `$hook`, limited to `$args` when given.
	 *
	 * @param string                    $hook Action hook.
	 * @param array<string, mixed>|null $args Exact action arguments, or null for any.
	 * @return array<int, \ActionScheduler_Action> Actions by id.
	 */
	protected function get_pending_actions( string $hook, ?array $args = null ): array {
		$query = [
			'hook'     => $hook,
			'group'    => 'woocommerce-subscriptions-lite',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		];
		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return as_get_scheduled_actions( $query );
	}

	/**
	 * The order's notes containing `$needle`.
	 *
	 * @param int    $order_id Order id.
	 * @param string $needle   Text to look for.
	 * @return array<int, string>
	 */
	protected function get_order_notes_containing( int $order_id, string $needle ): array {
		$notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order_id ] ), 'content' );

		return array_values(
			array_filter(
				$notes,
				static function ( $note ) use ( $needle ): bool {
					return false !== strpos( (string) $note, $needle );
				}
			)
		);
	}

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
	 * A `billing_policy` in `$overrides` replaces the whole payload, so the positional
	 * `$period`, `$interval` and `$max_cycles` are then ignored (except `$period` in the
	 * default name).
	 *
	 * @param string               $period     Billing period (day / week / month / year).
	 * @param int                  $interval   Billing interval.
	 * @param int|null             $max_cycles Maximum billing cycles, or null for open-ended.
	 * @param array<string, mixed> $overrides  Facade create keys (name, status, extension_slug, billing_policy, pricing_policy, delivery_policy).
	 */
	protected function make_plan( string $period = 'month', int $interval = 1, ?int $max_cycles = null, array $overrides = [] ): PlanView {
		return Plans::create(
			array_merge(
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
			)
		);
	}

	/**
	 * Create a plan with the plan validation action unhooked, as a row written
	 * before a validation rule existed would be stored. Same arguments as
	 * {@see self::make_plan()}, including a `billing_policy` override replacing the
	 * positional billing arguments.
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
		$plan = $this->without_plan_validation(
			static function () use ( $id, $args ): ?PlanView {
				return Plans::update( $id, [ 'extension_slug' => Package::EXTENSION_SLUG ] + $args );
			}
		);
		$this->assertInstanceOf( PlanView::class, $plan );
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
		$this->assertInstanceOf(
			PlanView::class,
			Plans::update(
				$id,
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => $status,
				]
			)
		);
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
	 * requested status through Lite's lifecycle flows.
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
				( new Hold() )->hold( $contract_id );
				break;
			case 'pending-cancellation':
				( new Cancellation() )->cancel_at_period_end( $contract_id );
				break;
			case 'cancelled':
				( new Cancellation() )->cancel( $contract_id );
				break;
			default:
				break;
		}

		return $contract_id;
	}

	/**
	 * Create a bare Lite-owned contract straight through the engine facade, for flow tests
	 * that need an exact status and schedule rather than a checkout-shaped contract.
	 *
	 * @param array<string, mixed> $args Contract fields overriding the defaults (an active
	 *                                   USD contract for customer 1, due 2099-01-01).
	 * @return int Contract id.
	 */
	protected function seed_contract( array $args = [] ): int {
		$contract = $this->create_released_contract(
			array_merge(
				[
					'extension_slug'   => Package::EXTENSION_SLUG,
					'customer_id'      => 1,
					'status'           => ContractStatus::ACTIVE,
					'currency'         => 'USD',
					'start_gmt'        => '2026-01-01 00:00:00',
					'next_payment_gmt' => '2099-01-01 00:00:00',
					'billing_total'    => '19.99',
				],
				$args
			)
		);

		return $contract->get_id();
	}

	/**
	 * Create an active contract of another extension for `$customer_id`, for tests that
	 * check Lite leaves other extensions' contracts alone.
	 *
	 * @param int                  $customer_id Customer id.
	 * @param array<string, mixed> $args        Contract fields overriding the defaults.
	 * @return int Contract id.
	 */
	protected function seed_foreign_contract( int $customer_id, array $args = [] ): int {
		return $this->seed_contract(
			array_merge(
				[
					'extension_slug' => self::FOREIGN_EXTENSION_SLUG,
					'customer_id'    => $customer_id,
				],
				$args
			)
		);
	}

	/**
	 * Create a contract through the engine facade and release the lock its creation holds.
	 *
	 * @param array<string, mixed> $args Contract fields.
	 * @return ContractView The contract as read after the release.
	 */
	protected function create_released_contract( array $args ): ContractView {
		$created = Contracts::create( $args );
		$this->assertNotNull( $created );
		$created['lock']->release();

		return $this->get_contract( $created['contract']->get_id() );
	}

	/**
	 * Write a raw status to a contract row, bypassing status validation (e.g. an
	 * unregistered legacy status).
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $status      Stored status value.
	 */
	protected function store_raw_status( int $contract_id, string $status ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACTS ), [ 'status' => $status ], [ 'id' => $contract_id ] );
	}

	/**
	 * A contract that must exist (narrows the nullable read).
	 *
	 * @param int $contract_id Contract id.
	 */
	protected function get_contract( int $contract_id ): ContractView {
		$contract = Contracts::get( $contract_id );
		$this->assertInstanceOf( ContractView::class, $contract );

		return $contract;
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
	 * Set a contract's status directly - for states no lifecycle flow produces
	 * (e.g. `expired`). Prefer {@see self::create_contract()} status transitions.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $status      A registered contract status.
	 */
	protected function force_contract_status( int $contract_id, string $status ): void {
		Contracts::update(
			$contract_id,
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'status'         => $status,
			]
		);
	}

	/**
	 * Create a renewal order for a contract and append the cycle it bills, the shape the
	 * related-orders and unpaid-renewal reads query for.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param int                  $customer_id Owning customer.
	 * @param array<string, mixed> $args        total, status (order), date_created, cycle_status (default billed),
	 *                                          ends_at_gmt (default one month after the cycle start).
	 */
	protected function create_renewal_order( int $contract_id, int $customer_id, array $args = [] ): WC_Order {
		$order = wc_create_order(
			[
				'customer_id' => $customer_id,
				'created_via' => RenewalOrderBuilder::CREATED_VIA,
			]
		);
		$order->set_total( (string) ( $args['total'] ?? '19.99' ) );
		$order->set_status( (string) ( $args['status'] ?? 'completed' ) );
		if ( isset( $args['date_created'] ) ) {
			$order->set_date_created( (string) $args['date_created'] );
		}
		$order->save();

		$utc        = new DateTimeZone( 'UTC' );
		$head       = Contracts::get_cycles( $contract_id, 1 )[0] ?? null;
		$starts_at  = null === $head ? new DateTimeImmutable( '2026-01-15 00:00:00', $utc ) : new DateTimeImmutable( $head->get_ends_at_gmt(), $utc );
		$order_id   = $order->get_id();
		$cycle_args = [
			'status'         => (string) ( $args['cycle_status'] ?? CycleStatus::BILLED ),
			'count'          => null === $head ? 1 : (int) $head->get_count() + 1,
			'starts_at_gmt'  => $starts_at,
			'ends_at_gmt'    => isset( $args['ends_at_gmt'] ) ? new DateTimeImmutable( (string) $args['ends_at_gmt'], $utc ) : $starts_at->modify( '+1 month' ),
			'expected_total' => $order->get_total(),
			'currency'       => 'USD',
			'order_id'       => $order_id,
		];
		$this->add_cycle_with_lock( $contract_id, $cycle_args );

		return $order;
	}

	/**
	 * Dispatch a Store API request (`wc/store/v1`) for the current user and cart, with a
	 * valid nonce, as the block checkout sends it.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route under `wc/store/v1`, such as `/cart`.
	 * @param array<string, mixed> $body   Body parameters.
	 */
	protected function dispatch_store_api( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wc/store/v1' . $route );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		if ( [] !== $body ) {
			$request->set_body_params( $body );
		}

		return rest_do_request( $request );
	}

	/**
	 * Outcome of the stub charge handler installed by {@see self::support_engine_charges()}:
	 * `paid`, `failed`, `on-hold` (an asynchronous charge), `none` (the order is left pending)
	 * or `throw` (the gateway throws, leaving the order pending).
	 *
	 * @var string
	 */
	protected $charge_outcome = 'paid';

	/**
	 * Orders the stub charge handler received, in call order.
	 *
	 * @var array<int, int>
	 */
	protected $charged_order_ids = [];

	/**
	 * Gateways the stub charge handler is installed for.
	 *
	 * @var array<string, bool>
	 */
	private $engine_charge_gateways = [];

	/**
	 * The deferral reason recorded on an order, read fresh, or '' when none.
	 *
	 * @param WC_Order $order The order.
	 */
	protected function deferral_reason( WC_Order $order ): string {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );

		return (string) $fresh->get_meta( ContractCreationHandler::CREATION_DEFERRED_META );
	}

	/**
	 * Make `$gateway` support engine charges and automatic renewal payments (the engine
	 * feature and {@see RenewalGateways::FEATURE}) and answer each charge
	 * with `$outcome` (see {@see self::$charge_outcome}). Calling it again only changes the
	 * outcome. The hooks are restored with the rest after the test. Use it when a test needs
	 * renewal charges; the suite's default `dummy` gateway only passes the renewal check
	 * (it is registered by the bootstrap and charges nothing).
	 *
	 * @param string $gateway Gateway id (a core gateway such as `cod`).
	 * @param string $outcome Charge outcome.
	 */
	protected function support_engine_charges( string $gateway, string $outcome = 'paid' ): void {
		$this->charge_outcome = $outcome;
		if ( isset( $this->engine_charge_gateways[ $gateway ] ) ) {
			return;
		}
		$this->engine_charge_gateways[ $gateway ] = true;

		add_filter(
			'woocommerce_payment_gateway_supports',
			static function ( $supported, $feature, $payment_gateway ) use ( $gateway ) {
				if ( in_array( $feature, [ Payments::GATEWAY_FEATURE, RenewalGateways::FEATURE ], true ) && is_object( $payment_gateway ) && $gateway === $payment_gateway->id ) {
					return true;
				}
				return $supported;
			},
			10,
			3
		);
		add_action(
			'woocommerce_subscriptions_engine_scheduled_payment_' . $gateway,
			function ( $order ): void {
				if ( ! $order instanceof WC_Order ) {
					return;
				}
				$this->charged_order_ids[] = $order->get_id();
				switch ( $this->charge_outcome ) {
					case 'paid':
						$order->payment_complete();
						break;
					case 'failed':
						$order->update_status( 'failed' );
						break;
					case 'on-hold':
						$order->update_status( 'on-hold' );
						break;
					case 'throw':
						throw new RuntimeException( 'The gateway is unreachable.' );
					default:
						break;
				}
			}
		);
	}

	/**
	 * Record every engine charge request for `$gateway` in {@see self::$charged_order_ids}
	 * while the gateway does not support engine charges, so a test can prove none was made.
	 * The gateway answers the feature check only inside `Payments::process_scheduled_payment()` (so the
	 * charge reaches the spy should anyone call it); Lite's own check still reads no.
	 *
	 * @param string $gateway Gateway id.
	 */
	protected function spy_charge_attempts( string $gateway ): void {
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function ( $supported, $feature, $payment_gateway ) use ( $gateway ) {
				if ( Payments::GATEWAY_FEATURE === $feature && is_object( $payment_gateway ) && $gateway === $payment_gateway->id && self::inside_charge() ) {
					return true;
				}
				return $supported;
			},
			10,
			3
		);
		add_action(
			'woocommerce_subscriptions_engine_scheduled_payment_' . $gateway,
			function ( $order ): void {
				if ( $order instanceof WC_Order ) {
					$this->charged_order_ids[] = $order->get_id();
				}
			}
		);
	}

	/**
	 * Whether `Payments::process_scheduled_payment()` is on the call stack.
	 */
	private static function inside_charge(): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- a test spy reads its caller.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( Payments::class === ( $frame['class'] ?? '' ) && 'process_scheduled_payment' === $frame['function'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many charges the stub charge handler received.
	 */
	protected function charge_count(): int {
		return count( $this->charged_order_ids );
	}

	/**
	 * Run one engine due-dispatch batch at `$now`.
	 *
	 * @param DateTimeImmutable $now The scan moment.
	 * @return int The number of contracts whose owner was notified.
	 */
	protected function run_due_dispatch( DateTimeImmutable $now ): int {
		return ( new DueDispatcher() )->run_batch( $now );
	}

	/**
	 * Run one engine due-dispatch batch once every lock taken now has expired: the database
	 * clock (which lock expiry is measured on) and the scan moment both move past the lock TTL.
	 *
	 * @param DateTimeImmutable $now The moment the earlier batch ran.
	 * @return int The number of contracts whose owner was notified.
	 */
	protected function run_due_dispatch_after_locks_expire( DateTimeImmutable $now ): int {
		$this->expire_held_locks();

		return $this->run_due_dispatch( $now->modify( sprintf( '+%d seconds', ContractLock::TTL_SECONDS + 60 ) ) );
	}

	/**
	 * Run the admin Renew now action on the contract as an administrator, who stays the
	 * current user for the rest of the test.
	 *
	 * @param int $contract_id Contract id.
	 */
	protected function renew_now_as_admin( int $contract_id ): RowActionResult {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		return ( new RowActionController() )->handle_renew_now( [ 'contract_id' => $contract_id ] );
	}

	/**
	 * Acquire the contract's lock in the test, as a concurrent operation would hold it.
	 *
	 * @param int    $contract_id    Contract id.
	 * @param string $operation      Operation label.
	 * @param string $extension_slug The contract's owner.
	 */
	protected function hold_lock( int $contract_id, string $operation = 'test', string $extension_slug = Package::EXTENSION_SLUG ): ContractLock {
		$lock = Contracts::lock( $contract_id, $extension_slug, $operation );
		$this->assertNotNull( $lock, 'The lock is free.' );

		return $lock;
	}

	/**
	 * Append a cycle under a test lock on the contract, released right after.
	 *
	 * @param int                  $contract_id    Contract id.
	 * @param array<string, mixed> $args           Cycle fields.
	 * @param string               $extension_slug The contract's owner.
	 */
	protected function add_cycle_with_lock( int $contract_id, array $args, string $extension_slug = Package::EXTENSION_SLUG ): ?CycleView {
		$lock = $this->hold_lock( $contract_id, 'test', $extension_slug );
		try {
			return Contracts::add_cycle( $contract_id, [ 'extension_slug' => $extension_slug ] + $args, $lock );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Transition a cycle under a test lock on its contract, released right after.
	 *
	 * @param int                  $contract_id    The cycle's contract id.
	 * @param int                  $cycle_id       Cycle id.
	 * @param array<string, mixed> $args           Transition arguments.
	 * @param string               $extension_slug The contract's owner.
	 */
	protected function transition_cycle_with_lock( int $contract_id, int $cycle_id, array $args, string $extension_slug = Package::EXTENSION_SLUG ): bool {
		$lock = $this->hold_lock( $contract_id, 'test', $extension_slug );
		try {
			return Contracts::transition_cycle( $cycle_id, [ 'extension_slug' => $extension_slug ] + $args, $lock );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Assert `$result` is Lite's lock conflict on the contract, labelled with `$operation`.
	 *
	 * @param mixed  $result      The flow's result.
	 * @param int    $contract_id Contract id.
	 * @param string $operation   The holder's operation label.
	 */
	protected function assert_lock_conflict( $result, int $contract_id, string $operation ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_subscriptions_lite_contract_locked', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( $contract_id, $data['contract_id'] ?? null );
		$this->assertSame( $operation, $data['operation'] ?? null );
	}

	/**
	 * Assert the contract's lock is released and no longer registered as held.
	 *
	 * @param int $contract_id Contract id.
	 */
	protected function assert_lock_released( int $contract_id ): void {
		$this->assertNull( $this->get_contract( $contract_id )->get_lock_state(), 'The lock is released.' );
		$this->assertNull( HeldLocks::get( $contract_id ), 'The lock is unregistered.' );
	}

	/**
	 * Pin the session's database clock at `$moment`, so locks are live or expired as of then.
	 * The clock is restored after the test.
	 *
	 * @param DateTimeImmutable $moment The database's now.
	 */
	protected function expire_lock_at( DateTimeImmutable $moment ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SET timestamp = %d', $moment->getTimestamp() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Move the database clock past the TTL of every lock taken until now.
	 */
	protected function expire_held_locks(): void {
		$this->expire_lock_at( new DateTimeImmutable( sprintf( '+%d seconds', ContractLock::TTL_SECONDS + 60 ), new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Run `$flow` as another request would: with none of this request's held locks visible
	 * (Lite flows otherwise reuse a lock held in the same request). Test-only reflection.
	 *
	 * @template T
	 * @param callable(): T $flow The other request's work.
	 * @return T
	 */
	protected function as_another_request( callable $flow ) {
		$locks = new \ReflectionProperty( HeldLocks::class, 'locks' );
		$locks->setAccessible( true );
		$held = $locks->getValue();
		$locks->setValue( null, [] );

		try {
			return $flow();
		} finally {
			$locks->setValue( null, $held );
		}
	}

	/**
	 * Seed a contract's hold anchor (or remove it when null) as an unlocked write.
	 *
	 * @param int         $contract_id Contract id.
	 * @param string|null $anchor      GMT anchor, or null.
	 */
	protected function store_hold_anchor( int $contract_id, ?string $anchor ): void {
		if ( null === $anchor ) {
			Contracts::delete_meta( $contract_id, Package::EXTENSION_SLUG, HoldAnchor::META_KEY );
			return;
		}

		Contracts::update_meta( $contract_id, Package::EXTENSION_SLUG, HoldAnchor::META_KEY, $anchor );
	}

	/**
	 * The `enabled` value of each registered gateway before {@see self::enable_only_gateways()}
	 * first changed it in this test.
	 *
	 * @var array<string, string>
	 */
	private $gateways_enabled_before = [];

	/**
	 * Enable exactly `$gateway_ids` and disable every other registered gateway. The enabled
	 * values are restored after the test.
	 *
	 * @param string[] $gateway_ids Gateway ids to enable.
	 */
	protected function enable_only_gateways( array $gateway_ids ): void {
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			if ( ! array_key_exists( $gateway_id, $this->gateways_enabled_before ) ) {
				$this->gateways_enabled_before[ $gateway_id ] = $gateway->enabled;
			}
			$gateway->enabled = in_array( $gateway_id, $gateway_ids, true ) ? 'yes' : 'no';
		}
	}

	/**
	 * `$wpdb->suppress_errors()` before {@see self::fail_next_query()} first suppressed them.
	 *
	 * @var bool|null
	 */
	private $suppressed_errors = null;

	/**
	 * Patterns passed to {@see self::fail_next_query()} that have not broken a statement yet.
	 *
	 * @var array<int, string>
	 */
	private $unfired_query_failures = [];

	/**
	 * Replace the next statement matching `$pattern` (after `$skip` matches) with invalid SQL, so
	 * it fails with a database error. Error output is suppressed until the test ends, and the test
	 * fails if no statement was broken by then.
	 *
	 * @param string $pattern Regex the statement to break must match.
	 * @param int    $skip    Matching statements to let through first.
	 */
	protected function fail_next_query( string $pattern, int $skip = 0 ): void {
		global $wpdb;

		$this->unfired_query_failures[] = $pattern;
		$failure                        = array_key_last( $this->unfired_query_failures );

		$break = function ( $query ) use ( $pattern, $failure, &$skip, &$break ) {
			if ( is_string( $query ) && 1 === preg_match( $pattern, $query ) && 0 === $skip-- ) {
				remove_filter( 'query', $break );
				unset( $this->unfired_query_failures[ $failure ] );
				return 'SELECT * FROM table_that_does_not_exist_for_tests';
			}
			return $query;
		};
		add_filter( 'query', $break );

		if ( null === $this->suppressed_errors ) {
			$this->suppressed_errors = $wpdb->suppress_errors( true );
		}
	}

	/**
	 * Replace every statement matching `$pattern` with invalid SQL until the test ends, so each
	 * one fails with a database error. Error output is suppressed until the test ends.
	 *
	 * @param string $pattern Regex the statements to break must match.
	 */
	protected function fail_every_query( string $pattern ): void {
		global $wpdb;

		add_filter(
			'query',
			static function ( $query ) use ( $pattern ) {
				return is_string( $query ) && 1 === preg_match( $pattern, $query ) ? 'SELECT * FROM table_that_does_not_exist_for_tests' : $query;
			}
		);

		if ( null === $this->suppressed_errors ) {
			$this->suppressed_errors = $wpdb->suppress_errors( true );
		}
	}

	/**
	 * The store's `timezone_string` and `gmt_offset` before this test pinned them.
	 *
	 * @var array{0: mixed, 1: mixed}|null
	 */
	private $timezone_before;

	/**
	 * Pin the store time zone to UTC, so date tests never depend on the site's setting.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_store_timezone( 'UTC' );
	}

	/**
	 * Set the store time zone for this test: a time zone identifier, or an empty one with a
	 * manual UTC offset in hours (an offset-only store). Restored in tear_down.
	 *
	 * @param string $timezone_string A time zone identifier, or '' for an offset-only store.
	 * @param float  $gmt_offset      UTC offset in hours, read when `$timezone_string` is ''.
	 */
	protected function set_store_timezone( string $timezone_string, float $gmt_offset = 0.0 ): void {
		if ( null === $this->timezone_before ) {
			$this->timezone_before = [ get_option( 'timezone_string' ), get_option( 'gmt_offset' ) ];
		}
		update_option( 'timezone_string', $timezone_string );
		update_option( 'gmt_offset', $gmt_offset );
	}

	/**
	 * Restore error output after a test that broke a query, the database clock after a test that
	 * pinned it, the store time zone, and the gateways' enabled values after a test that changed them.
	 */
	public function tear_down(): void {
		global $wpdb;

		$unfired_query_failures       = $this->unfired_query_failures;
		$this->unfired_query_failures = [];

		if ( null !== $this->suppressed_errors ) {
			$wpdb->suppress_errors( $this->suppressed_errors );
			$this->suppressed_errors = null;
		}

		if ( null !== $this->timezone_before ) {
			update_option( 'timezone_string', $this->timezone_before[0] );
			update_option( 'gmt_offset', $this->timezone_before[1] );
			$this->timezone_before = null;
		}

		if ( [] !== $this->gateways_enabled_before ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			foreach ( $this->gateways_enabled_before as $gateway_id => $enabled ) {
				$gateways[ $gateway_id ]->enabled = $enabled;
			}
		}

		$wpdb->query( 'SET timestamp = DEFAULT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		parent::tear_down();

		// After the parent tear-down, so the test's database changes are still rolled back.
		if ( [] !== $unfired_query_failures ) {
			$this->fail( 'fail_next_query() never broke a statement matching: ' . implode( ', ', $unfired_query_failures ) );
		}
	}

	/**
	 * Regex for the engine's plan reads (`Plans::get()`, `Plans::list()`), for {@see self::fail_next_query()}.
	 */
	protected static function plan_read(): string {
		return '/FROM ' . preg_quote( SchemaInstaller::get_table_name( SchemaInstaller::TABLE_PLANS ), '/' ) . ' /';
	}
}
