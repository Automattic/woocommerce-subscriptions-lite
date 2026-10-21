<?php
/**
 * Integration tests for Lite's billing terms reader.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ValidationErrorCode;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms
 */
final class BillingTermsTest extends LiteIntegrationTestCase {

	use ListsValidationErrors;

	public function test_reads_the_plan_billing_policy(): void {
		$terms = BillingTerms::from_plan(
			$this->make_plan(
				'week',
				2,
				6,
				[
					'billing_policy' => [
						'period'         => 'week',
						'interval'       => 2,
						'max_cycles'     => 6,
						'trial_duration' => [
							'length' => 14,
							'unit'   => 'day',
						],
					],
				]
			)
		);

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertSame( 'week', $terms->get_period() );
		$this->assertSame( 2, $terms->get_interval() );
		$this->assertSame( 6, $terms->get_max_cycles() );
		$this->assertSame(
			[
				'length' => 14,
				'unit'   => 'day',
			],
			$terms->get_trial_duration()
		);
	}

	public function test_open_ended_terms_without_a_trial(): void {
		$terms = BillingTerms::from_plan( $this->make_plan( 'month', 1 ) );

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertSame( 1, $terms->get_interval() );
		$this->assertNull( $terms->get_max_cycles() );
		$this->assertNull( $terms->get_trial_duration() );
	}

	public function test_no_plan_reads_as_no_terms(): void {
		$this->assertNull( BillingTerms::from_plan( null ) );
	}

