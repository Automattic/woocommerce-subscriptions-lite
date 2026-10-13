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

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\CycleStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Dispatch\DueDispatcher;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Cart\CartPlanHooks;
use Automattic\WooCommerce\SubscriptionsLite\Checkout\ContractCreationHandler;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Cancellation;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\Hold;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalOrderBuilder;
use Automattic\WooCommerce\SubscriptionsLite\Renewal\RenewalProcessor;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
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
		$contract = Contracts::create(
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
		Contracts::update( $contract_id, [ 'status' => $status ] );
	}

	/**
	 * Create a renewal order for a contract and append the cycle it bills, the shape the
	 * related-orders and unpaid-renewal reads query for.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param int                  $customer_id Owning customer.
	 * @param array<string, mixed> $args        total, status (order), date_created, cycle_status (default billed).
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
			'ends_at_gmt'    => $starts_at->modify( '+1 month' ),
			'expected_total' => $order->get_total(),
			'currency'       => 'USD',
			'order_id'       => $order_id,
		];
		Contracts::add_cycle( $contract_id, $cycle_args );

		return $order;
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
	 * Make `$gateway` support engine charges and automatic renewal payments (the engine
	 * feature and {@see RenewalProcessor::GATEWAY_RENEWAL_FEATURE}) and answer each charge
	 * with `$outcome` (see {@see self::$charge_outcome}). Calling it again only changes the
	 * outcome. The hooks are restored with the rest after the test.
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
				if ( in_array( $feature, [ Payments::GATEWAY_FEATURE, RenewalProcessor::GATEWAY_RENEWAL_FEATURE ], true ) && is_object( $payment_gateway ) && $gateway === $payment_gateway->id ) {
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
	 * Run one engine due-dispatch batch once the dispatch claims taken at `$now` have expired.
	 * Claims are measured on the database clock, so the session clock is moved past the claim
	 * along with the scan moment, then restored.
	 *
	 * @param DateTimeImmutable $now The moment the earlier batch ran.
	 * @return int The number of contracts whose owner was notified.
	 */
	protected function run_due_dispatch_after_claims_expire( DateTimeImmutable $now ): int {
		global $wpdb;

		$later = $now->modify( sprintf( '+%d seconds', DueDispatcher::CLAIM_TTL_SECONDS + 60 ) );
		$wpdb->query( $wpdb->prepare( 'SET timestamp = %d', $later->getTimestamp() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			return $this->run_due_dispatch( $later );
		} finally {
			$wpdb->query( 'SET timestamp = DEFAULT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}
}
