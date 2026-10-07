<?php
/**
 * Integration tests for the customer contract action routes, dispatched through the
 * Bootstrap-wired REST server: the auth and ownership matrix (401, asymmetric 404,
 * drafts hidden), the action round-trips with their `{ id, status }` summary, and the
 * 409 / 500 error mapping.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Rest;

use WP_REST_Request;
use WP_REST_Response;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\SchemaInstaller;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Rest\ContractActionsController
 */
final class ContractActionsControllerTest extends LiteIntegrationTestCase {

	private const BASE = '/wc-subscriptions-lite/v1/contracts';

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

		// A fresh server per test fires `rest_api_init` again, so the Bootstrap-wired routes
		// and core's REST filters (the OPTIONS handler among them) are all present.
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
	 * @param string $status Contract status.
	 */
	private function seed_owned_contract( string $status = ContractStatus::ACTIVE ): int {
		return $this->seed_contract(
			[
				'customer_id'      => $this->owner_id,
				'status'           => $status,
				'next_payment_gmt' => '2099-02-01 00:00:00',
			]
		);
	}

	/**
	 * Dispatch a POST action.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param string               $action      Action segment.
	 * @param array<string, mixed> $body        Body params.
	 */
	private function post_action( int $contract_id, string $action, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::BASE . '/' . $contract_id . '/' . $action );
		if ( [] !== $body ) {
			$request->set_body_params( $body );
		}

		return rest_get_server()->dispatch( $request );
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

	public function test_an_anonymous_request_is_unauthorized(): void {
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'hold' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_lite_not_authenticated', $this->get_response_data( $response )['code'] );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_an_unknown_contract_is_not_found(): void {
		wp_set_current_user( $this->other_id );

		$response = $this->post_action( 4242424, 'hold' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_lite_contract_not_found', $this->get_response_data( $response )['code'] );
	}

	public function test_a_foreign_contract_is_not_found_like_an_unknown_one(): void {
		wp_set_current_user( $this->other_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'hold' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_lite_contract_not_found', $this->get_response_data( $response )['code'] );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_a_draft_is_not_found_for_its_owner(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( ContractStatus::DRAFT );

		$response = $this->post_action( $contract_id, 'cancel', [ 'at_period_end' => false ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( ContractStatus::DRAFT, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_options_exposes_the_action_schema(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', self::BASE . '/' . $contract_id . '/hold' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $this->get_response_data( $response );
		$this->assertIsArray( $data['schema'] );
		$this->assertSame( 'woocommerce_subscriptions_lite_contract_action', $data['schema']['title'] );
	}

	public function test_the_owner_holds_and_gets_the_summary(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'hold' );

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
		$contract_id = $this->seed_owned_contract( ContractStatus::ON_HOLD );

		$response = $this->post_action( $contract_id, 'reactivate' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_response_data( $response )['status'] );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}

	public function test_reactivating_an_active_contract_is_a_conflict(): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract();

		$response = $this->post_action( $contract_id, 'reactivate' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_lite_action_not_allowed', $this->get_response_data( $response )['code'] );
		$this->assertSame( '2099-02-01 00:00:00', $this->get_contract( $contract_id )->get_next_payment_gmt(), 'The schedule is untouched.' );
	}

	public function test_cancel_defaults_to_the_period_end(): void {
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
		$contract_id = $this->seed_owned_contract( ContractStatus::ON_HOLD );

		$response = $this->post_action( $contract_id, 'cancel', [ 'at_period_end' => false ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_response_data( $response )['status'] );
		$this->assertSame( ContractStatus::CANCELLED, $this->get_contract( $contract_id )->get_status() );
	}

	/**
	 * @dataProvider provide_disallowed_actions
	 *
	 * @param string               $status Stored status.
	 * @param string               $action Action segment.
	 * @param array<string, mixed> $body   Body params.
	 */
	public function test_an_action_the_status_does_not_allow_is_a_conflict( string $status, string $action, array $body ): void {
		wp_set_current_user( $this->owner_id );
		$contract_id = $this->seed_owned_contract( $status );

		$response = $this->post_action( $contract_id, $action, $body );

		$this->assertSame( 409, $response->get_status() );
		$stored = $this->get_contract( $contract_id );
		$this->assertSame( $status, $stored->get_status() );
		$this->assertSame( '2099-02-01 00:00:00', $stored->get_next_payment_gmt(), 'The rejected action writes nothing.' );
	}

	/**
	 * Status and action pairs the flows reject.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public function provide_disallowed_actions(): array {
		return [
			'hold a cancelled contract' => [ ContractStatus::CANCELLED, 'hold', [] ],
			'hold an expired contract'  => [ ContractStatus::EXPIRED, 'hold', [] ],
			'cancel an expired one now' => [ ContractStatus::EXPIRED, 'cancel', [ 'at_period_end' => false ] ],
		];
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
			$response = $this->post_action( $contract_id, 'hold' );
		} finally {
			remove_filter( 'query', $break );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_subscriptions_lite_action_failed', $this->get_response_data( $response )['code'] );
		$this->assertSame( ContractStatus::ACTIVE, $this->get_contract( $contract_id )->get_status() );
	}
}
