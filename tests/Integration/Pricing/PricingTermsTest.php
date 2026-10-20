<?php
/**
 * Integration tests for Lite's pricing terms: tolerant reads, strict write
 * validation, and the normalized shape.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsLite\Pricing\PricingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\PricingTerms
 */
final class PricingTermsTest extends LiteIntegrationTestCase {

	public function test_tolerant_parse_drops_malformed_entries_and_keeps_valid_neighbours_in_order(): void {
		$terms = PricingTerms::from_array(
			[
				'policies' => [
					'not an entry',
					[
						'type'  => 'percentage',
						'value' => 10,
					],
					[
						'type'  => 'tiered',
						'value' => 5,
					],
					[
						'type'  => 'fixed_amount',
						'value' => 'lots',
					],
					[
						'type'           => 'price',
						'value'          => 4,
						'starting_cycle' => 0,
					],
					[
						'type'            => 'price',
						'value'           => 4,
						'duration_cycles' => -1,
					],
					[
						'type'           => 'price',
						'value'          => 4,
						'starting_cycle' => 1.5,
					],
					[
						'type'           => 'price',
						'value'          => 4,
						'starting_cycle' => 'abc',
					],
					[
						'type'  => 'fixed_amount',
						'value' => 2,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'  => 'percentage',
					'value' => 10.0,
				],
				[
					'type'  => 'fixed_amount',
					'value' => 2.0,
				],
			],
			$terms->get_policies()
		);
	}

	public function test_tolerant_parse_drops_out_of_range_entries(): void {
		$terms = PricingTerms::from_array(
			[
				'policies'      => [
					[
						'type'  => 'percentage',
						'value' => 150,
					],
					[
						'type'  => 'percentage',
						'value' => -10,
					],
					[
						'type'  => 'fixed_amount',
						'value' => -5,
					],
					[
						'type'  => 'price',
						'value' => -1,
					],
					[
						'type'  => 'percentage',
						'value' => 100,
					],
				],
				'one_time_fees' => [
					[
						'kind'   => 'setup',
						'amount' => -5,
					],
					[
						'kind'   => 'overflow',
						'amount' => INF,
					],
					[
						'kind'   => 'service',
						'amount' => 0,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'  => 'percentage',
					'value' => 100.0,
				],
			],
			$terms->get_policies()
		);
		$fees = $terms->get_one_time_fees();
		$this->assertCount( 1, $fees );
		$this->assertSame( 'service', $fees[0]['kind'] );
	}

	public function test_tolerant_parse_drops_non_finite_policy_values(): void {
		$terms = PricingTerms::from_array(
			[
				'policies' => [
					[
						'type'  => 'fixed_amount',
						'value' => INF,
					],
					[
						'type'  => 'price',
						'value' => -INF,
					],
					[
						'type'  => 'percentage',
						'value' => NAN,
					],
					[
						'type'  => 'percentage',
						'value' => 5,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'  => 'percentage',
					'value' => 5.0,
				],
			],
			$terms->get_policies()
		);
	}

	public function test_a_stored_bogo_value_reads_as_zero_and_keeps_the_entry(): void {
		$terms = PricingTerms::from_array(
			[
				'policies' => [
					[
						'type'  => 'bogo',
						'value' => 'lots',
					],
					[
						'type'  => 'bogo',
						'value' => -3,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'  => 'bogo',
					'value' => 0.0,
				],
				[
					'type'  => 'bogo',
					'value' => 0.0,
				],
			],
			$terms->get_policies()
		);
	}

	public function test_missing_value_reads_as_zero_and_null_gates_read_as_absent(): void {
		$terms = PricingTerms::from_array(
			[
				'policies' => [
					[
						'type'            => 'fixed_amount',
						'starting_cycle'  => null,
						'duration_cycles' => null,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'  => 'fixed_amount',
					'value' => 0.0,
				],
			],
			$terms->get_policies()
		);
	}

	public function test_integer_like_gates_normalize_to_int(): void {
		$terms = PricingTerms::from_array(
			[
				'policies' => [
					[
						'type'            => 'percentage',
						'value'           => 10,
						'starting_cycle'  => '2',
						'duration_cycles' => 3.0,
					],
				],
			]
		);

		$policy = $terms->get_policies()[0];
		$this->assertSame( 2, $policy['starting_cycle'] );
		$this->assertSame( 3, $policy['duration_cycles'] );
	}

	public function test_whole_number_values_normalize_to_float(): void {
		$terms = PricingTerms::from_array(
			[
				'policies'      => [
					[
						'type'  => 'percentage',
						'value' => 10,
					],
				],
				'one_time_fees' => [
					[
						'kind'    => 'enrollment',
						'amount'  => 15,
						'taxable' => true,
					],
				],
			]
		);

		$this->assertIsFloat( $terms->get_policies()[0]['value'] );
		$this->assertIsFloat( $terms->get_one_time_fees()[0]['amount'] );
	}

	public function test_value_less_bogo_normalizes_to_zero_value(): void {
		$terms = PricingTerms::from_array(
			[
				'policies' => [
					[
						'type'            => 'bogo',
						'duration_cycles' => 1,
					],
					[
						'type'  => 'bogo',
						'value' => 5,
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'            => 'bogo',
					'value'           => 0.0,
					'duration_cycles' => 1,
				],
				[
					'type'  => 'bogo',
					'value' => 0.0,
				],
			],
			$terms->get_policies()
		);
		$this->assertTrue( $terms->has_type( PricingTerms::TYPE_BOGO ) );
		$this->assertFalse( $terms->has_type( PricingTerms::TYPE_PERCENTAGE ) );
	}

	public function test_fees_normalize_to_typed_shape(): void {
		$terms = PricingTerms::from_array(
			[
				'one_time_fees' => [
					[
						'kind'   => 'setup',
						'amount' => 5,
					],
					[
						'kind'      => 'service',
						'amount'    => 7,
						'tax_class' => '',
					],
					'not a fee',
				],
			]
		);

		$fees = $terms->get_one_time_fees();

		$this->assertCount( 2, $fees );
		// A fee without taxable/tax_class normalizes to taxable=false, tax_class=null.
		$this->assertFalse( $fees[0]['taxable'] );
		$this->assertNull( $fees[0]['tax_class'] );
		// A supplied empty-string tax_class is preserved, not coerced to null.
		$this->assertFalse( $fees[1]['taxable'] );
		$this->assertSame( '', $fees[1]['tax_class'] );
	}

	/**
	 * @dataProvider provide_taxable_values
	 *
	 * @param mixed $supplied Raw taxable value as it might arrive from storage.
	 * @param bool  $expected Expected normalized boolean.
	 */
	public function test_taxable_is_interpreted_as_a_real_boolean( $supplied, bool $expected ): void {
		$terms = PricingTerms::from_array(
			[
				'one_time_fees' => [
					[
						'kind'    => 'setup',
						'amount'  => 5,
						'taxable' => $supplied,
					],
				],
			]
		);

		$this->assertSame( $expected, $terms->get_one_time_fees()[0]['taxable'] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function provide_taxable_values(): array {
		return [
			'bool true'    => [ true, true ],
			'bool false'   => [ false, false ],
			'string true'  => [ 'true', true ],
			'string false' => [ 'false', false ],
			'string one'   => [ '1', true ],
			'string zero'  => [ '0', false ],
			'unrecognized' => [ 'maybe', false ],
		];
	}

	public function test_from_plan_without_payload_is_empty(): void {
		$terms = PricingTerms::from_plan( $this->make_plan() );

		$this->assertSame( [], $terms->get_policies() );
		$this->assertSame( [], $terms->get_one_time_fees() );
	}

	public function test_from_plan_reads_no_plan_as_empty_terms(): void {
		$terms = PricingTerms::from_plan( null );

		$this->assertSame( [], $terms->get_policies() );
		$this->assertSame( [], $terms->get_one_time_fees() );
	}

	/**
	 * @dataProvider provide_valid_payloads
	 *
	 * @param mixed $payload Valid payload.
	 */
	public function test_validate_accepts_valid_payloads( $payload ): void {
		$this->assertFalse( PricingTerms::validate( $payload )->has_errors() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_valid_payloads(): array {
		return [
			'null'                   => [ null ],
			'empty object'           => [ [] ],
			'empty lists'            => [
				[
					'policies'      => [],
					'one_time_fees' => [],
				],
			],
			'value-less bogo'        => [ [ 'policies' => [ [ 'type' => 'bogo' ] ] ] ],
			'bogo with zero value'   => [
				[
					'policies' => [
						[
							'type'  => 'bogo',
							'value' => 0,
						],
					],
				],
			],
			'percentage at 100'      => [
				[
					'policies' => [
						[
							'type'  => 'percentage',
							'value' => 100,
						],
					],
				],
			],
			'missing value'          => [ [ 'policies' => [ [ 'type' => 'fixed_amount' ] ] ] ],
			'integer-like gates'     => [
				[
					'policies' => [
						[
							'type'            => 'price',
							'value'           => '5.5',
							'starting_cycle'  => '2',
							'duration_cycles' => 3.0,
						],
					],
				],
			],
			'null gates'             => [
				[
					'policies' => [
						[
							'type'           => 'price',
							'value'          => 5,
							'starting_cycle' => null,
						],
					],
				],
			],
			'fee with string bool'   => [
				[
					'one_time_fees' => [
						[
							'kind'      => 'setup',
							'amount'    => 5,
							'taxable'   => 'false',
							'tax_class' => '',
						],
					],
				],
			],
			'fee without amount'     => [ [ 'one_time_fees' => [ [ 'kind' => 'setup' ] ] ] ],
			'unknown top-level keys' => [ [ 'currency_overrides' => [ 'EUR' => 5 ] ] ],
		];
	}

	/**
	 * @dataProvider provide_invalid_payloads
	 *
	 * @param mixed  $payload Invalid payload.
	 * @param string $code    Expected first error code.
	 * @param string $field   Expected first error's `field` data.
	 * @param string $message Expected first error message.
	 */
	public function test_validate_rejects_invalid_payloads( $payload, string $code, string $field, string $message ): void {
		$errors = PricingTerms::validate( $payload );

		$this->assertSame( $code, $errors->get_error_code() );
		$this->assertSame( $message, $errors->get_error_message() );
		$this->assertSame( $field, $errors->get_all_error_data( $code )[0]['field'] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: string, 3: string}>
	 */
	public function provide_invalid_payloads(): array {
		return [
			'non-object'                   => [ 'bogo', 'invalid_type', 'pricing_policy', 'pricing_policy must be an object or null.' ],
			'policies not a list'          => [ [ 'policies' => [ 'type' => 'bogo' ] ], 'invalid_type', 'pricing_policy[policies]', 'pricing_policy[policies] must be a list.' ],
			'fees not a list'              => [ [ 'one_time_fees' => 'none' ], 'invalid_type', 'pricing_policy[one_time_fees]', 'pricing_policy[one_time_fees] must be a list.' ],
			'non-object entry'             => [ [ 'policies' => [ 'bogo' ] ], 'invalid_type', 'pricing_policy[policies][0]', 'pricing_policy[policies][0] must be an object.' ],
			'unknown type'                 => [ [ 'policies' => [ self::policy( 'mystery', 1 ) ] ], 'not_in_enum', 'pricing_policy[policies][0][type]', 'pricing_policy[policies][0][type] must be one of percentage, fixed_amount, price, bogo.' ],
			'missing type'                 => [ [ 'policies' => [ [ 'value' => 1 ] ] ], 'not_in_enum', 'pricing_policy[policies][0][type]', 'pricing_policy[policies][0][type] must be one of percentage, fixed_amount, price, bogo.' ],
			'non-numeric value'            => [ [ 'policies' => [ self::policy( 'percentage', 'ten' ) ] ], 'invalid_type', 'pricing_policy[policies][0][value]', 'pricing_policy[policies][0][value] must be a number.' ],
			'infinite value'               => [ [ 'policies' => [ self::policy( 'fixed_amount', INF ) ] ], 'invalid_value', 'pricing_policy[policies][0][value]', 'pricing_policy[policies][0][value] must be a finite number.' ],
			'negative value'               => [ [ 'policies' => [ self::policy( 'fixed_amount', -1 ) ] ], 'out_of_bounds', 'pricing_policy[policies][0][value]', 'pricing_policy[policies][0][value] must not be negative.' ],
			'percentage over 100'          => [ [ 'policies' => [ self::policy( 'percentage', 150 ) ] ], 'out_of_bounds', 'pricing_policy[policies][0][value]', 'pricing_policy[policies][0][value] must not exceed 100 for a percentage policy.' ],
			'bogo with a value'            => [ [ 'policies' => [ self::policy( 'bogo', 5 ) ] ], 'invalid_value', 'pricing_policy[policies][0][value]', 'pricing_policy[policies][0][value] must be 0 or omitted for a bogo policy.' ],
			'starting_cycle zero'          => [ [ 'policies' => [ self::policy( 'price', 5, [ 'starting_cycle' => 0 ] ) ] ], 'out_of_bounds', 'pricing_policy[policies][0][starting_cycle]', 'pricing_policy[policies][0][starting_cycle] must be 1 or greater.' ],
			'duration_cycles negative'     => [ [ 'policies' => [ self::policy( 'price', 5, [ 'duration_cycles' => -1 ] ) ] ], 'out_of_bounds', 'pricing_policy[policies][0][duration_cycles]', 'pricing_policy[policies][0][duration_cycles] must be 1 or greater.' ],
			'fractional starting float'    => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'starting_cycle' => 1.5 ] ) ] ], 'invalid_type', 'pricing_policy[policies][0][starting_cycle]', 'pricing_policy[policies][0][starting_cycle] must be an integer.' ],
			'fractional starting string'   => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'starting_cycle' => '1.5' ] ) ] ], 'invalid_type', 'pricing_policy[policies][0][starting_cycle]', 'pricing_policy[policies][0][starting_cycle] must be an integer.' ],
			'non-numeric starting string'  => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'starting_cycle' => 'soon' ] ) ] ], 'invalid_type', 'pricing_policy[policies][0][starting_cycle]', 'pricing_policy[policies][0][starting_cycle] must be an integer.' ],
			'fractional duration float'    => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'duration_cycles' => 1.5 ] ) ] ], 'invalid_type', 'pricing_policy[policies][0][duration_cycles]', 'pricing_policy[policies][0][duration_cycles] must be an integer.' ],
			'fractional duration string'   => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'duration_cycles' => '1.5' ] ) ] ], 'invalid_type', 'pricing_policy[policies][0][duration_cycles]', 'pricing_policy[policies][0][duration_cycles] must be an integer.' ],
			'non-numeric duration string'  => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'duration_cycles' => 'forever' ] ) ] ], 'invalid_type', 'pricing_policy[policies][0][duration_cycles]', 'pricing_policy[policies][0][duration_cycles] must be an integer.' ],
			'second entry reports index 1' => [ [ 'policies' => [ self::policy( 'bogo', 0 ), self::policy( 'percentage', 101 ) ] ], 'out_of_bounds', 'pricing_policy[policies][1][value]', 'pricing_policy[policies][1][value] must not exceed 100 for a percentage policy.' ],
			'non-object fee'               => [ [ 'one_time_fees' => [ 5 ] ], 'invalid_type', 'pricing_policy[one_time_fees][0]', 'pricing_policy[one_time_fees][0] must be an object.' ],
			'non-numeric fee amount'       => [ [ 'one_time_fees' => [ [ 'amount' => 'five' ] ] ], 'invalid_type', 'pricing_policy[one_time_fees][0][amount]', 'pricing_policy[one_time_fees][0][amount] must be a number.' ],
			'negative fee amount'          => [ [ 'one_time_fees' => [ [ 'amount' => -5 ] ] ], 'out_of_bounds', 'pricing_policy[one_time_fees][0][amount]', 'pricing_policy[one_time_fees][0][amount] must not be negative.' ],
			'infinite fee amount'          => [ [ 'one_time_fees' => [ [ 'amount' => INF ] ] ], 'invalid_value', 'pricing_policy[one_time_fees][0][amount]', 'pricing_policy[one_time_fees][0][amount] must be a finite number.' ],
			'non-bool taxable'             => [
				[
					'one_time_fees' => [
						[
							'amount'  => 5,
							'taxable' => 'maybe',
						],
					],
				],
				'invalid_type',
				'pricing_policy[one_time_fees][0][taxable]',
				'pricing_policy[one_time_fees][0][taxable] must be true or false.',
			],
			'non-string tax_class'         => [
				[
					'one_time_fees' => [
						[
							'amount'    => 5,
							'tax_class' => [ 'reduced' ],
						],
					],
				],
				'invalid_type',
				'pricing_policy[one_time_fees][0][tax_class]',
				'pricing_policy[one_time_fees][0][tax_class] must be a string or null.',
			],
		];
	}

	public function test_validate_reports_params_and_every_error(): void {
		$errors = PricingTerms::validate(
			[
				'policies'      => [ self::policy( 'percentage', 150 ) ],
				'one_time_fees' => [ [ 'amount' => -5 ] ],
			]
		);

		$this->assertSame(
			[
				[
					'field'  => 'pricing_policy[policies][0][value]',
					'params' => [ 'max' => 100 ],
				],
				[
					'field'  => 'pricing_policy[one_time_fees][0][amount]',
					'params' => [ 'min' => 0 ],
				],
			],
			$errors->get_all_error_data( 'out_of_bounds' )
		);
		$this->assertCount( 2, $errors->get_error_messages( 'out_of_bounds' ) );
	}

	public function test_validate_translates_its_messages(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'woocommerce-subscriptions-lite' === $domain && '%s must be a list.' === $text ? 'Translated %s' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$errors = PricingTerms::validate( [ 'policies' => 'none' ] );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( 'Translated pricing_policy[policies]', $errors->get_error_message() );
	}

	/**
	 * A policy entry.
	 *
	 * @param string               $type  Policy type.
	 * @param mixed                $value Policy value.
	 * @param array<string, mixed> $extra Extra keys (cycle gates).
	 * @return array<string, mixed>
	 */
	private static function policy( string $type, $value, array $extra = [] ): array {
		return array_merge(
			[
				'type'  => $type,
				'value' => $value,
			],
			$extra
		);
	}
}
