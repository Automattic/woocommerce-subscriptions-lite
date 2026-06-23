<?php
/**
 * Unit tests for the authenticated portal cancel handler.
 *
 * The handler is the My Account cancel form target: it verifies the request is
 * authenticated (logged-in session + nonce), resolves the contract, enforces
 * ownership (a foreign or missing contract collapses to "not found"), checks the
 * status is cancelable, and then asks the engine to cancel. These tests inject
 * fake finder / canceller / current-user / nonce seams so every guard branch is
 * exercised without a booted WordPress.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\Portal;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsLite\Portal\CancelHandler;
use Automattic\WooCommerce\SubscriptionsLite\Portal\CancelResult;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Portal\CancelHandler
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Portal\CancelResult
 */
final class CancelHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['woocommerce_subscriptions_lite_test_logs'] = [];
	}

	/**
	 * Build a contract double owned by `$customer_id` in `$status`.
	 *
	 * @param int    $customer_id Owning customer id.
	 * @param string $status      Contract status.
	 * @param int    $id          Contract id.
	 */
	private function make_contract( int $customer_id = 1, string $status = ContractStatus::ACTIVE, int $id = 100 ): Contract {
		$contract = Contract::create(
			[
				'customer_id'     => $customer_id,
				'currency'        => 'USD',
				'selling_plan_id' => 7,
				'origin_order_id' => 55,
				'billing_total'   => '19.99',
				'start_gmt'       => '2026-01-01 00:00:00',
				'status'          => $status,
				'schedule_source' => Contract::SCHEDULE_SOURCE_PRIMITIVE,
				'items'           => [],
				'addresses'       => [],
				'meta'            => [],
			]
		);
		$contract->set_id( $id );
		return $contract;
	}

	/**
	 * Build a handler with sensible passing defaults, overridable per test.
	 *
	 * @param array<string, mixed> $overrides Seam overrides: contract, current_user_id, nonce_ok, canceller.
	 */
	private function make_handler( array $overrides = [] ): CancelHandler {
		$contract        = $overrides['contract'] ?? $this->make_contract();
		$current_user_id = $overrides['current_user_id'] ?? 1;
		$nonce_ok        = $overrides['nonce_ok'] ?? true;
		$cancel_calls    = $overrides['cancel_calls'] ?? null;

		return new CancelHandler(
			static fn ( int $id ): ?Contract => $contract instanceof Contract && $id === $contract->get_id() ? $contract : null,
			static function ( Contract $c ) use ( &$cancel_calls ): bool {
				if ( null !== $cancel_calls ) {
					$cancel_calls[] = $c->get_id();
				}
				return true;
			},
			static fn (): int => $current_user_id,
			static fn ( string $action ): bool => (bool) $nonce_ok
		);
	}

	public function test_cancels_a_contract_the_logged_in_owner_requests(): void {
		$cancel_calls = [];
		$handler      = new CancelHandler(
			fn ( int $id ): ?Contract => $this->make_contract( 1, ContractStatus::ACTIVE, 100 ),
			static function ( Contract $c ) use ( &$cancel_calls ): bool {
				$cancel_calls[] = $c->get_id();
				return true;
			},
			static fn (): int => 1,
			static fn ( string $action ): bool => true
		);

		$result = $handler->handle( [ 'contract_id' => '100' ] );

		$this->assertSame( CancelResult::CANCELLED, $result->code() );
		$this->assertSame( [ 100 ], $cancel_calls, 'The engine cancel runs for the owned, cancelable contract.' );
	}

	public function test_rejects_an_unauthenticated_request(): void {
		$cancel_calls = [];
		$handler      = new CancelHandler(
			fn ( int $id ): ?Contract => $this->make_contract(),
			static function ( Contract $c ) use ( &$cancel_calls ): bool {
				$cancel_calls[] = $c->get_id();
				return true;
			},
			static fn (): int => 0, // Not logged in.
			static fn ( string $action ): bool => true
		);

		$result = $handler->handle( [ 'contract_id' => '100' ] );

		$this->assertSame( CancelResult::NOT_AUTHENTICATED, $result->code() );
		$this->assertSame( [], $cancel_calls, 'An anonymous request never reaches the engine cancel.' );
	}

	public function test_rejects_a_bad_nonce(): void {
		$cancel_calls = [];
		$handler      = new CancelHandler(
			fn ( int $id ): ?Contract => $this->make_contract(),
			static function ( Contract $c ) use ( &$cancel_calls ): bool {
				$cancel_calls[] = $c->get_id();
				return true;
			},
			static fn (): int => 1,
			static fn ( string $action ): bool => false // Nonce check fails.
		);

		$result = $handler->handle( [ 'contract_id' => '100' ] );

		$this->assertSame( CancelResult::BAD_NONCE, $result->code() );
		$this->assertSame( [], $cancel_calls, 'A bad nonce never reaches the engine cancel.' );
	}

	public function test_foreign_contract_collapses_to_not_found(): void {
		$cancel_calls = [];
		// Contract is owned by customer 2; the requester is customer 1.
		$handler = new CancelHandler(
			fn ( int $id ): ?Contract => $this->make_contract( 2, ContractStatus::ACTIVE, 100 ),
			static function ( Contract $c ) use ( &$cancel_calls ): bool {
				$cancel_calls[] = $c->get_id();
				return true;
			},
			static fn (): int => 1,
			static fn ( string $action ): bool => true
		);

		$result = $handler->handle( [ 'contract_id' => '100' ] );

		$this->assertSame( CancelResult::NOT_FOUND, $result->code(), 'A foreign contract is reported as not-found, not forbidden.' );
		$this->assertSame( [], $cancel_calls, 'A foreign contract is never cancelled.' );
	}

	public function test_missing_contract_is_not_found(): void {
		$handler = new CancelHandler(
			static fn ( int $id ): ?Contract => null, // No such contract.
			static fn ( Contract $c ): bool => true,
			static fn (): int => 1,
			static fn ( string $action ): bool => true
		);

		$result = $handler->handle( [ 'contract_id' => '999' ] );

		$this->assertSame( CancelResult::NOT_FOUND, $result->code() );
	}

	public function test_non_cancelable_status_is_rejected(): void {
		$cancel_calls = [];
		$handler      = new CancelHandler(
			fn ( int $id ): ?Contract => $this->make_contract( 1, ContractStatus::CANCELLED, 100 ),
			static function ( Contract $c ) use ( &$cancel_calls ): bool {
				$cancel_calls[] = $c->get_id();
				return true;
			},
			static fn (): int => 1,
			static fn ( string $action ): bool => true
		);

		$result = $handler->handle( [ 'contract_id' => '100' ] );

		$this->assertSame( CancelResult::NOT_CANCELABLE, $result->code() );
		$this->assertSame( [], $cancel_calls, 'An already-cancelled contract is not cancelled again.' );
	}

	public function test_missing_contract_id_param_is_not_found(): void {
		$handler = $this->make_handler();

		$result = $handler->handle( [] );

		$this->assertSame( CancelResult::NOT_FOUND, $result->code(), 'No contract id resolves to nothing - not found.' );
	}

	public function test_register_binds_the_front_end_handler(): void {
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'] = [];

		CancelHandler::register();

		$hooks = array_filter(
			$GLOBALS['woocommerce_subscriptions_lite_test_hooks'],
			static fn ( array $h ): bool => 'template_redirect' === $h['hook']
		);
		$this->assertNotEmpty( $hooks, 'register() binds the front-end cancel handler on template_redirect.' );
	}
}
