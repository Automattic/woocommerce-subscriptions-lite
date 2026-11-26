<?php
/**
 * Integration tests for History: the actor on each entry, the held lock passed to the
 * engine, and a failed write that is logged instead of thrown.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\HeldLocks;
use Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\History
 */
final class HistoryTest extends LiteIntegrationTestCase {

	public function test_an_entry_by_the_contracts_customer_names_the_customer(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->seed_contract( [ 'customer_id' => $customer_id ] );
		wp_set_current_user( $customer_id );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$entries = $this->get_history( $contract_id );
		$this->assertCount( 1, $entries );
		$this->assertSame( History::TYPE_HELD, $entries[0]->get_type() );
		$this->assertSame(
			[
				'actor' => [
					'role'    => History::ROLE_CUSTOMER,
					'user_id' => $customer_id,
				],
			],
			$entries[0]->get_payload()
		);
	}

	public function test_an_entry_by_a_store_manager_names_a_merchant(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->create_customer() ] );
		$manager_id  = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $manager_id );

		History::add( $this->get_contract( $contract_id ), History::TYPE_CANCELLED );

		$this->assertSame(
			[
				'role'    => History::ROLE_MERCHANT,
				'user_id' => $manager_id,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_a_store_manager_acting_on_their_own_subscription_outside_wp_admin_is_its_customer(): void {
		$manager_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$contract_id = $this->seed_contract( [ 'customer_id' => $manager_id ] );
		wp_set_current_user( $manager_id );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame( History::ROLE_CUSTOMER, $this->get_history( $contract_id )[0]->get_payload()['actor']['role'] );
	}

	public function test_a_store_manager_acting_on_their_own_subscription_in_wp_admin_is_the_merchant(): void {
		$manager_id  = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		$contract_id = $this->seed_contract( [ 'customer_id' => $manager_id ] );
		wp_set_current_user( $manager_id );
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame(
			[
				'role'    => History::ROLE_MERCHANT,
				'user_id' => $manager_id,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_a_store_manager_acting_on_their_own_subscription_through_admin_ajax_is_its_customer(): void {
		$manager_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$contract_id = $this->seed_contract( [ 'customer_id' => $manager_id ] );
		wp_set_current_user( $manager_id );
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame( History::ROLE_CUSTOMER, $this->get_history( $contract_id )[0]->get_payload()['actor']['role'] );
	}

	public function test_a_customer_who_cannot_manage_the_store_stays_the_customer_in_wp_admin(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->seed_contract( [ 'customer_id' => $customer_id ] );
		wp_set_current_user( $customer_id );
		set_current_screen( 'profile' );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame( History::ROLE_CUSTOMER, $this->get_history( $contract_id )[0]->get_payload()['actor']['role'] );
	}

	public function test_a_background_run_in_wp_admin_stays_the_systems(): void {
		$manager_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$contract_id = $this->seed_contract( [ 'customer_id' => $manager_id ] );
		wp_set_current_user( $manager_id );
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );
		add_filter( 'wp_doing_cron', '__return_true' );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame(
			[
				'role'    => History::ROLE_SYSTEM,
				'user_id' => null,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_a_system_entry_in_wp_admin_by_a_store_manager_who_is_the_customer_stays_the_systems(): void {
		$manager_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$contract_id = $this->seed_contract( [ 'customer_id' => $manager_id ] );
		wp_set_current_user( $manager_id );
		set_current_screen( 'subscriptions_page_wc-subscriptions-lite' );

		History::add_system( $contract_id, History::TYPE_RENEWAL_FAILED );

		$this->assertSame(
			[
				'role'    => History::ROLE_SYSTEM,
				'user_id' => null,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_an_entry_with_no_user_is_the_systems(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->create_customer() ] );
		wp_set_current_user( 0 );

		History::add( $this->get_contract( $contract_id ), History::TYPE_CREATED );

		$this->assertSame(
			[
				'role'    => History::ROLE_SYSTEM,
				'user_id' => null,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_an_entry_by_another_user_who_cannot_manage_the_store_is_the_systems_with_the_user(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->create_customer() ] );
		$other_id    = $this->create_customer();
		wp_set_current_user( $other_id );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame(
			[
				'role'    => History::ROLE_SYSTEM,
				'user_id' => $other_id,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_an_entry_inside_wp_cron_is_the_systems_whatever_user_the_run_carries(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->create_customer() ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'wp_doing_cron', '__return_true' );

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame(
			[
				'role'    => History::ROLE_SYSTEM,
				'user_id' => null,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_an_entry_inside_the_action_scheduler_queue_is_the_systems_whatever_user_the_run_carries(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->seed_contract( [ 'customer_id' => $customer_id ] );
		wp_set_current_user( $customer_id );
		remove_all_actions( 'action_scheduler_run_queue' );
		add_action(
			'action_scheduler_run_queue',
			function () use ( $contract_id ): void {
				History::add( $this->get_contract( $contract_id ), History::TYPE_CANCELLED );
			}
		);

		do_action( 'action_scheduler_run_queue', 'Async Request' );

		$this->assertSame(
			[
				'role'    => History::ROLE_SYSTEM,
				'user_id' => null,
			],
			$this->get_history( $contract_id )[0]->get_payload()['actor']
		);
	}

	public function test_an_actor_lookup_that_throws_is_logged_and_not_thrown(): void {
		$contract_id = $this->seed_contract( [ 'customer_id' => $this->create_customer() ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		add_filter(
			'user_has_cap',
			static function (): array {
				throw new \RuntimeException( 'A capability filter failed.' );
			}
		);
		$this->capture_log();

		History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );

		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertTrue( $this->has_logged( 'A capability filter failed.' ) );
	}

	public function test_a_system_entry_ignores_the_current_user_and_stores_its_fields(): void {
		$customer_id = $this->create_customer();
		$contract_id = $this->create_contract( $customer_id );
		$cycle_id    = Contracts::get_cycles( $contract_id, 1 )[0]->get_id();
		wp_set_current_user( $customer_id );

		History::add_system(
			$contract_id,
			History::TYPE_RENEWAL_FAILED,
			[
				'cycle_id'     => $cycle_id,
				'payload'      => [ 'order_id' => 42 ],
				'creation_key' => 'test-key',
			]
		);

		$entry = $this->get_history( $contract_id, History::TYPE_RENEWAL_FAILED )[0];
		$this->assertSame( $cycle_id, $entry->get_cycle_id() );
		$this->assertSame(
			[
				'actor'    => [
					'role'    => History::ROLE_SYSTEM,
					'user_id' => null,
				],
				'order_id' => 42,
			],
			$entry->get_payload()
		);
	}

	public function test_a_repeated_creation_key_records_one_entry(): void {
		$contract_id = $this->seed_contract();

		History::add_system( $contract_id, History::TYPE_RENEWAL_PAID, [ 'creation_key' => 'once' ] );
		History::add_system( $contract_id, History::TYPE_RENEWAL_PAID, [ 'creation_key' => 'once' ] );

		$this->assertCount( 1, $this->get_history( $contract_id ) );
	}

	public function test_the_entry_is_written_with_the_lock_held_in_this_request(): void {
		$contract_id = $this->seed_contract();
		$lock        = HeldLocks::acquire( $contract_id, 'test' );
		$this->assertNotNull( $lock );

		try {
			History::add_system( $contract_id, History::TYPE_HELD );
		} finally {
			HeldLocks::release( $lock );
		}

		$this->assertSame( [ History::TYPE_HELD ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_lost_held_lock_records_nothing_and_logs_an_error(): void {
		$contract_id = $this->seed_contract();
		$stale       = $this->hold_lock( $contract_id, 'first' );
		$stale->release();
		$holder = $this->hold_lock( $contract_id, 'second' );
		HeldLocks::register( $stale );
		$this->capture_log();

		try {
			History::add_system( $contract_id, History::TYPE_HELD );
		} finally {
			HeldLocks::unregister( $stale );
			$holder->release();
		}

		$this->assertSame( [], $this->get_history( $contract_id ), 'A lost lock writes nothing.' );
		$this->assertSame( [ Package::EXTENSION_SLUG ], $this->logged_sources_of( sprintf( 'History: the held entry of contract %d could not be recorded', $contract_id ) ) );
	}

	public function test_an_unlocked_entry_is_written_while_another_request_holds_the_lock(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id, 'renewal' );

		try {
			History::add( $this->get_contract( $contract_id ), History::TYPE_HELD );
		} finally {
			$lock->release();
		}

		$this->assertSame( [ History::TYPE_HELD ], $this->get_history_types( $contract_id ) );
	}

	public function test_a_storage_failure_is_logged_and_not_thrown(): void {
		$contract_id = $this->seed_contract();
		$this->capture_log();
		$this->fail_next_query( self::history_insert() );

		History::add_system( $contract_id, History::TYPE_HELD );

		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertSame( [ Package::EXTENSION_SLUG ], $this->logged_sources_of( sprintf( 'History: the held entry of contract %d could not be recorded', $contract_id ) ) );
	}

	public function test_an_entry_on_another_extensions_contract_is_logged_and_not_thrown(): void {
		$contract_id = $this->seed_foreign_contract( $this->create_customer() );
		$this->capture_log();

		History::add_system( $contract_id, History::TYPE_HELD );

		$this->assertSame( [], $this->get_history( $contract_id ) );
		$this->assertTrue( $this->has_logged( sprintf( 'History: the held entry of contract %d could not be recorded', $contract_id ) ) );
	}
}
