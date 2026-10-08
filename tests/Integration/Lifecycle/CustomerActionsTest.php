<?php
/**
 * Integration tests for the customer actions, dispatched through the engine's contract action
 * endpoint: the round-trips with their `{ id, status }` summary, the auth and ownership matrix
 * (401, asymmetric 404, drafts hidden), availability by status (409) and discovery.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Lifecycle;

use WP_REST_Request;
use WP_REST_Response;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\CustomerActions
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Lifecycle\CustomerActionRules
 */
final class CustomerActionsTest extends LiteIntegrationTestCase {

	private const BASE = '/wc/v3/subscriptions-engine/contracts';

	/**
	 * The owning customer.
	 *
	 * @var int
	 */
	private $owner_id;

	/**
	 * Another customer.
	 *
	 * @var int
	 */
	private $other_id;

	public function set_up(): void {
		global $wp_rest_server;

		parent::set_up();

		// A fresh server per test fires `rest_api_init` again, so the engine's routes are present.
		$wp_rest_server = null;
		rest_get_server();

		$this->owner_id = $this->create_customer();
		$this->other_id = $this->create_customer();
	}

	public function tear_down(): void {
		global $wp_rest_server;

		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Seed a contract for the owner.
	 *
	 * @param string      $status           Contract status.
	 * @param string|null $next_payment_gmt Next payment; a customer or merchant hold clears it.
	 */
	private function seed_owned_contract( string $status = ContractStatus::ACTIVE, ?string $next_payment_gmt = '2099-02-01 00:00:00' ): int {
		return $this->seed_contract(
			[
				'customer_id'      => $this->owner_id,
				'status'           => $status,
				'next_payment_gmt' => $next_payment_gmt,
			]
		);
	}

	/**
	 * POST an action as the portal store does.
	 *
	 * @param int                       $contract_id Contract id.
	 * @param string                    $action      Action slug.
	 * @param array<string, mixed>|null $action_args Action args, omitted from the body when null.
	 */
	private function post_action( int $contract_id, string $action, ?array $action_args = null ): WP_REST_Response {
		$body = [
			'action'         => $action,
			'extension_slug' => Package::EXTENSION_SLUG,
		];
		if ( null !== $action_args ) {
			$body['action_args'] = $action_args;
		}

		$request = new WP_REST_Request( 'POST', self::BASE . '/' . $contract_id . '/action' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The action slugs discovery lists for the contract.
	 *
	 * @param int $contract_id Contract id.
	 * @return array<int, string>
	 */
	private function discover_actions( int $contract_id ): array {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::BASE . '/' . $contract_id . '/action' ) );
		$this->assertSame( 200, $response->get_status() );

		return array_column( $this->get_response_data( $response )['actions'], 'action' );
	}

	/**
	 * The response body as an array.
	 *
	 * @param WP_REST_Response $response The dispatched response.
	 * @return array<int|string, mixed>
	 */
	private function get_response_data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	public function test_the_owner_holds_and_gets_the_summary(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'hold', [] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'id'     => $contract_id,
				'status' => ContractStatus::ON_HOLD,
			],
			$this->get_response_data( $response )
		);
		$this->assertSame( ContractStatus::ON_HOLD, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_the_owner_reactivates(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( ContractStatus::ON_HOLD, null );

		$response = $this->post_action( $contract_id, 'reactivate', [] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_response_data( $response )['status'] );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_cancel_without_action_args_cancels_at_the_period_end(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'cancel' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $this->get_response_data( $response )['status'] );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_cancel_at_period_end_winds_the_contract_down(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'cancel', [ 'at_period_end' => true ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ContractStatus::PENDING_CANCELLATION, $this->get_response_data( $response )['status'] );
	}

	public function test_cancel_now_cancels_the_contract(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( ContractStatus::ON_HOLD, null );

		$response = $this->post_action( $contract_id, 'cancel', [ 'at_period_end' => false ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_response_data( $response )['status'] );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_an_anonymous_request_is_unauthorized(): void {
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'hold', [] );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_foreign_contract_is_not_found(): void {
		wp_set_current_user( $this->other_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'hold', [] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
		$discovery = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::BASE . '/' . $contract_id . '/action' ) );
		$this->assertSame( 404, $discovery->get_status(), 'Discovery does not reveal a foreign contract.' );
	}

	public function test_a_draft_is_not_found_for_its_owner(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( ContractStatus::DRAFT, null );

		$response = $this->post_action( $contract_id, 'cancel', [ 'at_period_end' => false ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( ContractStatus::DRAFT, $this->get_contract( $contract_id )->get_status() );
	}

	/**
	 * @dataProvider provide_unavailable_actions
	 *
	 * @param string      $status           Stored status.
	 * @param string|null $next_payment_gmt Stored next payment.
	 * @param string      $action           Action slug.
	 */
	public function test_an_action_the_status_does_not_allow_is_a_conflict( string $status, ?string $next_payment_gmt, string $action ): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( $status, $next_payment_gmt );

		$response = $this->post_action( $contract_id, $action, [] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_engine_action_not_available', $this->get_response_data( $response )['code'] );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( $status, $stored->get_status() );
		$this->assertSame( $next_payment_gmt, $stored->get_next_payment_gmt(), 'The rejected action writes nothing.' );
	}

	/**
	 * Status, schedule and action combinations the customer may not run.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string}>
	 */
	public function provide_unavailable_actions(): array {
		return [
			'reactivate an active contract'   => [ ContractStatus::ACTIVE, '2099-02-01 00:00:00', 'reactivate' ],
			'reactivate a needs-payment hold' => [ ContractStatus::ON_HOLD, '2099-02-01 00:00:00', 'reactivate' ],
			'hold a cancelled contract'       => [ ContractStatus::CANCELLED, null, 'hold' ],
			'cancel an expired contract'      => [ ContractStatus::EXPIRED, null, 'cancel' ],
			'cancel a winding-down contract'  => [ ContractStatus::PENDING_CANCELLATION, null, 'cancel' ],
		];
	}

	/**
	 * @dataProvider provide_discovery_by_status
	 *
	 * @param string             $status           Stored status.
	 * @param string|null        $next_payment_gmt Stored next payment.
	 * @param array<int, string> $expected         Listed action slugs.
	 */
	public function test_discovery_lists_the_actions_the_status_allows( string $status, ?string $next_payment_gmt, array $expected ): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( $status, $next_payment_gmt );

		$this->assertSame( $expected, $this->discover_actions( $contract_id ) );
	}

	/**
	 * Expected discovery per status and schedule.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: array<int, string>}>
	 */
	public function provide_discovery_by_status(): array {
		return [
			'active'             => [ ContractStatus::ACTIVE, '2099-02-01 00:00:00', [ 'hold', 'cancel' ] ],
			'on hold'            => [ ContractStatus::ON_HOLD, null, [ 'reactivate', 'cancel' ] ],
			'needs-payment hold' => [ ContractStatus::ON_HOLD, '2099-02-01 00:00:00', [ 'cancel' ] ],
			'cancelled'          => [ ContractStatus::CANCELLED, null, [] ],
		];
	}

	public function test_discovery_describes_the_cancel_args(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$request = new WP_REST_Request( 'GET', self::BASE . '/' . $contract_id . '/action' );
		$request->set_query_params( [ 'action' => 'cancel' ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$actions = $this->get_response_data( $response )['actions'];
		$this->assertCount( 1, $actions );
		$this->assertSame( Package::EXTENSION_SLUG, $actions[0]['extension_slug'] );
		$args = (array) $actions[0]['args'];
		$this->assertSame( 'boolean', $args['at_period_end']['type'] );
		$this->assertTrue( $args['at_period_end']['default'] );
	}

	public function test_a_storage_failure_is_a_server_error(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();
		$meta_table  = SchemaInstaller::get_table_name( SchemaInstaller::TABLE_CONTRACT_META );
		$break       = static function ( string $query ) use ( $meta_table ): string {
			return 0 === strpos( $query, "INSERT INTO `{$meta_table}`" ) ? 'SELECT broken syntax (' : $query;
		};
		add_filter( 'query', $break );

		try {
			$response = $this->post_action( $contract_id, 'hold', [] );
		} finally {
			remove_filter( 'query', $break );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}
}
