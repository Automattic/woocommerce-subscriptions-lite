<?php
/**
 * Integration tests for the Lite plan order REST route, dispatched through the
 * real REST server.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\PlanStatus;
use Automattic\WooCommerce\SubscriptionsLite\Plans\PlanOrder;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Plans\PlanOrderController
 * @group plans-rest
 */
final class PlanOrderControllerTest extends LiteIntegrationTestCase {

	private const ROUTE = '/wc/v3/subscriptions-lite/plans/reorder';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		rest_get_server();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_the_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	public function test_an_anonymous_request_is_refused(): void {
		$id = $this->make_plan()->get_id();
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->reorder( [ $id ] )->get_status() );
		$this->assertSame( [], ( new PlanOrder() )->get() );
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		$id = $this->make_plan()->get_id();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );

		$this->assertSame( 403, $this->reorder( [ $id ] )->get_status() );
		$this->assertSame( [], ( new PlanOrder() )->get() );
	}

	public function test_saves_and_returns_the_order(): void {
		$first  = $this->make_plan( 'week' )->get_id();
		$second = $this->make_plan( 'month' )->get_id();

		$response = $this->reorder( [ $second, $first ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'ids' => [ $second, $first ] ], $response->get_data() );
		$this->assertSame( [ $second, $first ], ( new PlanOrder() )->get() );
	}

	public function test_archived_lite_plans_are_accepted(): void {
		$active   = $this->make_plan()->get_id();
		$archived = $this->make_plan( 'year', 1, null, [ 'status' => PlanStatus::ARCHIVED ] )->get_id();

		$this->assertSame( 200, $this->reorder( [ $archived, $active ] )->get_status() );
		$this->assertSame( [ $archived, $active ], ( new PlanOrder() )->get() );
	}

	/**
	 * @dataProvider provide_invalid_orders
	 *
	 * @param callable(int, int): array<int, int|string> $build_ids Builds the ids from a Lite and a foreign plan id.
	 */
	public function test_an_invalid_order_is_rejected_without_writing( callable $build_ids ): void {
		$lite    = $this->make_plan()->get_id();
		$foreign = $this->make_plan( 'month', 1, null, [ 'extension_slug' => 'another-extension' ] )->get_id();
		( new PlanOrder() )->set( [ $lite ] );

		$response = $this->reorder( $build_ids( $lite, $foreign ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertSame( [ $lite ], ( new PlanOrder() )->get(), 'The saved order is unchanged.' );
	}

	/**
	 * @return array<string, array{0: callable(int, int): array<int, int|string>}>
	 */
	public function provide_invalid_orders(): array {
		return [
			'duplicate'     => [
				static function ( int $lite ): array {
					return [ $lite, $lite ];
				},
			],
			'int and digit' => [
				static function ( int $lite ): array {
					return [ $lite, (string) $lite ];
				},
			],
			'zero'          => [
				static function ( int $lite ): array {
					return [ $lite, 0 ];
				},
			],
			'negative'      => [
				static function ( int $lite ): array {
					return [ -1, $lite ];
				},
			],
			'foreign owner' => [
				static function ( int $lite, int $foreign ): array {
					return [ $lite, $foreign ];
				},
			],
			'unknown id'    => [
				static function ( int $lite ): array {
					return [ $lite, 999999 ];
				},
			],
		];
	}

	public function test_an_empty_list_clears_the_saved_order(): void {
		( new PlanOrder() )->set( [ $this->make_plan()->get_id() ] );

		$response = $this->reorder( [] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'ids' => [] ], $response->get_data() );
		$this->assertSame( [], ( new PlanOrder() )->get() );
	}

	public function test_ids_are_required(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * POST an order.
	 *
	 * @param array<int, int|string> $ids Plan ids.
	 */
	private function reorder( array $ids ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params( [ 'ids' => $ids ] );

		return rest_do_request( $request );
	}
}
