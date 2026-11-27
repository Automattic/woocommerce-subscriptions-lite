<?php
/**
 * Integration tests for the subscriptions personal data eraser.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Privacy;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ValidationException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HoldAnchor;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Privacy\Eraser;
use Automattic\WooCommerce\SubscriptionsLite\Privacy\ErasureSetting;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WP_Error;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Privacy\Eraser
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Privacy\RequesterContracts
 */
final class EraserTest extends LiteIntegrationTestCase {

	private const EMAIL = 'grace@example.com';

	private const WARNING = 'This erasure may remove the customer\'s saved payment methods, so the subscription\'s automatic renewals may fail.';

	private const LOCKED = 'Another change to subscription #%d is in progress (renewal). Its personal data was not removed. Run the erasure again in a few minutes.';

	/**
	 * The requester's account.
	 *
	 * @var int
	 */
	private $customer_id;

	public function set_up(): void {
		parent::set_up();
		$this->customer_id = $this->create_customer( [ 'user_email' => self::EMAIL ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Turn subscription erasure on or off.
	 *
	 * @param bool $enabled Whether erasure requests remove subscription data.
	 */
	private function set_erasure( bool $enabled ): void {
		update_option( ErasureSetting::OPTION, $enabled ? 'yes' : 'no' );
	}

	/**
	 * Run the registered eraser.
	 *
	 * @param int $page Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	private function erase( int $page = 1 ): array {
		$erasers  = apply_filters( 'wp_privacy_personal_data_erasers', [] );
		$response = call_user_func( $erasers[ Package::EXTENSION_SLUG ]['callback'], self::EMAIL, $page );
		$this->assertIsArray( $response );

		return $response;
	}

	/**
	 * Run the eraser as WordPress does: pages from 1 while `done` is false.
	 *
	 * @return array<int, array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}>
	 */
	private function erase_all(): array {
		$responses = [];
		$page      = 1;
		do {
			$response    = $this->erase( $page++ );
			$responses[] = $response;
			$this->assertLessThan( 20, $page, 'The eraser terminates.' );
		} while ( ! $response['done'] );

		return $responses;
	}

	/**
	 * Assert a contract carries the anonymised addresses and no payment references or customer.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function assert_erased( int $contract_id ): void {
		$contract  = $this->get_contract( $contract_id );
		$addresses = (array) $contract->get_addresses();

		$this->assertSame( '[deleted]', $addresses['billing']['first_name'] );
		$this->assertSame( '[deleted]', $addresses['billing']['last_name'] );
		$this->assertSame( '[deleted]', $addresses['billing']['address_1'] );
		$this->assertSame( '[deleted]', $addresses['billing']['city'] );
		$this->assertSame( '[deleted]', $addresses['billing']['postcode'] );
		$this->assertSame( '', $addresses['billing']['country'] );
		$this->assertSame( 'deleted@site.invalid', $addresses['billing']['email'] );
		$this->assertSame( '000 0000 0000', $addresses['billing']['phone'] );
		$this->assertSame( '[deleted]', $addresses['shipping']['address_1'] );
		$this->assertEmpty( $addresses['billing']['company'], 'An empty field stays empty.' );
		$this->assertEmpty( $addresses['shipping']['email'], 'An empty field stays empty.' );
		$this->assertNull( $contract->get_payment_token_id() );
		$this->assertNull( $contract->get_payment_method_title() );
		$this->assertNull( $contract->get_customer_id() );
		$this->assert_lock_released( $contract_id );
	}

	/**
	 * Assert a contract still carries the customer's data.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function assert_intact( int $contract_id ): void {
		$contract  = $this->get_contract( $contract_id );
		$addresses = (array) $contract->get_addresses();

		$this->assertSame( $this->customer_id, $contract->get_customer_id() );
		$this->assertSame( 'ada@example.com', $addresses['billing']['email'] );
		$this->assertSame( 'Ada', $addresses['billing']['first_name'] );
	}

	/**
	 * Give a contract a payment token reference, as a checkout with a saved method does.
	 *
	 * @param int $contract_id Contract id.
	 */
	private function set_payment_token( int $contract_id ): void {
		Contracts::update(
			$contract_id,
			[
				'extension_slug'   => Package::EXTENSION_SLUG,
				'payment_token_id' => 42,
			]
		);
	}

	public function test_one_eraser_is_registered(): void {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', [] );

		$this->assertArrayHasKey( Package::EXTENSION_SLUG, $erasers );
		$this->assertSame( 'Subscriptions', $erasers[ Package::EXTENSION_SLUG ]['eraser_friendly_name'] );
		$this->assertIsCallable( $erasers[ Package::EXTENSION_SLUG ]['callback'] );
	}

	public function test_with_erasure_off_every_subscription_is_retained_and_nothing_is_written(): void {
		$active    = $this->create_contract( $this->customer_id );
		$cancelled = $this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$before    = [ $this->get_contract( $active ), $this->get_contract( $cancelled ) ];

		$response = $this->erase();

		$this->assertFalse( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
		$this->assertSame(
			[
				sprintf( 'Personal data within subscription #%d has been retained. %s', $active, self::WARNING ),
				sprintf( 'Personal data within subscription #%d has been retained.', $cancelled ),
			],
			$response['messages'],
			'Only the running subscription carries the saved payment method warning.'
		);
		$this->assertEquals( $before, [ $this->get_contract( $active ), $this->get_contract( $cancelled ) ] );
	}

	public function test_an_ended_subscription_is_anonymised_and_keeps_its_facts(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->set_payment_token( $contract_id );
		$before = $this->get_contract( $contract_id );
		$cycles = Contracts::get_cycles( $contract_id );

		$response = $this->erase();

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
		$this->assertSame( [ sprintf( 'Removed personal data from subscription #%d.', $contract_id ) ], $response['messages'] );

		$this->assert_erased( $contract_id );
		$after = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::CANCELLED, $after->get_status() );
		$this->assertSame( $before->get_items(), $after->get_items() );
		$this->assertSame( $before->get_billing_total(), $after->get_billing_total() );
		$this->assertSame( $before->get_origin_order_id(), $after->get_origin_order_id() );
		$this->assertSame( $before->get_payment_method(), $after->get_payment_method() );
		$this->assertSame( $before->get_selling_plan_id(), $after->get_selling_plan_id() );
		$this->assertEquals( $cycles, Contracts::get_cycles( $contract_id ) );
	}

	public function test_a_stored_shipping_email_is_anonymised(): void {
		$this->set_erasure( true );
		$contract_id = $this->seed_contract(
			[
				'customer_id' => $this->customer_id,
				'status'      => ContractStatus::CANCELLED,
				'addresses'   => [
					'billing'  => self::BILLING_ADDRESS,
					'shipping' => array_merge( self::SHIPPING_ADDRESS, [ 'email' => 'ada.shipping@example.com' ] ),
				],
			]
		);

		$this->erase();

		$addresses = (array) $this->get_contract( $contract_id )->get_addresses();
		$this->assertSame( 'deleted@site.invalid', $addresses['shipping']['email'] );
		$this->assertSame( 'deleted@site.invalid', $addresses['billing']['email'] );
	}

	public function test_an_expired_subscription_is_anonymised(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id );
		$this->force_contract_status( $contract_id, ContractStatus::EXPIRED );

		$response = $this->erase();

		$this->assertSame( [ sprintf( 'Removed personal data from subscription #%d.', $contract_id ) ], $response['messages'] );
		$this->assertSame( ContractStatus::EXPIRED, $this->get_contract( $contract_id )->get_status() );
		$this->assert_erased( $contract_id );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function running_statuses(): array {
		return [
			'active'               => [ 'active' ],
			'on hold'              => [ 'on-hold' ],
			'pending cancellation' => [ 'pending-cancellation' ],
		];
	}

	/**
	 * @dataProvider running_statuses
	 *
	 * @param string $status Running status.
	 */
	public function test_a_running_subscription_is_cancelled_then_anonymised( string $status ): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => $status ] );
		$cancelled   = [];
		add_action(
			'woocommerce_subscriptions_lite_contract_cancelled',
			static function ( ContractView $contract ) use ( &$cancelled ): void {
				$cancelled[] = $contract->get_id();
			}
		);

		$response = $this->erase();

		$this->assertSame( [ sprintf( 'Cancelled subscription #%d and removed its personal data.', $contract_id ) ], $response['messages'] );
		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['done'] );
		$this->assertSame( [ $contract_id ], $cancelled, 'The cancellation action fired once.' );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::CANCELLED, $contract->get_status() );
		$this->assertNull( $contract->get_next_payment_gmt() );
		$this->assertNull( HoldAnchor::read( $contract_id ) );
		$this->assert_erased( $contract_id );
		$entries = $this->get_history( $contract_id, History::TYPE_CANCELLED );
		$this->assertCount( 1, $entries, 'The cancellation is recorded as a merchant cancel.' );
		$this->assertSame(
			[
				'role'    => History::ROLE_MERCHANT,
				'user_id' => get_current_user_id(),
			],
			$entries[0]->get_payload()['actor']
		);
	}

	public function test_an_erasure_through_admin_ajax_records_the_cancel_by_the_merchant(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id );
		set_current_screen( 'tools_page_remove_personal_data' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->erase();

		$this->assertSame(
			[
				'role'    => History::ROLE_MERCHANT,
				'user_id' => get_current_user_id(),
			],
			$this->get_history( $contract_id, History::TYPE_CANCELLED )[0]->get_payload()['actor']
		);
	}

	public function test_a_draft_is_cancelled_then_anonymised(): void {
		$this->set_erasure( true );
		$contract_id = $this->seed_contract(
			[
				'customer_id' => $this->customer_id,
				'status'      => ContractStatus::DRAFT,
			]
		);

		$response = $this->erase();

		$this->assertSame( [ sprintf( 'Cancelled subscription #%d and removed its personal data.', $contract_id ) ], $response['messages'] );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( ContractStatus::CANCELLED, $contract->get_status() );
		$this->assertNull( $contract->get_customer_id() );
	}

	public function test_a_status_neither_running_nor_ended_is_retained_and_reported_on_page_one_only(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id );
		$this->store_raw_status( $contract_id, 'legacy-status' );

		$first  = $this->erase( 1 );
		$second = $this->erase( 2 );

		$this->assertSame( [ sprintf( 'Personal data within subscription #%d has been retained.', $contract_id ) ], $first['messages'] );
		$this->assertTrue( $first['items_retained'] );
		$this->assertFalse( $first['items_removed'] );
		$this->assertTrue( $first['done'] );
		$this->assertSame( [], $second['messages'] );
		$this->assert_intact( $contract_id );
	}

	public function test_other_customers_and_other_extensions_contracts_are_untouched(): void {
		$this->set_erasure( true );
		$foreign = $this->seed_foreign_contract( $this->customer_id, [ 'status' => ContractStatus::CANCELLED ] );
		$other   = $this->create_contract( $this->create_customer(), [ 'status' => 'cancelled' ] );

		$response = $this->erase();

		$this->assertSame( [], $response['messages'] );
		$this->assertTrue( $response['done'] );
		$this->assertSame( $this->customer_id, $this->get_contract( $foreign )->get_customer_id() );
		$this->assertNotNull( $this->get_contract( $other )->get_customer_id() );
		$this->assertSame( 'ada@example.com', ( (array) $this->get_contract( $other )->get_addresses() )['billing']['email'] );
	}

	public function test_twelve_subscriptions_are_erased_over_two_calls_with_retained_messages_on_page_one(): void {
		$this->set_erasure( true );
		$retained = $this->create_contract( $this->customer_id );
		$this->store_raw_status( $retained, 'legacy-status' );
		$ids = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->seed_contract(
				[
					'customer_id' => $this->customer_id,
					'status'      => ContractStatus::CANCELLED,
				]
			);
		}

		$first  = $this->erase( 1 );
		$second = $this->erase( 2 );

		$this->assertFalse( $first['done'] );
		$this->assertCount( 11, $first['messages'], 'One retained message and ten removals.' );
		$this->assertSame( sprintf( 'Personal data within subscription #%d has been retained.', $retained ), $first['messages'][0] );
		$this->assertTrue( $second['done'] );
		$this->assertSame(
			[
				sprintf( 'Removed personal data from subscription #%d.', $ids[10] ),
				sprintf( 'Removed personal data from subscription #%d.', $ids[11] ),
			],
			$second['messages'],
			'The second call erases the rest and repeats no retained message.'
		);
		foreach ( $ids as $id ) {
			$this->assertNull( $this->get_contract( $id )->get_customer_id() );
		}
		$this->assertSame(
			[ $retained ],
			array_map(
				static function ( ContractView $contract ): int {
					return $contract->get_id();
				},
				Contracts::list_for_customer( $this->customer_id, 50, 0, [ 'extension_slug' => Package::EXTENSION_SLUG ] )
			),
			'Erased contracts drop out of the account lookup.'
		);
	}

	public function test_a_second_full_run_finds_nothing_to_erase(): void {
		$this->set_erasure( true );
		$this->create_contract( $this->customer_id );
		$this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->erase_all();

		$responses = $this->erase_all();

		$this->assertCount( 1, $responses );
		$this->assertSame( [], $responses[0]['messages'] );
		$this->assertFalse( $responses[0]['items_removed'] );
		$this->assertFalse( $responses[0]['items_retained'] );
	}

	public function test_an_email_without_an_account_erases_nothing(): void {
		$this->set_erasure( true );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', [] );

		$response = call_user_func( $erasers[ Package::EXTENSION_SLUG ]['callback'], 'nobody@example.com', 1 );

		$this->assertSame(
			[
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			],
			$response
		);
	}

	/**
	 * Seed a Lite contract whose billing address email is the request's.
	 *
	 * @param int|null             $customer_id Customer id, or null for a guest contract.
	 * @param array<string, mixed> $args        Contract fields overriding the defaults.
	 * @return int Contract id.
	 */
	private function seed_contract_with_request_email( ?int $customer_id, array $args = [] ): int {
		return $this->seed_contract(
			array_merge(
				[
					'customer_id' => $customer_id,
					'addresses'   => [ 'billing' => array_merge( self::BILLING_ADDRESS, [ 'email' => self::EMAIL ] ) ],
				],
				$args
			)
		);
	}

	/**
	 * The ids of the Lite contracts with an address email equal to the request's.
	 *
	 * @return array<int, int>
	 */
	private function find_by_request_email(): array {
		return array_map(
			static function ( ContractView $contract ): int {
				return $contract->get_id();
			},
			Contracts::list_for_address_email( self::EMAIL, 50, 0, [ 'extension_slug' => Package::EXTENSION_SLUG ] )
		);
	}

	public function test_a_guest_subscription_is_found_by_its_address_email_and_erased(): void {
		$this->set_erasure( true );
		$guest = $this->seed_contract_with_request_email( null );

		$responses = $this->erase_all();

		$this->assertSame( [ sprintf( 'Cancelled subscription #%d and removed its personal data.', $guest ) ], $responses[0]['messages'] );
		$contract = $this->get_contract( $guest );
		$this->assertSame( ContractStatus::CANCELLED, $contract->get_status() );
		$this->assertSame( 'deleted@site.invalid', ( (array) $contract->get_addresses() )['billing']['email'] );
		$this->assertSame( [], $this->find_by_request_email(), 'The erased contract is not found again.' );
		$this->assertSame( [], $this->erase_all()[0]['messages'] );
	}

	public function test_a_subscription_whose_account_was_deleted_is_erased(): void {
		$this->set_erasure( true );
		$email       = 'orphan@example.com';
		$customer_id = $this->create_customer( [ 'user_email' => $email ] );
		$orphan      = $this->seed_contract(
			[
				'customer_id' => $customer_id,
				'status'      => ContractStatus::CANCELLED,
				'addresses'   => [ 'billing' => array_merge( self::BILLING_ADDRESS, [ 'email' => $email ] ) ],
			]
		);
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $customer_id );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', [] );

		$response = call_user_func( $erasers[ Package::EXTENSION_SLUG ]['callback'], $email, 1 );

		$this->assertSame( [ sprintf( 'Removed personal data from subscription #%d.', $orphan ) ], $response['messages'] );
		$contract = $this->get_contract( $orphan );
		$this->assertNull( $contract->get_customer_id() );
		$this->assertSame( 'deleted@site.invalid', ( (array) $contract->get_addresses() )['billing']['email'] );
	}

	public function test_a_subscription_found_by_both_reads_is_erased_once(): void {
		$this->set_erasure( true );
		$both  = $this->seed_contract_with_request_email( $this->customer_id, [ 'status' => ContractStatus::CANCELLED ] );
		$other = $this->seed_contract_with_request_email( $this->create_customer(), [ 'status' => ContractStatus::CANCELLED ] );

		$responses = $this->erase_all();

		$this->assertCount( 1, $responses );
		$this->assertSame(
			[
				sprintf( 'Removed personal data from subscription #%d.', $both ),
				sprintf( 'Removed personal data from subscription #%d.', $other ),
			],
			$responses[0]['messages'],
			'Each contract is erased once; another account\'s contract with the email is in scope.'
		);
		$this->assertNull( $this->get_contract( $both )->get_customer_id() );
		$this->assertNull( $this->get_contract( $other )->get_customer_id() );
		$this->assertSame( [], $this->find_by_request_email() );
		$this->assertSame( [], Contracts::list_for_customer( $this->customer_id, 50, 0, [ 'extension_slug' => Package::EXTENSION_SLUG ] ) );
	}

	public function test_twelve_guest_subscriptions_are_erased_over_two_calls(): void {
		$this->set_erasure( true );
		$ids = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->seed_contract_with_request_email( null, [ 'status' => ContractStatus::CANCELLED ] );
		}

		$first  = $this->erase( 1 );
		$second = $this->erase( 2 );

		$this->assertFalse( $first['done'] );
		$this->assertCount( 10, $first['messages'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame(
			[
				sprintf( 'Removed personal data from subscription #%d.', $ids[10] ),
				sprintf( 'Removed personal data from subscription #%d.', $ids[11] ),
			],
			$second['messages'],
			'Erased contracts leave the email read, so the second call erases the rest.'
		);
		$this->assertSame( [], $this->find_by_request_email() );
	}

	public function test_a_guest_subscription_is_retained_with_erasure_off(): void {
		$guest = $this->seed_contract_with_request_email( null );

		$response = $this->erase();

		$this->assertSame( [ sprintf( 'Personal data within subscription #%d has been retained. %s', $guest, self::WARNING ) ], $response['messages'] );
		$this->assertSame( self::EMAIL, ( (array) $this->get_contract( $guest )->get_addresses() )['billing']['email'] );
	}

	public function test_a_held_lock_retains_the_subscription_until_a_rerun(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->capture_log();
		$lock = $this->hold_lock( $contract_id, 'renewal' );

		$response = $this->erase();

		$this->assertSame(
			[ sprintf( self::LOCKED, $contract_id ) ],
			$response['messages']
		);
		$this->assertTrue( $response['items_retained'] );
		$this->assertTrue( $response['done'] );
		$this->assert_intact( $contract_id );
		$this->assertTrue( $this->has_logged( sprintf( 'Privacy erasure: personal data was not removed from contract %d at the lock step (held by renewal).', $contract_id ) ) );

		$lock->release();
		$this->erase_all();

		$this->assert_erased( $contract_id );
	}

	public function test_a_lock_lost_mid_erasure_is_reported_as_held(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id );
		$this->capture_log();
		add_action(
			'woocommerce_subscriptions_lite_contract_cancelled',
			function () use ( $contract_id ): void {
				$this->expire_held_locks();
				$this->hold_lock( $contract_id, 'renewal' );
			}
		);

		$response = $this->erase();

		$this->assertSame(
			[ sprintf( self::LOCKED, $contract_id ) ],
			$response['messages']
		);
		$this->assertTrue( $response['done'] );
		$this->assertSame( $this->customer_id, $this->get_contract( $contract_id )->get_customer_id() );
		$this->assertTrue( $this->has_logged( sprintf( 'Privacy erasure: personal data was not removed from contract %d at the anonymise step (held by renewal).', $contract_id ) ) );
		$this->assertTrue( $this->has_logged( sprintf( 'HeldLocks: a write to contract %d was refused mid-flow; another operation (renewal) holds its lock.', $contract_id ) ) );
	}

	public function test_a_failed_address_write_leaves_the_contract_linked_and_a_rerun_completes_it(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->capture_log();
		$levels = [];
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level ) use ( &$levels ) {
				if ( false !== strpos( (string) $message, 'Privacy erasure:' ) ) {
					$levels[] = $level;
				}
				return $message;
			},
			10,
			2
		);
		$this->fail_next_query( '/INSERT INTO \S*wc_subscription_contract_addresses/' );

		$response = $this->erase();

		$this->assertSame(
			[ sprintf( 'Personal data could not be removed from subscription #%d. Check the WooCommerce logs and run the erasure again.', $contract_id ) ],
			$response['messages']
		);
		$this->assertTrue( $response['done'] );
		$this->assert_intact( $contract_id );
		$this->assert_lock_released( $contract_id );
		$this->assertTrue( $this->has_logged( sprintf( 'Privacy erasure: personal data was not removed from contract %d at the anonymise step (RuntimeException, code 0).', $contract_id ) ) );
		$this->assertSame( [ 'error' ], array_unique( $levels ), 'A per-contract failure is logged as an error.' );

		$this->erase_all();

		$this->assert_erased( $contract_id );
	}

	public function test_a_failed_unlinking_write_leaves_the_contract_linked_and_a_rerun_completes_it(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->capture_log();
		$this->fail_next_query( '/UPDATE `\S*wc_subscription_contracts` SET .*`customer_id` = NULL/' );

		$response = $this->erase();

		$this->assertStringStartsWith( 'Personal data could not be removed from subscription #', $response['messages'][0] );
		$this->assertTrue( $this->has_logged( sprintf( 'Privacy erasure: personal data was not removed from contract %d at the unlink step', $contract_id ) ) );
		$this->assertTrue( $response['done'] );
		$contract = $this->get_contract( $contract_id );
		$this->assertSame( $this->customer_id, $contract->get_customer_id(), 'Still found by the account lookup.' );
		$this->assertSame( 'deleted@site.invalid', ( (array) $contract->get_addresses() )['billing']['email'], 'The anonymising write landed.' );

		$second = $this->erase_all();

		$this->assertSame( [ sprintf( 'Removed personal data from subscription #%d.', $contract_id ) ], $second[0]['messages'] );
		$this->assert_erased( $contract_id );
	}

	public function test_a_failure_after_the_cancel_leaves_it_cancelled_and_linked_and_a_rerun_completes_it(): void {
		$this->set_erasure( true );
		$contract_id = $this->create_contract( $this->customer_id );
		$this->fail_next_query( '/INSERT INTO \S*wc_subscription_contract_addresses/' );

		$response = $this->erase();

		$this->assertStringStartsWith( 'Personal data could not be removed from subscription #', $response['messages'][0] );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $contract_id )->get_status() );
		$this->assert_intact( $contract_id );

		$second = $this->erase_all();

		$this->assertSame( [ sprintf( 'Removed personal data from subscription #%d.', $contract_id ) ], $second[0]['messages'], 'The rerun finds it as ended.' );
		$this->assert_erased( $contract_id );
	}

	/**
	 * @return array<string, array{0: Throwable}>
	 */
	public function exceptions(): array {
		return [
			'runtime'          => [ new RuntimeException( 'Storage failed for ada@example.com.' ) ],
			'invalid argument' => [ new InvalidArgumentException( 'Bad argument for ada@example.com.' ) ],
			'validation'       => [ new ValidationException( new WP_Error( 'invalid', 'Invalid value for ada@example.com.' ) ) ],
		];
	}

	/**
	 * @dataProvider exceptions
	 *
	 * @param Throwable $exception The exception a step throws.
	 */
	public function test_an_exception_is_reported_logged_without_personal_data_and_ends_the_request( Throwable $exception ): void {
		$this->set_erasure( true );
		$failing = $this->create_contract( $this->customer_id );
		$later   = $this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->capture_log();
		add_action(
			'woocommerce_subscriptions_lite_contract_cancelled',
			static function () use ( $exception ): void {
				throw $exception;
			}
		);

		$response = $this->erase();

		$this->assertTrue( $response['done'], 'A failure ends the request.' );
		$this->assertTrue( $response['items_retained'] );
		$this->assertSame(
			[
				sprintf( 'Personal data could not be removed from subscription #%d. Check the WooCommerce logs and run the erasure again.', $failing ),
				sprintf( 'Removed personal data from subscription #%d.', $later ),
			],
			$response['messages'],
			'The rest of the batch is still erased.'
		);
		$this->assertTrue( $this->has_logged( sprintf( 'Privacy erasure: personal data was not removed from contract %d at the cancel step (%s, code %s).', $failing, get_class( $exception ), (string) $exception->getCode() ) ) );
		$this->assertSame( [ Package::EXTENSION_SLUG ], array_unique( $this->logged_sources_of( 'Privacy erasure' ) ) );
		$this->assertFalse( $this->has_logged( 'ada@example.com' ) );
		$this->assertFalse( $this->has_logged( self::EMAIL ) );
		$this->assert_lock_released( $failing );
	}

	public function test_a_failure_at_the_batch_boundary_with_more_eligible_left_ends_the_request_and_a_rerun_completes_it(): void {
		$this->set_erasure( true );
		$ids = [];
		for ( $i = 0; $i < 11; $i++ ) {
			$ids[] = $this->seed_contract(
				[
					'customer_id' => $this->customer_id,
					'status'      => ContractStatus::CANCELLED,
				]
			);
		}
		$lock = $this->hold_lock( $ids[9], 'renewal' );

		$response = $this->erase();
		$lock->release();

		$this->assertTrue( $response['done'], 'A failure ends the request although an eleventh contract is eligible.' );
		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertCount( 10, $response['messages'] );
		$this->assertSame( sprintf( self::LOCKED, $ids[9] ), $response['messages'][9], 'The tenth contract, the last of the batch, failed.' );
		foreach ( array_slice( $ids, 0, 9 ) as $id ) {
			$this->assertNull( $this->get_contract( $id )->get_customer_id(), 'The contracts before the failure are erased.' );
		}
		$this->assertSame( $this->customer_id, $this->get_contract( $ids[9] )->get_customer_id(), 'The locked contract stays linked.' );
		$this->assertSame( $this->customer_id, $this->get_contract( $ids[10] )->get_customer_id(), 'The contract past the batch stays linked.' );

		$rerun = $this->erase_all();

		$this->assertCount( 1, $rerun );
		$this->assertSame(
			[
				sprintf( 'Removed personal data from subscription #%d.', $ids[9] ),
				sprintf( 'Removed personal data from subscription #%d.', $ids[10] ),
			],
			$rerun[0]['messages']
		);
		$this->assertNull( $this->get_contract( $ids[9] )->get_customer_id() );
		$this->assertNull( $this->get_contract( $ids[10] )->get_customer_id() );
	}

	public function test_a_failed_read_returns_an_error_and_logs_no_personal_data(): void {
		$this->set_erasure( true );
		$this->create_contract( $this->customer_id, [ 'status' => 'cancelled' ] );
		$this->capture_log();
		$this->fail_next_query( '/FROM \S*wc_subscription_contracts\b.*customer_id/s' );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', [] );

		$response = call_user_func( $erasers[ Package::EXTENSION_SLUG ]['callback'], self::EMAIL, 1 );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'woocommerce_subscriptions_lite_privacy_read_failed', $response->get_error_code() );
		$this->assertTrue( $this->has_logged( 'Privacy erasure: subscriptions could not be read' ) );
		$this->assertFalse( $this->has_logged( self::EMAIL ) );
	}
}