	/**
	 * @dataProvider provide_unusable_plan_billing
	 *
	 * @param array<string, mixed>|null $billing_policy Plan billing payload.
	 */
	public function test_a_plan_without_usable_billing_reads_as_no_terms( ?array $billing_policy ): void {
		$this->assertNull( BillingTerms::from_plan( $this->foreign_plan( $billing_policy ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public function provide_unusable_plan_billing(): array {
		return [
			'null payload'     => [ null ],
			'missing interval' => [ [ 'period' => 'month' ] ],
			'unknown period'   => [
				[
					'period'   => 'fortnight',
					'interval' => 1,
				],
			],
			'zero interval'    => [
				[
					'period'   => 'month',
					'interval' => 0,
				],
			],
		];
	}

	/**
	 * Plans are read strictly: a payload contract creation cannot parse is not billable.
	 *
	 * @dataProvider provide_unbillable_cycle_and_trial_payloads
	 *
	 * @param array<string, mixed> $billing_policy Plan billing payload.
	 */
	public function test_an_unbillable_cycle_limit_or_trial_reads_as_no_terms( array $billing_policy ): void {
		$this->assertNull( BillingTerms::from_plan( $this->foreign_plan( $billing_policy ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function provide_unbillable_cycle_and_trial_payloads(): array {
		return [
			'zero max cycles'   => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => 0,
				],
			],
			'zero trial length' => [
				[
					'period'         => 'month',
					'interval'       => 1,
					'trial_duration' => [
						'length' => 0,
						'unit'   => 'day',
					],
				],
			],
		];
	}

	/**
	 * A string cycle limit reads as an integer by `FILTER_VALIDATE_INT`.
	 *
	 * @dataProvider provide_string_cycle_limits
	 *
	 * @param string   $max_cycles Stored cycle limit.
	 * @param int|null $expected   Expected limit; null when the plan is not billable.
	 */
	public function test_a_string_cycle_limit_reads_as_an_integer( string $max_cycles, ?int $expected ): void {
		$terms = BillingTerms::from_plan(
			$this->foreign_plan(
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => $max_cycles,
				]
			)
		);

		if ( null === $expected ) {
			$this->assertNull( $terms );
			return;
		}

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertSame( $expected, $terms->get_max_cycles() );
	}

	/**
	 * @return array<string, array{0: string, 1: int|null}>
	 */
	public function provide_string_cycle_limits(): array {
		return [
			'digits'       => [ '6', 6 ],
			'plus sign'    => [ '+6', 6 ],
			'leading zero' => [ '06', null ],
			'decimal'      => [ '6.0', null ],
			'negative'     => [ '-6', null ],
		];
	}

	public function test_a_live_plan_is_read_strictly(): void {
		// A digit-string interval is not billable on a plan.
		$plan = $this->make_unvalidated_plan(
			'month',
			1,
			null,
			[
				'billing_policy' => [
					'period'   => 'month',
					'interval' => '1',
				],
			]
		);

		$this->assertNull( BillingTerms::from_plan( $plan ) );
	}

	public function test_a_billable_payload_has_no_errors(): void {
		$policy = [
			'period'         => 'week',
			'interval'       => 2,
			'max_cycles'     => 6,
			'trial_duration' => [
				'length' => 7,
				'unit'   => 'day',
			],
		];

		$this->assertFalse( BillingTerms::validate( $policy )->has_errors() );
		$this->assertTrue( BillingTerms::is_billable( $policy ) );
	}

	/**
	 * @dataProvider provide_billing_payloads_to_validate
	 *
	 * @param array<string, mixed>|null                                  $policy   Billing payload.
	 * @param list<array{0: string, 1: string, 2: array<string, mixed>}> $expected Every error as code, field and params.
	 */
	public function test_validate_reports_why_a_payload_is_not_billable( ?array $policy, array $expected ): void {
		$this->assertSame( $expected, $this->get_validation_errors( BillingTerms::validate( $policy ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: list<array{0: string, 1: string, 2: array<string, mixed>}>}>
	 */
	public function provide_billing_payloads_to_validate(): array {
		$units = [ 'allowed' => [ 'day', 'week', 'month', 'year' ] ];

		return [
			'null'                      => [ null, [ [ 'required', 'billing_policy', [] ] ] ],
			'no period'                 => [
				[ 'interval' => 1 ],
				[ [ 'required', 'billing_policy.period', [] ] ],
			],
			'unknown period'            => [
				[
					'period'   => 'fortnight',
					'interval' => 1,
				],
				[ [ 'not_in_enum', 'billing_policy.period', $units ] ],
			],
			'zero interval'             => [
				[
					'period'   => 'month',
					'interval' => 0,
				],
				[ [ 'out_of_bounds', 'billing_policy.interval', [ 'min' => 1 ] ] ],
			],
			'string interval'           => [
				[
					'period'   => 'month',
					'interval' => '2',
				],
				[ [ 'invalid_type', 'billing_policy.interval', [ 'type' => 'integer' ] ] ],
			],
			'zero max cycles'           => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => 0,
				],
				[ [ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ] ],
			],
			'zero interval and max'     => [
				[
					'period'     => 'month',
					'interval'   => 0,
					'max_cycles' => 0,
				],
				[
					[ 'out_of_bounds', 'billing_policy.interval', [ 'min' => 1 ] ],
					[ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ],
				],
			],
			'negative max cycles'       => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => -1,
				],
				[ [ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ] ],
			],
			'leading-zero max cycles'   => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => '06',
				],
				[ [ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ] ],
			],
			'min above max'             => [
				[
					'period'     => 'month',
					'interval'   => 1,
					'min_cycles' => 5,
					'max_cycles' => 2,
				],
				[ [ 'out_of_bounds', 'billing_policy.min_cycles', [ 'max' => 2 ] ] ],
			],
			'unknown trial unit'        => [
				[
					'period'         => 'month',
					'interval'       => 1,
					'trial_duration' => [
						'length' => 1,
						'unit'   => 'fortnight',
					],
				],
				[ [ 'not_in_enum', 'billing_policy.trial_duration.unit', $units ] ],
			],
			'zero trial length'         => [
				[
					'period'         => 'month',
					'interval'       => 1,
					'trial_duration' => [
						'length' => 0,
						'unit'   => 'day',
					],
				],
				[ [ 'out_of_bounds', 'billing_policy.trial_duration.length', [ 'min' => 1 ] ] ],
			],
			'every field at once'       => [
				[
					'period'         => 'fortnight',
					'interval'       => '2',
					'max_cycles'     => -1,
					'trial_duration' => [
						'length' => 0,
						'unit'   => 'fortnight',
					],
				],
				[
					[ 'not_in_enum', 'billing_policy.period', $units ],
					[ 'not_in_enum', 'billing_policy.trial_duration.unit', $units ],
					[ 'invalid_type', 'billing_policy.interval', [ 'type' => 'integer' ] ],
					[ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ],
					[ 'out_of_bounds', 'billing_policy.trial_duration.length', [ 'min' => 1 ] ],
				],
			],
			'missing interval'          => [
				[ 'period' => 'month' ],
				[ [ 'required', 'billing_policy.interval', [] ] ],
			],
			'non-string period'         => [
				[
					'period'   => 5,
					'interval' => 1,
				],
				[ [ 'invalid_type', 'billing_policy.period', [ 'type' => 'string' ] ] ],
			],
			'null period'               => [
				[
					'period'   => null,
					'interval' => 1,
				],
				[ [ 'invalid_type', 'billing_policy.period', [ 'type' => 'string' ] ] ],
			],
			'word min cycles'           => [
				self::month_with( [ 'min_cycles' => 'abc' ] ),
				[ [ 'invalid_type', 'billing_policy.min_cycles', [ 'type' => 'integer' ] ] ],
			],
			'decimal min cycles'        => [
				self::month_with( [ 'min_cycles' => 1.5 ] ),
				[ [ 'invalid_type', 'billing_policy.min_cycles', [ 'type' => 'integer' ] ] ],
			],
			'boolean min cycles'        => [
				self::month_with( [ 'min_cycles' => true ] ),
				[ [ 'invalid_type', 'billing_policy.min_cycles', [ 'type' => 'integer' ] ] ],
			],
			'leading-zero min cycles'   => [
				self::month_with( [ 'min_cycles' => '007' ] ),
				[ [ 'invalid_type', 'billing_policy.min_cycles', [ 'type' => 'integer' ] ] ],
			],
			'negative min cycles'       => [
				self::month_with( [ 'min_cycles' => -1 ] ),
				[ [ 'out_of_bounds', 'billing_policy.min_cycles', [ 'min' => 0 ] ] ],
			],
			'string min above max'      => [
				self::month_with(
					[
						'min_cycles' => '5',
						'max_cycles' => 2,
					]
				),
				[ [ 'out_of_bounds', 'billing_policy.min_cycles', [ 'max' => 2 ] ] ],
			],
			'min above a zero max'      => [
				self::month_with(
					[
						'min_cycles' => 5,
						'max_cycles' => 0,
					]
				),
				[ [ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ] ],
			],
			'zero max and trial length' => [
				self::month_with(
					[
						'max_cycles'     => 0,
						'trial_duration' => [
							'length' => 0,
							'unit'   => 'day',
						],
					]
				),
				[
					[ 'out_of_bounds', 'billing_policy.max_cycles', [ 'min' => 1 ] ],
					[ 'out_of_bounds', 'billing_policy.trial_duration.length', [ 'min' => 1 ] ],
				],
			],
			'string trial'              => [
				self::month_with( [ 'trial_duration' => 'x' ] ),
				[ [ 'invalid_type', 'billing_policy.trial_duration', [ 'type' => 'object' ] ] ],
			],
			'trial without a length'    => [
				self::month_with( [ 'trial_duration' => [ 'unit' => 'day' ] ] ),
				[ [ 'required', 'billing_policy.trial_duration.length', [] ] ],
			],
			'trial without a unit'      => [
				self::month_with( [ 'trial_duration' => [ 'length' => 7 ] ] ),
				[ [ 'required', 'billing_policy.trial_duration.unit', [] ] ],
			],
			'string trial length'       => [
				self::month_with(
					[
						'trial_duration' => [
							'length' => '7',
							'unit'   => 'day',
						],
					]
				),
				[ [ 'invalid_type', 'billing_policy.trial_duration.length', [ 'type' => 'integer' ] ] ],
			],
			'non-string trial unit'     => [
				self::month_with(
					[
						'trial_duration' => [
							'length' => 7,
							'unit'   => 5,
						],
					]
				),
				[ [ 'invalid_type', 'billing_policy.trial_duration.unit', [ 'type' => 'string' ] ] ],
			],
		];
	}

	/**
	 * A monthly payload with extra keys.
	 *
	 * @param array<string, mixed> $extra Keys to add.
	 * @return array<string, mixed>
	 */
	private static function month_with( array $extra ): array {
		return array_merge(
			[
				'period'   => 'month',
				'interval' => 1,
			],
			$extra
		);
	}

	/**
	 * `is_billable()` and `validate()` apply one rule.
	 *
	 * @dataProvider provide_billability_payloads
	 *
	 * @param array<string, mixed>|null $policy   Billing payload.
	 * @param bool                      $billable Whether the payload is billable.
	 */
	public function test_is_billable_agrees_with_validate( ?array $policy, bool $billable ): void {
		$this->assertSame( $billable, BillingTerms::is_billable( $policy ) );
		$this->assertSame( ! $billable, BillingTerms::validate( $policy )->has_errors() );
	}

	/**
	 * Every unbillable payload from the validation rows, plus billable ones.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: bool}>
	 */
	public function provide_billability_payloads(): array {
		$rows = [];
		foreach ( $this->provide_billing_payloads_to_validate() as $name => $row ) {
			$rows[ $name ] = [ $row[0], false ];
		}

		return array_merge(
			$rows,
			[
				'billable, no limit'                => [
					[
						'period'   => 'month',
						'interval' => 1,
					],
					true,
				],
				'billable, six cycles'              => [
					[
						'period'     => 'week',
						'interval'   => 2,
						'max_cycles' => 6,
					],
					true,
				],
				'billable, no minimum cycles'       => [
					[
						'period'     => 'month',
						'interval'   => 1,
						'min_cycles' => 0,
					],
					true,
				],
				'billable, min equals max cycles'   => [
					[
						'period'     => 'month',
						'interval'   => 1,
						'min_cycles' => 3,
						'max_cycles' => 3,
					],
					true,
				],
				'billable, min cycles digit string' => [
					[
						'period'     => 'month',
						'interval'   => 1,
						'min_cycles' => '3',
						'max_cycles' => 6,
					],
					true,
				],
				'billable, min cycles and no limit' => [
					[
						'period'     => 'month',
						'interval'   => 1,
						'min_cycles' => 2,
					],
					true,
				],
				'billable, with trial'              => [
					[
						'period'         => 'month',
						'interval'       => 1,
						'trial_duration' => [
							'length' => 14,
							'unit'   => 'day',
						],
					],
					true,
				],
			]
		);
	}

	public function test_validate_reports_the_cross_field_bound(): void {
		$errors = BillingTerms::validate(
			[
				'period'     => 'month',
				'interval'   => 1,
				'min_cycles' => 5,
				'max_cycles' => 2,
			]
		);

		$this->assertSame(
			[
				'field'  => 'billing_policy.min_cycles',
				'params' => [ 'max' => 2 ],
			],
			$errors->get_error_data( 'out_of_bounds' )
		);
	}

	public function test_validate_reports_a_null_payload_as_required(): void {
		$this->assertSame( 'This field is required.', BillingTerms::validate( null )->get_error_message( 'required' ) );

		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'woocommerce-subscriptions-lite' === $domain && 'This field is required.' === $text ? 'Translated required' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$message = BillingTerms::validate( null )->get_error_message( 'required' );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( 'Translated required', $message );
	}

	public function test_validate_lists_the_allowed_periods(): void {
		$errors = BillingTerms::validate(
			[
				'period'   => 'fortnight',
				'interval' => 1,
			]
		);

		$this->assertSame( 'Must be one of day, week, month, and year.', $errors->get_error_message( 'not_in_enum' ) );
	}

	public function test_validate_names_the_cycle_bound_it_exceeds(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'woocommerce-subscriptions-lite' === $domain && 'Must not exceed %d.' === $text ? 'No more than %d.' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$errors = BillingTerms::validate(
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

		$this->assertSame( 'No more than 2.', $errors->get_error_message( 'out_of_bounds' ) );
	}

	public function test_validate_translates_its_own_messages(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'woocommerce-subscriptions-lite' === $domain && 'Must be a positive integer, or empty for no limit.' === $text ? 'Translated cycle limit' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$errors = BillingTerms::validate(
				[
					'period'     => 'month',
					'interval'   => 1,
					'max_cycles' => 0,
				]
			);
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertSame( [ 'Translated cycle limit' ], $errors->get_error_messages( 'out_of_bounds' ) );
	}

	public function test_get_first_renewal_from_applies_the_trial(): void {
		$plan = $this->make_plan(
			'month',
			1,
			null,
			[
				'billing_policy' => [
					'period'         => 'month',
					'interval'       => 1,
					'trial_duration' => [
						'length' => 14,
						'unit'   => 'day',
					],
				],
			]
		);

		$terms = BillingTerms::from_plan( $plan );
		$this->assertInstanceOf( BillingTerms::class, $terms );

		$next = $terms->get_first_renewal_from( new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '2026-01-15 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * First renewal dates for every cadence and trial unit, pinned: month and year overflow
	 * (31 Jan + 1 month = 3 Mar), a trial wins over the cadence, and results are in UTC.
	 *
	 * @dataProvider provide_first_renewals
	 *
	 * @param array<string, mixed> $billing_policy Plan billing payload.
	 * @param string               $start          Contract start.
	 * @param string               $expected       Expected first renewal (UTC).
	 */
	public function test_the_first_renewal_matches_the_pinned_dates( array $billing_policy, string $start, string $expected ): void {
		$this->assertSame( $expected, $this->first_renewal( $billing_policy, $start ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public function provide_first_renewals(): array {
		$start   = '2026-01-31 10:00:00';
		$cadence = static function ( string $period, int $interval, ?array $trial = null ): array {
			return [
				'period'         => $period,
				'interval'       => $interval,
				'trial_duration' => $trial,
			];
		};
		$trial   = static function ( int $length, string $unit ) use ( $cadence ): array {
			return $cadence(
				'month',
				1,
				[
					'length' => $length,
					'unit'   => $unit,
				]
			);
		};

		return [
			'every day'                  => [ $cadence( 'day', 1 ), $start, '2026-02-01 10:00:00' ],
			'every week'                 => [ $cadence( 'week', 1 ), $start, '2026-02-07 10:00:00' ],
			'every two weeks'            => [ $cadence( 'week', 2 ), $start, '2026-02-14 10:00:00' ],
			'every month overflows'      => [ $cadence( 'month', 1 ), $start, '2026-03-03 10:00:00' ],
			'every three months'         => [ $cadence( 'month', 3 ), $start, '2026-05-01 10:00:00' ],
			'every year'                 => [ $cadence( 'year', 1 ), $start, '2027-01-31 10:00:00' ],
			'every year from a leap day' => [ $cadence( 'year', 1 ), '2024-02-29 00:00:00', '2025-03-01 00:00:00' ],
			'14 day trial'               => [ $trial( 14, 'day' ), $start, '2026-02-14 10:00:00' ],
			'2 week trial'               => [ $trial( 2, 'week' ), $start, '2026-02-14 10:00:00' ],
			'1 month trial'              => [ $trial( 1, 'month' ), $start, '2026-03-03 10:00:00' ],
			'1 year trial'               => [ $trial( 1, 'year' ), $start, '2027-01-31 10:00:00' ],
			'non-UTC start'              => [ $cadence( 'month', 1 ), '2026-01-31T23:30:00+02:00', '2026-03-03 21:30:00' ],
			'non-UTC start with trial'   => [ $trial( 1, 'month' ), '2026-01-31T23:30:00+02:00', '2026-03-03 21:30:00' ],
		];
	}

	/**
	 * The first renewal of a contract starting at `$start` on a plan with `$billing_policy`.
	 *
	 * @param array<string, mixed> $billing_policy Plan billing payload.
	 * @param string               $start          Contract start (UTC unless it carries an offset).
	 */
	private function first_renewal( array $billing_policy, string $start ): string {
		$terms = BillingTerms::from_plan( $this->make_plan( 'month', 1, null, [ 'billing_policy' => $billing_policy ] ) );
		$this->assertInstanceOf( BillingTerms::class, $terms );

		return $terms->get_first_renewal_from( new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A plan with the given billing payload, built on a foreign owner's plan: Lite refuses
	 * such payloads on its own plans.
	 *
	 * @param array<string, mixed>|null $billing_policy Plan billing payload.
	 */
	private function foreign_plan( ?array $billing_policy ): PlanView {
		return $this->make_plan(
			'month',
			1,
			null,
			[
				'extension_slug' => 'another-extension',
				'billing_policy' => $billing_policy,
			]
		);
	}

	/**
	 * A UTC moment.
	 *
	 * @param string $datetime GMT datetime string.
	 */
	private function utc( string $datetime ): DateTimeImmutable {
		return new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Billing terms read from a plan with the given cadence.
	 *
	 * @param string $period   Period.
	 * @param int    $interval Interval.
	 */
	private function terms( string $period = 'month', int $interval = 1 ): BillingTerms {
		$terms = BillingTerms::from_plan( $this->make_plan( $period, $interval ) );
		$this->assertInstanceOf( BillingTerms::class, $terms );

		return $terms;
	}

	/**
	 * Format a period as GMT strings.
	 *
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $period Period.
	 * @return array<int, string>
	 */
	private function format( array $period ): array {
		return [ $period[0]->format( 'Y-m-d H:i:s' ), $period[1]->format( 'Y-m-d H:i:s' ) ];
	}

	public function test_a_future_anchor_starts_the_period(): void {
		$period = $this->terms()->get_period_to_bill( $this->utc( '2026-05-01 00:00:00' ), $this->utc( '2026-04-15 00:00:00' ) );

		$this->assertSame( [ '2026-05-01 00:00:00', '2026-06-01 00:00:00' ], $this->format( $period ) );
	}

	public function test_an_anchor_due_now_bills_the_period_starting_at_it(): void {
		$period = $this->terms()->get_period_to_bill( $this->utc( '2026-05-01 00:00:00' ), $this->utc( '2026-05-01 00:00:00' ) );

		$this->assertSame( [ '2026-05-01 00:00:00', '2026-06-01 00:00:00' ], $this->format( $period ) );
	}

	public function test_an_anchor_several_periods_behind_bills_the_one_period_containing_now(): void {
		$now    = $this->utc( '2026-04-15 12:00:00' );
		$period = $this->terms()->get_period_to_bill( $this->utc( '2026-01-01 00:00:00' ), $now );

		$this->assertSame( [ '2026-04-01 00:00:00', '2026-05-01 00:00:00' ], $this->format( $period ) );
		$this->assertLessThanOrEqual( $now, $period[0] );
		$this->assertGreaterThan( $now, $period[1] );
	}

	public function test_a_boundary_equal_to_now_rolls_into_the_next_period(): void {
		$period = $this->terms()->get_period_to_bill( $this->utc( '2026-03-01 00:00:00' ), $this->utc( '2026-04-01 00:00:00' ) );

		$this->assertSame( [ '2026-04-01 00:00:00', '2026-05-01 00:00:00' ], $this->format( $period ) );
	}

	public function test_get_period_to_bill_floors_at_now_and_logs_when_the_roll_cap_runs_out(): void {
		$this->capture_log();

		$period = $this->terms( 'day' )->get_period_to_bill( $this->utc( '2020-01-01 00:00:00' ), $this->utc( '2026-07-06 00:00:00' ) );

		$this->assertSame( [ '2026-07-06 00:00:00', '2026-07-07 00:00:00' ], $this->format( $period ) );
		$this->assertTrue( $this->has_logged( 'exhausted the roll cap' ) );
	}

	public function test_get_next_due_after_keeps_a_future_moment(): void {
		$next = $this->terms()->get_next_due_after( $this->utc( '2026-06-01 00:00:00' ), $this->utc( '2026-05-15 00:00:00' ) );

		$this->assertSame( '2026-06-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_rolls_a_past_moment_past_now(): void {
		$next = $this->terms()->get_next_due_after( $this->utc( '2026-02-01 00:00:00' ), $this->utc( '2026-04-15 09:30:00' ) );

		$this->assertSame( '2026-05-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_rolls_a_moment_equal_to_now(): void {
		$next = $this->terms( 'week', 2 )->get_next_due_after( $this->utc( '2026-04-01 00:00:00' ), $this->utc( '2026-04-01 00:00:00' ) );

		$this->assertSame( '2026-04-15 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_floors_one_period_after_now_when_the_roll_cap_runs_out(): void {
		$this->capture_log();

		$next = $this->terms( 'day' )->get_next_due_after( $this->utc( '2020-01-01 00:00:00' ), $this->utc( '2026-07-06 00:00:00' ) );

		$this->assertSame( '2026-07-07 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $this->has_logged( 'exhausted the roll cap' ) );
	}

	public function test_a_month_end_anchor_rolls_with_overflow(): void {
		$period = $this->terms()->get_period_to_bill( $this->utc( '2026-01-31 00:00:00' ), $this->utc( '2026-03-10 00:00:00' ) );

		$this->assertSame( [ '2026-03-03 00:00:00', '2026-04-03 00:00:00' ], $this->format( $period ) );
	}

	public function test_get_next_due_after_overflows_a_month_end(): void {
		$next = $this->terms()->get_next_due_after( $this->utc( '2026-01-31 00:00:00' ), $this->utc( '2026-02-15 00:00:00' ) );

		$this->assertSame( '2026-03-03 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_a_leap_day_anchor_rolls_by_year(): void {
		$period = $this->terms( 'year' )->get_period_to_bill( $this->utc( '2024-02-29 00:00:00' ), $this->utc( '2025-06-01 00:00:00' ) );
		$next   = $this->terms( 'year' )->get_next_due_after( $this->utc( '2024-02-29 00:00:00' ), $this->utc( '2024-03-01 00:00:00' ) );

		$this->assertSame( [ '2025-03-01 00:00:00', '2026-03-01 00:00:00' ], $this->format( $period ) );
		$this->assertSame( '2025-03-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	public function test_a_non_utc_anchor_is_billed_in_utc(): void {
		$period = $this->terms()->get_period_to_bill( new DateTimeImmutable( '2026-01-31T23:30:00+02:00' ), $this->utc( '2026-02-01 00:00:00' ) );

		$this->assertSame( [ '2026-01-31 21:30:00', '2026-03-03 21:30:00' ], $this->format( $period ) );
	}
}
