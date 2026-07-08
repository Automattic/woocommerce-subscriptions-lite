<?php
/**
 * Integration test: a value-less BOGO plan round-trips through the plans REST
 * endpoint that Lite exposes by bundling the engine.
 *
 * The engine unit-tests its own controller; this test guards the vertical from
 * Lite's vantage - Lite boots the engine, the route registers, and a BOGO plan
 * built the way the editor builds it (type only, no amount) persists and reads
 * back. Because Lite's CI resolves the engine from trunk, it also catches a
 * future engine change that would break BOGO for Lite.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Plans;

use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @group plans-rest
 */
final class BogoRoundTripTest extends LiteIntegrationTestCase {

	private const BASE = '/wc/v3/subscriptions-engine/plans';

	private const EXTENSION_SLUG = 'woocommerce-subscriptions-lite';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// Force the REST server to boot so the engine's routes register.
		rest_get_server();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_value_less_bogo_plan_persists_and_reads_back(): void {
		// Mirrors the editor's payload: a BOGO entry carries no amount.
		$created = $this->request(
			'POST',
			self::BASE,
			[
				'extension_slug' => self::EXTENSION_SLUG,
				'name'           => 'Monthly BOGO',
				'billing_policy' => [
					'period'   => 'month',
					'interval' => 1,
				],
				'pricing_policy' => [
					'policies' => [
						[
							'type'            => 'bogo',
							'duration_cycles' => 1,
						],
					],
				],
			]
		);

		$this->assertSame( 201, $created->get_status(), 'A BOGO plan is created.' );

		$created_policy = $this->first_policy( $created->get_data() );
		$this->assertSame( 'bogo', $created_policy['type'] );
		$this->assertSame( 0.0, $created_policy['value'], 'A value-less BOGO entry normalizes to 0.' );
		$this->assertSame( 1, $created_policy['duration_cycles'], 'The cycle scope is preserved.' );

		// A fresh read round-trips the stored shape through the database.
		$id      = (int) $created->get_data()['id'];
		$fetched = $this->request( 'GET', self::BASE . '/' . $id, [], [ 'extension_slug' => self::EXTENSION_SLUG ] );
		$this->assertSame( 200, $fetched->get_status() );

		$fetched_policy = $this->first_policy( $fetched->get_data() );
		$this->assertSame( 'bogo', $fetched_policy['type'] );
		$this->assertSame( 0.0, $fetched_policy['value'] );
		$this->assertSame( 1, $fetched_policy['duration_cycles'] );
	}

	/**
	 * Dispatch a REST request against the plans controller.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Route path.
	 * @param array<string, mixed> $body   Body params.
	 * @param array<string, mixed> $query  Query params.
	 * @return WP_REST_Response
	 */
	private function request( string $method, string $path, array $body = [], array $query = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		if ( ! empty( $body ) ) {
			$request->set_body_params( $body );
		}
		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		return rest_do_request( $request );
	}

	/**
	 * Extract the first pricing policy entry from a plan response body.
	 *
	 * @param mixed $data Plan response data.
	 * @return array<string, mixed>
	 */
	private function first_policy( $data ): array {
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'pricing_policy', $data );
		$this->assertIsArray( $data['pricing_policy'] );
		$this->assertArrayHasKey( 'policies', $data['pricing_policy'] );
		$this->assertIsArray( $data['pricing_policy']['policies'] );
		$this->assertArrayHasKey( 0, $data['pricing_policy']['policies'] );

		return $data['pricing_policy']['policies'][0];
	}
}
