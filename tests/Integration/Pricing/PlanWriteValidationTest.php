<?php
/**
 * Integration tests for Lite's billing and pricing validation on plan writes,
 * dispatched through the engine plan facade, its plans REST route and the plan
 * validation action.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Plans;
use Automattic\WooCommerce\SubscriptionsEngine\Api\ValidationException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\PlanWriteValidation;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\PlanWriteValidation
 * @group plans-rest
 */
final class PlanWriteValidationTest extends LiteIntegrationTestCase {

	private const BASE = '/wc/v3/subscriptions-engine/plans';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		rest_get_server();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @dataProvider provide_invalid_policies
	 *
	 * @param array<string, mixed> $pricing_policy Invalid pricing payload.
	 * @param string               $code           Expected engine error code.
	 * @param string               $field          Expected field path.
	 * @param string               $message        Expected translated message.
	 */
	public function test_an_invalid_create_is_rejected_and_nothing_is_stored( array $pricing_policy, string $code, string $field, string $message ): void {
		$response = $this->create_plan( $pricing_policy );

		$this->assert_invalid_param( $response, 'pricing_policy', $code, $field, $message );
		$this->assertSame( [], $this->list_plans() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
	 */
	public function provide_invalid_policies(): array {
		return [
			'unknown type'        => [
				[ 'policies' => [ [ 'type' => 'mystery' ] ] ],
				'not_in_enum',
				'pricing_policy[policies][0][type]',
				'pricing_policy[policies][0][type] must be one of percentage, fixed_amount, price, bogo.',
			],
			'percentage over 100' => [
				[
					'policies' => [
						[
							'type'  => 'percentage',
							'value' => 150,
						],
					],
				],
				'out_of_bounds',
				'pricing_policy[policies][0][value]',
				'pricing_policy[policies][0][value] must not exceed 100 for a percentage policy.',
			],
			'bogo with a value'   => [
				[
					'policies' => [
						[
							'type'  => 'bogo',
							'value' => 5,
						],
					],
				],
				'invalid_value',
				'pricing_policy[policies][0][value]',
				'pricing_policy[policies][0][value] must be 0 or omitted for a bogo policy.',
			],
			'zero duration'       => [
				[
					'policies' => [
						[
							'type'            => 'bogo',
							'duration_cycles' => 0,
						],
					],
				],
				'out_of_bounds',
				'pricing_policy[policies][0][duration_cycles]',
				'pricing_policy[policies][0][duration_cycles] must be 1 or greater.',
			],
			'negative fee'        => [
				[
					'one_time_fees' => [
						[
							'kind'   => 'setup',
							'amount' => -5,
						],
					],
				],
				'out_of_bounds',
				'pricing_policy[one_time_fees][0][amount]',
				'pricing_policy[one_time_fees][0][amount] must not be negative.',
			],
		];
	}

	public function test_a_valid_create_is_stored_as_sent(): void {
		$policies = [
			[
				'type'           => 'percentage',
				'value'          => 10,
				'starting_cycle' => '2',
			],
			[ 'type' => 'bogo' ],
		];
		$response = $this->create_plan(
			[
				'policies'           => $policies,
				'currency_overrides' => [ 'EUR' => 5 ],
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$created = $response->get_data();
		$this->assertIsArray( $created );
		$this->assertSame( $policies, $created['pricing_policy']['policies'] );

		$stored = $this->fetch_pricing_policy( $this->plan_id( $response ) );
		$this->assertSame( $policies, $stored['policies'], 'Whole numbers stay integers and BOGO stays value-less.' );
		$this->assertSame( [ 'EUR' => 5 ], $stored['currency_overrides'], 'Keys Lite does not own pass through untouched.' );
		$this->assertArrayNotHasKey( 'one_time_fees', $stored );
	}

	public function test_a_patch_validates_and_replaces_the_whole_pricing_payload(): void {
		$id = $this->plan_id(
			$this->create_plan(
				[
					'policies' => [
						[
							'type'  => 'percentage',
							'value' => 10,
						],
					],
				]
			)
		);

		$invalid = $this->patch_plan( $id, [ 'one_time_fees' => [ [ 'amount' => 'five' ] ] ] );
		$this->assert_invalid_param(
			$invalid,
			'pricing_policy',
			'invalid_type',
			'pricing_policy[one_time_fees][0][amount]',
			'pricing_policy[one_time_fees][0][amount] must be a number.'
		);

		$fees  = [
			[
				'kind'    => 'setup',
				'amount'  => 5,
				'taxable' => 'true',
			],
		];
		$valid = $this->patch_plan( $id, [ 'one_time_fees' => $fees ] );
		$this->assertSame( 200, $valid->get_status() );

		$this->assertSame( [ 'one_time_fees' => $fees ], $this->fetch_pricing_policy( $id ) );
	}

	public function test_an_invalid_patch_leaves_the_stored_plan_unchanged(): void {
		$id     = $this->plan_id( $this->create_plan( [ 'policies' => [ [ 'type' => 'bogo' ] ] ] ) );
		$before = $this->fetch_pricing_policy( $id );

		$response = $this->patch_plan(
			$id,
			[
				'policies' => [
					[
						'type'  => 'percentage',
						'value' => 101,
					],
				],
			]
		);

		$this->assert_invalid_param(
			$response,
			'pricing_policy',
			'out_of_bounds',
			'pricing_policy[policies][0][value]',
			'pricing_policy[policies][0][value] must not exceed 100 for a percentage policy.'
		);
		$this->assertSame( $before, $this->fetch_pricing_policy( $id ) );
	}

	public function test_a_differently_cased_route_is_still_validated(): void {
		$response = $this->create_plan(
			[
				'policies' => [
					[
						'type'  => 'percentage',
						'value' => 150,
					],
				],
			],
			Package::EXTENSION_SLUG,
			'/wc/v3/subscriptions-engine/PLANS'
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $this->error_code( $response ) );
		$this->assertSame( [], $this->list_plans() );
	}

	public function test_a_plan_owned_by_another_extension_is_stored_as_given(): void {
		$payload  = [
			'policies' => [
				[
					'type'  => 'tiered',
					'value' => 5,
				],
			],
		];
		$response = $this->create_plan( $payload, 'other-extension' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $payload, $this->fetch_pricing_policy( $this->plan_id( $response ), 'other-extension' ) );
	}

	public function test_an_unauthenticated_invalid_create_gets_the_route_auth_error(): void {
		wp_set_current_user( 0 );

		$response = $this->create_plan( [ 'policies' => [ [ 'type' => 'mystery' ] ] ] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertNotSame( 'rest_invalid_param', $this->error_code( $response ) );
	}

	public function test_an_earlier_callback_error_accumulates_with_lite_errors(): void {
		$earlier = static function ( WP_Error $errors ): void {
			$errors->add( 'earlier_error', 'Earlier.', [ 'status' => 418 ] );
		};
		add_action( 'woocommerce_subscriptions_engine_validate_plan', $earlier, 5 );

		$response = $this->create_plan( [ 'policies' => [ [ 'type' => 'mystery' ] ] ] );

		remove_action( 'woocommerce_subscriptions_engine_validate_plan', $earlier, 5 );
		$this->assert_invalid_param(
			$response,
			'pricing_policy',
			'not_in_enum',
			'pricing_policy[policies][0][type]',
			'pricing_policy[policies][0][type] must be one of percentage, fixed_amount, price, bogo.'
		);
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( [ 'earlier_error' ], array_column( $data['additional_errors'], 'code' ) );
		$this->assertSame( 'Earlier.', $data['additional_errors'][0]['message'] );
		$this->assertSame( [], $this->list_plans() );
	}

	public function test_a_foreign_extension_plan_adds_no_errors(): void {
		$errors = new WP_Error();

		( new PlanWriteValidation() )->validate_plan( $errors, $this->lite_view( [ 'policies' => [ [ 'type' => 'mystery' ] ] ] ), 'other-extension' );

		$this->assertFalse( $errors->has_errors() );
	}

	public function test_a_valid_lite_plan_adds_no_errors_and_is_not_changed(): void {
		$pricing_policy = [
			'policies'   => [
				[
					'type'  => 'percentage',
					'value' => 10,
				],
				[ 'type' => 'bogo' ],
			],
			'custom_key' => 'kept',
		];
		$plan           = $this->lite_view( $pricing_policy );
		$errors         = new WP_Error();

		( new PlanWriteValidation() )->validate_plan( $errors, $plan, Package::EXTENSION_SLUG );

		$this->assertFalse( $errors->has_errors() );
		$this->assertSame( $pricing_policy, $plan->get_pricing_policy() );
	}

	public function test_each_invalid_term_adds_an_error_and_keeps_earlier_ones(): void {
		$errors = new WP_Error( 'earlier_error', 'Earlier.' );
		$plan   = $this->lite_view(
			[
				'policies'      => [ [ 'type' => 'mystery' ] ],
				'one_time_fees' => [
					[
						'kind'   => 'setup',
						'amount' => -5,
					],
				],
			]
		);

		( new PlanWriteValidation() )->validate_plan( $errors, $plan, Package::EXTENSION_SLUG );

		$this->assertSame( [ 'earlier_error', 'not_in_enum', 'out_of_bounds' ], $errors->get_error_codes() );
		$this->assertSame( [ 'Earlier.' ], $errors->get_error_messages( 'earlier_error' ) );
		$this->assertSame(
			[
				'field'  => 'pricing_policy[policies][0][type]',
				'params' => [ 'allowed' => [ 'percentage', 'fixed_amount', 'price', 'bogo' ] ],
			],
			$errors->get_error_data( 'not_in_enum' )
		);
		$this->assertSame(
			[
				'field'  => 'pricing_policy[one_time_fees][0][amount]',
				'params' => [ 'min' => 0 ],
			],
			$errors->get_error_data( 'out_of_bounds' )
		);
	}

	public function test_a_status_only_update_validates_the_stored_payloads(): void {
		// Store invalid policies with Lite's validation unhooked, as a pre-existing row would be.
		remove_all_actions( 'woocommerce_subscriptions_engine_validate_plan' );
		$id = $this->plan_id( $this->create_plan( [ 'policies' => [ [ 'type' => 'mystery' ] ] ] ) );
		PlanWriteValidation::register();

		try {
			Plans::update(
				$id,
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => 'archived',
				]
			);
			$this->fail( 'A status-only update of a plan with invalid stored pricing must be refused.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( [ 'not_in_enum' ], $e->get_errors()->get_error_codes() );
			$this->assertSame( 'pricing_policy[policies][0][type]', $e->get_errors()->get_error_data( 'not_in_enum' )['field'] );
		}

		$plan = Plans::get( $id );
		$this->assertNotNull( $plan );
		$this->assertSame( 'active', $plan->get_status() );
	}

	/**
	 * @dataProvider provide_unusable_billing
	 *
	 * @param array<string, mixed>|null $billing_policy Billing payload.
	 * @param string                    $code           Expected error code.
	 * @param string                    $field          Expected field path.
	 * @param string                    $message        Expected translated message.
	 */
	public function test_a_create_without_usable_billing_is_rejected_and_nothing_is_stored( ?array $billing_policy, string $code, string $field, string $message ): void {
		$response = $this->create_plan( null, Package::EXTENSION_SLUG, self::BASE, $billing_policy );

		$this->assert_invalid_param( $response, 'billing_policy', $code, $field, $message );
		$this->assertSame( [], $this->list_plans() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: string, 2: string, 3: string}>
	 */
	public function provide_unusable_billing(): array {
		$cadence = 'billing_policy must have a period (day, week, month or year) and a positive interval.';

		return [
			'no billing policy'   => [ null, 'required', 'billing_policy', $cadence ],
			'missing interval'    => [ [ 'period' => 'month' ], 'invalid_value', 'billing_policy', $cadence ],
			'unknown period'      => [
				[
					'period'   => 'fortnight',
					'interval' => 1,
				],
				'invalid_value',
				'billing_policy',
				$cadence,
			],
			'zero interval'       => [
				[
					'period'   => 'month',
					'interval' => 0,
				],
				'invalid_value',
				'billing_policy',
				$cadence,
			],
			'string interval'     => [
				[
					'period'   => 'month',
					'interval' => '1',
				],
				'invalid_type',
				'billing_policy[interval]',
				'billing_policy[interval] is not of type integer.',
			],
			'unknown trial unit'  => [
				[
					'period'         => 'month',
					'interval'       => 1,
					'trial_duration' => [
						'length' => 1,
						'unit'   => 'fortnight',
					],
				],
				'not_in_enum',
				'billing_policy[trial_duration][unit]',
				'billing_policy[trial_duration][unit] is not one of day, week, month, year.',
			],
			'min above max cycle' => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'min_cycles' => 5,
					'max_cycles' => 2,
				],
				'out_of_bounds',
				'billing_policy[min_cycles]',
				'billing_policy[min_cycles] must be less than or equal to 2.',
			],
			'zero max cycles'     => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => 0,
				],
				'out_of_bounds',
				'billing_policy[max_cycles]',
				'billing_policy[max_cycles] must be a positive integer, or null for no limit.',
			],
		];
	}

	public function test_an_engine_billing_error_reaches_rest_translated(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'woocommerce' === $domain && '%1$s must be less than or equal to %2$d.' === $text ? '%1$s: at most %2$d.' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$response = $this->create_plan(
				null,
				Package::EXTENSION_SLUG,
				self::BASE,
				[
					'period'     => 'month',
					'interval'   => 1,
					'min_cycles' => 5,
					'max_cycles' => 2,
				]
			);
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assert_invalid_param( $response, 'billing_policy', 'out_of_bounds', 'billing_policy[min_cycles]', 'billing_policy[min_cycles]: at most 2.' );
		$this->assertSame( [ 'max' => 2 ], $this->error_data( $response )['details']['billing_policy']['data']['params'] );
	}

	public function test_a_plan_with_unusable_stored_billing_can_be_archived_but_not_restored(): void {
		$plan = $this->make_unvalidated_plan( 'month', 1, null, [ 'billing_policy' => null ] );
		$id   = $plan->get_id();

		$this->assertInstanceOf(
			PlanView::class,
			Plans::update(
				$id,
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => 'archived',
				]
			),
			'Archiving skips the billing check.'
		);

		try {
			Plans::update(
				$id,
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'status'         => 'active',
				]
			);
			$this->fail( 'Restoring a plan without usable billing must be refused.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( [ 'required' ], $e->get_errors()->get_error_codes() );
			$this->assertSame( 'billing_policy', $e->get_errors()->get_error_data( 'required' )['field'] );
		}

		$stored = Plans::get( $id );
		$this->assertNotNull( $stored );
		$this->assertSame( 'archived', $stored->get_status() );
	}

	public function test_an_archived_plan_billing_change_is_validated(): void {
		$id = $this->make_plan( 'month', 1, null, [ 'status' => 'archived' ] )->get_id();

		try {
			Plans::update(
				$id,
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'billing_policy' => [
						'period'   => 'month',
						'interval' => 0,
					],
				]
			);
			$this->fail( 'An unbillable billing payload must be refused on an archived plan too.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( [ 'invalid_value' ], $e->get_errors()->get_error_codes() );
		}

		$stored = Plans::update(
			$id,
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'name'           => 'Renamed',
				'billing_policy' => [
					'period'   => 'week',
					'interval' => 2,
				],
			]
		);

		$this->assertNotNull( $stored, 'A billable change to an archived plan is accepted.' );
		$this->assertSame( 'week', $stored->get_billing_policy()['period'] ?? null );
	}

