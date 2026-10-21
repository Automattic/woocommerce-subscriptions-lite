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

	use ListsValidationErrors;

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
	 * @param mixed                                                      $payload  Invalid payload.
	 * @param list<array{0: string, 1: string, 2: array<string, mixed>}> $expected Every error as code, field and params.
	 */
	public function test_validate_rejects_invalid_payloads( $payload, array $expected ): void {
		$this->assertSame( $expected, $this->get_validation_errors( PricingTerms::validate( $payload ) ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: list<array{0: string, 1: string, 2: array<string, mixed>}>}>
	 */
	public function provide_invalid_payloads(): array {
		$allowed = [ 'allowed' => PricingTerms::TYPES ];
		$object  = [ 'type' => 'object' ];
		$number  = [ 'type' => 'number' ];
		$integer = [ 'type' => 'integer' ];

		return [
			'non-object'                   => [ 'bogo', [ [ 'invalid_type', 'pricing_policy', $object ] ] ],
			'policies not a list'          => [ [ 'policies' => [ 'type' => 'bogo' ] ], [ [ 'invalid_type', 'pricing_policy.policies', [ 'type' => 'array' ] ] ] ],
			'fees not a list'              => [ [ 'one_time_fees' => 'none' ], [ [ 'invalid_type', 'pricing_policy.one_time_fees', [ 'type' => 'array' ] ] ] ],
			'neither is a list'            => [
				[
					'policies'      => 'none',
					'one_time_fees' => 'none',
				],
				[
					[ 'invalid_type', 'pricing_policy.policies', [ 'type' => 'array' ] ],
					[ 'invalid_type', 'pricing_policy.one_time_fees', [ 'type' => 'array' ] ],
				],
			],
			'fees not a list, bad policy'  => [
				[
					'policies'      => [ self::policy( 'mystery', 1 ) ],
					'one_time_fees' => 'none',
				],
				[
					[ 'not_in_enum', 'pricing_policy.policies.0.type', $allowed ],
					[ 'invalid_type', 'pricing_policy.one_time_fees', [ 'type' => 'array' ] ],
				],
			],
			'policies not a list, bad fee' => [
				[
					'policies'      => 'none',
					'one_time_fees' => [ [ 'amount' => -5 ] ],
				],
				[
					[ 'invalid_type', 'pricing_policy.policies', [ 'type' => 'array' ] ],
					[ 'out_of_bounds', 'pricing_policy.one_time_fees.0.amount', [ 'min' => 0 ] ],
				],
			],
			'non-object entry'             => [ [ 'policies' => [ 'bogo' ] ], [ [ 'invalid_type', 'pricing_policy.policies.0', $object ] ] ],
			'unknown type'                 => [ [ 'policies' => [ self::policy( 'mystery', 1 ) ] ], [ [ 'not_in_enum', 'pricing_policy.policies.0.type', $allowed ] ] ],
			'missing type'                 => [ [ 'policies' => [ [ 'value' => 1 ] ] ], [ [ 'not_in_enum', 'pricing_policy.policies.0.type', $allowed ] ] ],
			'non-numeric value'            => [ [ 'policies' => [ self::policy( 'percentage', 'ten' ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.value', $number ] ] ],
			'infinite value'               => [ [ 'policies' => [ self::policy( 'fixed_amount', INF ) ] ], [ [ 'invalid_value', 'pricing_policy.policies.0.value', [] ] ] ],
			'negative value'               => [ [ 'policies' => [ self::policy( 'fixed_amount', -1 ) ] ], [ [ 'out_of_bounds', 'pricing_policy.policies.0.value', [ 'min' => 0 ] ] ] ],
			'percentage over 100'          => [ [ 'policies' => [ self::policy( 'percentage', 150 ) ] ], [ [ 'out_of_bounds', 'pricing_policy.policies.0.value', [ 'max' => 100 ] ] ] ],
			'bogo with a value'            => [ [ 'policies' => [ self::policy( 'bogo', 5 ) ] ], [ [ 'invalid_value', 'pricing_policy.policies.0.value', [] ] ] ],
			'starting_cycle zero'          => [ [ 'policies' => [ self::policy( 'price', 5, [ 'starting_cycle' => 0 ] ) ] ], [ [ 'out_of_bounds', 'pricing_policy.policies.0.starting_cycle', [ 'min' => 1 ] ] ] ],
			'duration_cycles negative'     => [ [ 'policies' => [ self::policy( 'price', 5, [ 'duration_cycles' => -1 ] ) ] ], [ [ 'out_of_bounds', 'pricing_policy.policies.0.duration_cycles', [ 'min' => 1 ] ] ] ],
			'fractional starting float'    => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'starting_cycle' => 1.5 ] ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.starting_cycle', $integer ] ] ],
			'fractional starting string'   => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'starting_cycle' => '1.5' ] ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.starting_cycle', $integer ] ] ],
			'non-numeric starting string'  => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'starting_cycle' => 'soon' ] ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.starting_cycle', $integer ] ] ],
			'fractional duration float'    => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'duration_cycles' => 1.5 ] ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.duration_cycles', $integer ] ] ],
			'fractional duration string'   => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'duration_cycles' => '1.5' ] ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.duration_cycles', $integer ] ] ],
			'non-numeric duration string'  => [ [ 'policies' => [ self::policy( 'percentage', 10, [ 'duration_cycles' => 'forever' ] ) ] ], [ [ 'invalid_type', 'pricing_policy.policies.0.duration_cycles', $integer ] ] ],
			'bad value and both gates'     => [
				[
					'policies' => [
						self::policy(
							'percentage',
							150,
							[
								'starting_cycle'  => 0,
								'duration_cycles' => 'forever',
							]
						),
					],
				],
				[
					[ 'out_of_bounds', 'pricing_policy.policies.0.value', [ 'max' => 100 ] ],
					[ 'out_of_bounds', 'pricing_policy.policies.0.starting_cycle', [ 'min' => 1 ] ],
					[ 'invalid_type', 'pricing_policy.policies.0.duration_cycles', $integer ],
				],
			],
			'second entry reports index 1' => [ [ 'policies' => [ self::policy( 'bogo', 0 ), self::policy( 'percentage', 101 ) ] ], [ [ 'out_of_bounds', 'pricing_policy.policies.1.value', [ 'max' => 100 ] ] ] ],
			'two bad policy values'        => [
				[ 'policies' => [ self::policy( 'percentage', 150 ), self::policy( 'percentage', 120 ) ] ],
				[
					[ 'out_of_bounds', 'pricing_policy.policies.0.value', [ 'max' => 100 ] ],
					[ 'out_of_bounds', 'pricing_policy.policies.1.value', [ 'max' => 100 ] ],
				],
			],
			'non-object fee'               => [ [ 'one_time_fees' => [ 5 ] ], [ [ 'invalid_type', 'pricing_policy.one_time_fees.0', $object ] ] ],
			'non-numeric fee amount'       => [ [ 'one_time_fees' => [ [ 'amount' => 'five' ] ] ], [ [ 'invalid_type', 'pricing_policy.one_time_fees.0.amount', $number ] ] ],
			'negative fee amount'          => [ [ 'one_time_fees' => [ [ 'amount' => -5 ] ] ], [ [ 'out_of_bounds', 'pricing_policy.one_time_fees.0.amount', [ 'min' => 0 ] ] ] ],
			'infinite fee amount'          => [ [ 'one_time_fees' => [ [ 'amount' => INF ] ] ], [ [ 'invalid_value', 'pricing_policy.one_time_fees.0.amount', [] ] ] ],
			'every bad fee field'          => [
				[
					'one_time_fees' => [
						[
							'amount'    => -5,
							'taxable'   => 'maybe',
							'tax_class' => [ 'reduced' ],
						],
					],
				],
				[
					[ 'out_of_bounds', 'pricing_policy.one_time_fees.0.amount', [ 'min' => 0 ] ],
					[ 'invalid_type', 'pricing_policy.one_time_fees.0.taxable', [ 'type' => 'boolean' ] ],
					[ 'invalid_type', 'pricing_policy.one_time_fees.0.tax_class', [ 'type' => 'string' ] ],
				],
			],
			'policy and fee errors'        => [
				[
					'policies'      => [ self::policy( 'percentage', 150 ) ],
					'one_time_fees' => [ [ 'amount' => -5 ] ],
				],
				[
					[ 'out_of_bounds', 'pricing_policy.policies.0.value', [ 'max' => 100 ] ],
					[ 'out_of_bounds', 'pricing_policy.one_time_fees.0.amount', [ 'min' => 0 ] ],
				],
			],
		];
	}

	/**
	 * Every message is non-empty and names no field: the field rides in the error data.
	 *
	 * @dataProvider provide_single_error_payloads
	 *
	 * @param mixed $payload Invalid payload with one error.
	 */
	public function test_validate_reports_a_field_free_message( $payload ): void {
		$errors   = PricingTerms::validate( $payload );
		$messages = $errors->get_error_messages();

		$this->assertCount( 1, $messages );
		$this->assertNotSame( '', $messages[0] );
		$this->assertStringNotContainsString( 'pricing_policy', $messages[0] );
		$this->assertStringNotContainsString( $errors->get_error_data()['field'], $messages[0] );
	}

	/**
	 * One row per message family.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_single_error_payloads(): array {
		return [
			'non-object payload'  => [ 'bogo' ],
			'not a list'          => [ [ 'policies' => 'none' ] ],
			'non-object entry'    => [ [ 'policies' => [ 'bogo' ] ] ],
			'unknown type'        => [ [ 'policies' => [ self::policy( 'mystery', 1 ) ] ] ],
			'non-numeric value'   => [ [ 'policies' => [ self::policy( 'percentage', 'ten' ) ] ] ],
			'infinite amount'     => [ [ 'one_time_fees' => [ [ 'amount' => INF ] ] ] ],
			'negative amount'     => [ [ 'one_time_fees' => [ [ 'amount' => -5 ] ] ] ],
			'percentage over 100' => [ [ 'policies' => [ self::policy( 'percentage', 150 ) ] ] ],
			'bogo with a value'   => [ [ 'policies' => [ self::policy( 'bogo', 5 ) ] ] ],
			'fractional gate'     => [ [ 'policies' => [ self::policy( 'price', 5, [ 'starting_cycle' => 1.5 ] ) ] ] ],
			'zero gate'           => [ [ 'policies' => [ self::policy( 'price', 5, [ 'starting_cycle' => 0 ] ) ] ] ],
			'non-bool taxable'    => [
				[
					'one_time_fees' => [
						[
							'amount'  => 5,
							'taxable' => 'maybe',
						],
					],
				],
			],
			'non-string class'    => [
				[
					'one_time_fees' => [
						[
							'amount'    => 5,
							'tax_class' => 1,
						],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider provide_message_wording
	 *
	 * @param mixed  $payload Invalid payload with one error.
	 * @param string $message Expected message.
	 */
	public function test_validate_message_wording( $payload, string $message ): void {
		$this->assertSame( [ $message ], PricingTerms::validate( $payload )->get_error_messages() );
	}

	/**
	 * A plain message and the type list joined by `wp_sprintf( '%l' )`.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function provide_message_wording(): array {
		return [
			'plain message' => [ [ 'policies' => 'none' ], 'Must be a list.' ],
			'type list'     => [ [ 'policies' => [ self::policy( 'mystery', 1 ) ] ], 'Must be one of percentage, fixed_amount, price, and bogo.' ],
		];
	}

	public function test_validate_translates_its_messages(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'woocommerce-subscriptions-lite' === $domain && 'Must be a list.' === $text ? 'Translated list message' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$errors = PricingTerms::validate( [ 'policies' => 'none' ] );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( 'Translated list message', $errors->get_error_message() );
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