	public function test_creating_an_archived_plan_validates_its_billing(): void {
		$this->expectException( ValidationException::class );

		Plans::create(
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'name'           => 'Archived on arrival',
				'status'         => 'archived',
				'billing_policy' => null,
			]
		);
	}

	public function test_the_php_facade_runs_the_lite_validation(): void {
		try {
			Plans::create(
				[
					'extension_slug' => Package::EXTENSION_SLUG,
					'name'           => 'Fortnightly',
					'billing_policy' => [
						'period'   => 'fortnight',
						'interval' => 1,
					],
				]
			);
			$this->fail( 'Plans::create() must run the Lite billing validation.' );
		} catch ( ValidationException $e ) {
			$this->assertSame( [ 'invalid_value' ], $e->get_errors()->get_error_codes() );
			$this->assertSame( 'billing_policy', $e->get_errors()->get_error_data( 'invalid_value' )['field'] );
		}

		$this->assertSame( [], Plans::list( [ 'extension_slug' => Package::EXTENSION_SLUG ] ) );
	}

	public function test_a_foreign_extension_plan_without_billing_is_not_checked(): void {
		$plan = Plans::create(
			[
				'extension_slug' => 'other-extension',
				'name'           => 'No cadence',
			]
		);

		$this->assertGreaterThan( 0, $plan->get_id() );
	}

	/**
	 * A stored Lite plan with the given pricing payload, written unvalidated.
	 *
	 * @param array<string, mixed> $pricing_policy Pricing payload.
	 */
	private function lite_view( array $pricing_policy ): PlanView {
		return $this->make_unvalidated_plan( 'month', 1, null, [ 'pricing_policy' => $pricing_policy ] );
	}

	/**
	 * POST a monthly plan.
	 *
	 * @param array<string, mixed>|null $pricing_policy Pricing payload.
	 * @param string                    $extension_slug Owner slug.
	 * @param string                    $route          Route to POST to.
	 * @param array<string, mixed>|null $billing_policy Billing payload.
	 */
	private function create_plan(
		?array $pricing_policy,
		string $extension_slug = Package::EXTENSION_SLUG,
		string $route = self::BASE,
		?array $billing_policy = [
			'period'   => 'month',
			'interval' => 1,
		]
	): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params(
			[
				'extension_slug' => $extension_slug,
				'name'           => 'Monthly',
				'billing_policy' => $billing_policy,
				'pricing_policy' => $pricing_policy,
			]
		);

		return rest_do_request( $request );
	}

	/**
	 * PATCH a Lite plan's pricing payload.
	 *
	 * @param int                  $id             Plan id.
	 * @param array<string, mixed> $pricing_policy Pricing payload.
	 */
	private function patch_plan( int $id, array $pricing_policy ): WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', self::BASE . '/' . $id );
		$request->set_body_params(
			[
				'extension_slug' => Package::EXTENSION_SLUG,
				'pricing_policy' => $pricing_policy,
			]
		);

		return rest_do_request( $request );
	}

	/**
	 * The stored pricing payload, read back through the route.
	 *
	 * @param int    $id             Plan id.
	 * @param string $extension_slug Owner slug.
	 * @return array<string, mixed>
	 */
	private function fetch_pricing_policy( int $id, string $extension_slug = Package::EXTENSION_SLUG ): array {
		$request = new WP_REST_Request( 'GET', self::BASE . '/' . $id );
		$request->set_query_params( [ 'extension_slug' => $extension_slug ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['pricing_policy'] );

		return $data['pricing_policy'];
	}

	/**
	 * Lite's stored plans, read back through the route.
	 *
	 * @return array<int, mixed>
	 */
	private function list_plans(): array {
		$request = new WP_REST_Request( 'GET', self::BASE );
		$request->set_query_params( [ 'extension_slug' => Package::EXTENSION_SLUG ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Plan id from a create response.
	 *
	 * @param WP_REST_Response $response Create response.
	 */
	private function plan_id( WP_REST_Response $response ): int {
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return (int) $data['id'];
	}

	/**
	 * Error code of an error response.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function error_code( WP_REST_Response $response ): string {
		$data = $response->get_data();

		return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
	}

	/**
	 * Data of an error response.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	private function error_data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['data'] ?? null );

		return $data['data'];
	}

	/**
	 * Assert a 400 `rest_invalid_param` response with one error on `$arg`: its translated
	 * message under `params`, and its code and field path under `details`.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $arg      Top-level request arg.
	 * @param string           $code     Engine error code.
	 * @param string           $field    Field path, in bracket notation.
	 * @param string           $message  Translated message.
	 */
	private function assert_invalid_param( WP_REST_Response $response, string $arg, string $code, string $field, string $message ): void {
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $this->error_code( $response ) );

		$data = $this->error_data( $response );
		$this->assertSame( [ $arg => $message ], $data['params'] );
		$this->assertSame( $code, $data['details'][ $arg ]['code'] );
		$this->assertSame( $field, $data['details'][ $arg ]['data']['field'] );
	}
}
