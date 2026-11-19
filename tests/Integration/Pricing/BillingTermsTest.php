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
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingDate;
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
			'interval above the cap'    => [
				[
					'period'   => 'year',
					'interval' => 1001,
				],
				[ [ 'out_of_bounds', 'billing_policy.interval', [ 'max' => 1000 ] ] ],
			],
			'trial above the cap'       => [
				self::month_with(
					[
						'trial_duration' => [
							'length' => 1001,
							'unit'   => 'day',
						],
					]
				),
				[ [ 'out_of_bounds', 'billing_policy.trial_duration.length', [ 'max' => 1000 ] ] ],
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
				'billable, interval at the cap'     => [
					[
						'period'   => 'year',
						'interval' => 1000,
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
	 * First renewal dates for every cadence and trial unit, pinned: month and year steps clamp
	 * to the end of a shorter month (31 Jan + 1 month = 28 Feb), a trial wins over the cadence,
	 * and results are in UTC.
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
			'every month clamps'         => [ $cadence( 'month', 1 ), $start, '2026-02-28 10:00:00' ],
			'every three months'         => [ $cadence( 'month', 3 ), $start, '2026-04-30 10:00:00' ],
			'every year'                 => [ $cadence( 'year', 1 ), $start, '2027-01-31 10:00:00' ],
			'every year from a leap day' => [ $cadence( 'year', 1 ), '2024-02-29 00:00:00', '2025-02-28 00:00:00' ],
			'14 day trial'               => [ $trial( 14, 'day' ), $start, '2026-02-14 10:00:00' ],
			'2 week trial'               => [ $trial( 2, 'week' ), $start, '2026-02-14 10:00:00' ],
			'1 month trial'              => [ $trial( 1, 'month' ), $start, '2026-02-28 10:00:00' ],
			'1 year trial'               => [ $trial( 1, 'year' ), $start, '2027-01-31 10:00:00' ],
			'non-UTC start'              => [ $cadence( 'month', 1 ), '2026-01-31T23:30:00+02:00', '2026-02-28 21:30:00' ],
			'non-UTC start with trial'   => [ $trial( 1, 'month' ), '2026-01-31T23:30:00+02:00', '2026-02-28 21:30:00' ],
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
	 * @param array{0: DateTimeImmutable, 1: BillingDate} $period Period.
	 * @return array<int, string>
	 */
	private function format( array $period ): array {
		return [ $period[0]->format( 'Y-m-d H:i:s' ), $period[1]->get_moment()->format( 'Y-m-d H:i:s' ) ];
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
		$this->assertGreaterThan( $now, $period[1]->get_moment() );
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

		$this->assertSame( '2026-06-01 00:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_rolls_a_past_moment_past_now(): void {
		$next = $this->terms()->get_next_due_after( $this->utc( '2026-02-01 00:00:00' ), $this->utc( '2026-04-15 09:30:00' ) );

		$this->assertSame( '2026-05-01 00:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_rolls_a_moment_equal_to_now(): void {
		$next = $this->terms( 'week', 2 )->get_next_due_after( $this->utc( '2026-04-01 00:00:00' ), $this->utc( '2026-04-01 00:00:00' ) );

		$this->assertSame( '2026-04-15 00:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_get_next_due_after_floors_one_period_after_now_when_the_roll_cap_runs_out(): void {
		$this->capture_log();

		$next = $this->terms( 'day' )->get_next_due_after( $this->utc( '2020-01-01 00:00:00' ), $this->utc( '2026-07-06 00:00:00' ) );

		$this->assertSame( '2026-07-07 00:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $this->has_logged( 'exhausted the roll cap' ) );
	}

	public function test_a_month_end_anchor_rolls_on_its_day_clamped_to_the_month_end(): void {
		$period = $this->terms()->get_period_to_bill( $this->utc( '2026-01-31 00:00:00' ), $this->utc( '2026-03-10 00:00:00' ) );

		$this->assertSame( [ '2026-02-28 00:00:00', '2026-03-31 00:00:00' ], $this->format( $period ) );
	}

	public function test_get_next_due_after_clamps_a_month_end(): void {
		$next = $this->terms()->get_next_due_after( $this->utc( '2026-01-31 00:00:00' ), $this->utc( '2026-02-15 00:00:00' ) );

		$this->assertSame( '2026-02-28 00:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_a_leap_day_anchor_rolls_by_year_to_the_end_of_february(): void {
		$period = $this->terms( 'year' )->get_period_to_bill( $this->utc( '2024-02-29 00:00:00' ), $this->utc( '2025-06-01 00:00:00' ) );
		$next   = $this->terms( 'year' )->get_next_due_after( $this->utc( '2024-02-29 00:00:00' ), $this->utc( '2024-03-01 00:00:00' ) );

		$this->assertSame( [ '2025-02-28 00:00:00', '2026-02-28 00:00:00' ], $this->format( $period ) );
		$this->assertSame( '2025-02-28 00:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	public function test_a_non_utc_anchor_is_billed_in_the_utc_store_calendar(): void {
		$period = $this->terms()->get_period_to_bill( new DateTimeImmutable( '2026-01-31T23:30:00+02:00' ), $this->utc( '2026-02-01 00:00:00' ) );

		$this->assertSame( [ '2026-01-31 21:30:00', '2026-02-28 21:30:00' ], $this->format( $period ) );
	}

	/**
	 * Monthly billing terms on a billing day, or on none.
	 *
	 * @param string   $period      Period.
	 * @param int      $interval    Interval.
	 * @param int|null $billing_day Billing day, or null for none.
	 */
	private function terms_on_day( string $period, int $interval, ?int $billing_day ): BillingTerms {
		$terms = $this->terms( $period, $interval );

		return null === $billing_day ? $terms : $terms->with_billing_day( $billing_day );
	}

	/**
	 * The renewal dates of `$steps` on-time renewals from the due moment `$due`, each one billed
	 * when it falls due (as the renewal flow does: the next due moment is the period's end).
	 *
	 * @param BillingTerms $terms Terms.
	 * @param string       $due   First due moment (GMT).
	 * @param int          $steps Number of renewals.
	 * @return array<int, string>
	 */
	private function renewal_chain( BillingTerms $terms, string $due, int $steps = 4 ): array {
		$chain  = [];
		$moment = $this->utc( $due );
		for ( $i = 0; $i < $steps; $i++ ) {
			$period  = $terms->get_period_to_bill( $moment, $moment );
			$moment  = $period[1]->get_moment();
			$chain[] = $moment->format( 'Y-m-d H:i:s' );
		}

		return $chain;
	}

	/**
	 * Monthly chains land on the billing day, clamped to a shorter month's last day, and return
	 * to it in months that have it.
	 *
	 * @dataProvider provide_monthly_chains
	 *
	 * @param string             $due         First due moment.
	 * @param int|null           $billing_day Billing day, or null for none stored.
	 * @param array<int, string> $expected    The next four renewals.
	 */
	public function test_a_monthly_chain_keeps_the_billing_day( string $due, ?int $billing_day, array $expected ): void {
		$this->assertSame( $expected, $this->renewal_chain( $this->terms_on_day( 'month', 1, $billing_day ), $due ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int|null, 2: array<int, string>}>
	 */
	public function provide_monthly_chains(): array {
		return [
			'28th, 2026'                => [ '2026-01-28 10:00:00', 28, [ '2026-02-28 10:00:00', '2026-03-28 10:00:00', '2026-04-28 10:00:00', '2026-05-28 10:00:00' ] ],
			'29th, 2026'                => [ '2026-01-29 10:00:00', 29, [ '2026-02-28 10:00:00', '2026-03-29 10:00:00', '2026-04-29 10:00:00', '2026-05-29 10:00:00' ] ],
			'30th, 2026'                => [ '2026-01-30 10:00:00', 30, [ '2026-02-28 10:00:00', '2026-03-30 10:00:00', '2026-04-30 10:00:00', '2026-05-30 10:00:00' ] ],
			'31st, 2026'                => [ '2026-01-31 10:00:00', 31, [ '2026-02-28 10:00:00', '2026-03-31 10:00:00', '2026-04-30 10:00:00', '2026-05-31 10:00:00' ] ],
			'28th, leap 2028'           => [ '2028-01-28 10:00:00', 28, [ '2028-02-28 10:00:00', '2028-03-28 10:00:00', '2028-04-28 10:00:00', '2028-05-28 10:00:00' ] ],
			'29th, leap 2028'           => [ '2028-01-29 10:00:00', 29, [ '2028-02-29 10:00:00', '2028-03-29 10:00:00', '2028-04-29 10:00:00', '2028-05-29 10:00:00' ] ],
			'30th, leap 2028'           => [ '2028-01-30 10:00:00', 30, [ '2028-02-29 10:00:00', '2028-03-30 10:00:00', '2028-04-30 10:00:00', '2028-05-30 10:00:00' ] ],
			'31st, leap 2028'           => [ '2028-01-31 10:00:00', 31, [ '2028-02-29 10:00:00', '2028-03-31 10:00:00', '2028-04-30 10:00:00', '2028-05-31 10:00:00' ] ],
			'15th control'              => [ '2026-01-15 10:00:00', 15, [ '2026-02-15 10:00:00', '2026-03-15 10:00:00', '2026-04-15 10:00:00', '2026-05-15 10:00:00' ] ],
			'30 Apr is not a month end' => [ '2026-04-30 10:00:00', 30, [ '2026-05-30 10:00:00', '2026-06-30 10:00:00', '2026-07-30 10:00:00', '2026-08-30 10:00:00' ] ],
			'31st, across the year end' => [ '2026-10-31 10:00:00', 31, [ '2026-11-30 10:00:00', '2026-12-31 10:00:00', '2027-01-31 10:00:00', '2027-02-28 10:00:00' ] ],
			'no billing day: own day'   => [ '2026-01-31 10:00:00', null, [ '2026-02-28 10:00:00', '2026-03-28 10:00:00', '2026-04-28 10:00:00', '2026-05-28 10:00:00' ] ],
			'28 Feb is on the 31st'     => [ '2026-02-28 10:00:00', 31, [ '2026-03-31 10:00:00', '2026-04-30 10:00:00', '2026-05-31 10:00:00', '2026-06-30 10:00:00' ] ],
		];
	}

	/**
	 * A cadence of several months clamps once per step.
	 *
	 * @dataProvider provide_multi_month_steps
	 *
	 * @param int                $interval    Months per cycle.
	 * @param string             $due         First due moment.
	 * @param int                $billing_day Billing day.
	 * @param array<int, string> $expected    The next renewals.
	 */
	public function test_a_multi_month_cadence_clamps_each_step( int $interval, string $due, int $billing_day, array $expected ): void {
		$this->assertSame( $expected, $this->renewal_chain( $this->terms_on_day( 'month', $interval, $billing_day ), $due, count( $expected ) ) );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: int, 3: array<int, string>}>
	 */
	public function provide_multi_month_steps(): array {
		return [
			'every 2 months from 31 Jan' => [ 2, '2026-01-31 10:00:00', 31, [ '2026-03-31 10:00:00', '2026-05-31 10:00:00', '2026-07-31 10:00:00' ] ],
			'every 3 months from 31 Jan' => [ 3, '2026-01-31 10:00:00', 31, [ '2026-04-30 10:00:00', '2026-07-31 10:00:00', '2026-10-31 10:00:00' ] ],
			'every 2 months from 30 Nov' => [ 2, '2026-11-30 10:00:00', 30, [ '2027-01-30 10:00:00', '2027-03-30 10:00:00', '2027-05-30 10:00:00' ] ],
			'every 3 months from 30 Nov' => [ 3, '2026-11-30 10:00:00', 30, [ '2027-02-28 10:00:00', '2027-05-30 10:00:00', '2027-08-30 10:00:00', '2027-11-30 10:00:00' ] ],
		];
	}

	/**
	 * A year is 12 months under the same clamp: a leap day renews on 28 Feb in other years.
	 *
	 * @dataProvider provide_yearly_chains
	 *
	 * @param int                $interval    Years per cycle.
	 * @param string             $due         First due moment.
	 * @param int                $billing_day Billing day.
	 * @param array<int, string> $expected    The next renewals.
	 */
	public function test_a_yearly_cadence_clamps_as_twelve_months( int $interval, string $due, int $billing_day, array $expected ): void {
		$this->assertSame( $expected, $this->renewal_chain( $this->terms_on_day( 'year', $interval, $billing_day ), $due, count( $expected ) ) );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: int, 3: array<int, string>}>
	 */
	public function provide_yearly_chains(): array {
		return [
			'29 Feb 2024, chained'          => [ 1, '2024-02-29 10:00:00', 29, [ '2025-02-28 10:00:00', '2026-02-28 10:00:00', '2027-02-28 10:00:00', '2028-02-29 10:00:00' ] ],
			'28 Feb 2025 stays on the 28th' => [ 1, '2025-02-28 10:00:00', 28, [ '2026-02-28 10:00:00', '2027-02-28 10:00:00', '2028-02-28 10:00:00', '2029-02-28 10:00:00' ] ],
			'29 Feb 2024 plus 4 years'      => [ 4, '2024-02-29 10:00:00', 29, [ '2028-02-29 10:00:00', '2032-02-29 10:00:00' ] ],
		];
	}

	/**
	 * A trial in months or years keeps the start's day; a trial in days or weeks makes its end
	 * the billing day. The renewal after the trial lands on that day.
	 *
	 * @dataProvider provide_trials
	 *
	 * @param string $period      Plan period.
	 * @param string $start       Contract start.
	 * @param int    $length      Trial length.
	 * @param string $unit        Trial unit.
	 * @param string $first       Expected first renewal.
	 * @param int    $billing_day Expected billing day.
	 * @param string $second      Expected renewal after the first.
	 */
	public function test_a_trial_sets_the_first_renewal_and_the_billing_day( string $period, string $start, int $length, string $unit, string $first, int $billing_day, string $second ): void {
		$terms = BillingTerms::from_plan(
			$this->make_plan(
				$period,
				1,
				null,
				[
					'billing_policy' => [
						'period'         => $period,
						'interval'       => 1,
						'trial_duration' => [
							'length' => $length,
							'unit'   => $unit,
						],
					],
				]
			)
		);
		$this->assertInstanceOf( BillingTerms::class, $terms );

		$first_renewal = $terms->get_first_renewal_from( $this->utc( $start ) );
		$day           = $terms->get_billing_day_from( $this->utc( $start ) );
		$period_billed = $terms->with_billing_day( $day )->get_period_to_bill( $first_renewal, $first_renewal );

		$this->assertSame( $first, $first_renewal->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( $billing_day, $day );
		$this->assertSame( $second, $period_billed[1]->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: int, 6: string}>
	 */
	public function provide_trials(): array {
		return [
			'1 month trial from 31 Jan'      => [ 'month', '2026-01-31 10:00:00', 1, 'month', '2026-02-28 10:00:00', 31, '2026-03-31 10:00:00' ],
			'1 month trial from 30 Jan'      => [ 'month', '2026-01-30 10:00:00', 1, 'month', '2026-02-28 10:00:00', 30, '2026-03-30 10:00:00' ],
			'1 month trial from 29 Jan'      => [ 'month', '2026-01-29 10:00:00', 1, 'month', '2026-02-28 10:00:00', 29, '2026-03-29 10:00:00' ],
			'1 month trial from 29 Jan 2028' => [ 'month', '2028-01-29 10:00:00', 1, 'month', '2028-02-29 10:00:00', 29, '2028-03-29 10:00:00' ],
			'1 year trial from 29 Feb'       => [ 'month', '2024-02-29 10:00:00', 1, 'year', '2025-02-28 10:00:00', 29, '2025-03-29 10:00:00' ],
			'1 year trial, yearly plan'      => [ 'year', '2024-02-29 10:00:00', 1, 'year', '2025-02-28 10:00:00', 29, '2026-02-28 10:00:00' ],
			'1 year trial from 31 Jan'       => [ 'month', '2026-01-31 10:00:00', 1, 'year', '2027-01-31 10:00:00', 31, '2027-02-28 10:00:00' ],
			'14 day trial ending on 31st'    => [ 'month', '2026-01-17 10:00:00', 14, 'day', '2026-01-31 10:00:00', 31, '2026-02-28 10:00:00' ],
			'2 week trial ending on 2nd'     => [ 'month', '2026-02-16 10:00:00', 2, 'week', '2026-03-02 10:00:00', 2, '2026-04-02 10:00:00' ],
		];
	}

	public function test_without_a_trial_the_billing_day_is_the_start_day(): void {
		$this->assertSame( 31, $this->terms()->get_billing_day_from( $this->utc( '2026-01-31 10:00:00' ) ) );
	}

	/**
	 * The catch-up roll follows the billing day.
	 *
	 * @dataProvider provide_catch_up
	 *
	 * @param string             $period      Plan period.
	 * @param int                $billing_day Billing day.
	 * @param string             $due         Due moment.
	 * @param string             $now         The current moment.
	 * @param array<int, string> $expected    Expected period to bill.
	 * @param string             $next_due    Expected next due moment after `$due`.
	 */
	public function test_the_catch_up_roll_keeps_the_billing_day( string $period, int $billing_day, string $due, string $now, array $expected, string $next_due ): void {
		$terms = $this->terms_on_day( $period, 1, $billing_day );

		$billed = $terms->get_period_to_bill( $this->utc( $due ), $this->utc( $now ) );
		$next   = $terms->get_next_due_after( $this->utc( $due ), $this->utc( $now ) );

		$this->assertSame( $expected, $this->format( $billed ) );
		$this->assertSame( $next_due, $next->get_moment()->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: array<int, string>, 5: string}>
	 */
	public function provide_catch_up(): array {
		return [
			'31st, across February'      => [ 'month', 31, '2026-01-31 10:00:00', '2026-03-10 00:00:00', [ '2026-02-28 10:00:00', '2026-03-31 10:00:00' ], '2026-03-31 10:00:00' ],
			'30th, two periods behind'   => [ 'month', 30, '2026-01-30 10:00:00', '2026-04-10 00:00:00', [ '2026-03-30 10:00:00', '2026-04-30 10:00:00' ], '2026-04-30 10:00:00' ],
			'29th, across February'      => [ 'month', 29, '2026-01-29 10:00:00', '2026-03-10 00:00:00', [ '2026-02-28 10:00:00', '2026-03-29 10:00:00' ], '2026-03-29 10:00:00' ],
			'31st, leap February'        => [ 'month', 31, '2028-01-31 10:00:00', '2028-03-01 00:00:00', [ '2028-02-29 10:00:00', '2028-03-31 10:00:00' ], '2028-03-31 10:00:00' ],
			'leap day, into a leap year' => [ 'year', 29, '2024-02-29 10:00:00', '2027-06-01 00:00:00', [ '2027-02-28 10:00:00', '2028-02-29 10:00:00' ], '2028-02-29 10:00:00' ],
		];
	}

	public function test_a_monthly_roll_cap_restarts_on_the_day_of_now(): void {
		$this->capture_log();
		$terms = $this->terms_on_day( 'month', 1, 15 );

		$period = $terms->get_period_to_bill( $this->utc( '1900-01-15 10:00:00' ), $this->utc( '2026-01-31 10:00:00' ) );
		$next   = $terms->get_next_due_after( $this->utc( '1900-01-15 10:00:00' ), $this->utc( '2026-01-31 10:00:00' ) );

		$this->assertSame( [ '2026-01-31 10:00:00', '2026-02-28 10:00:00' ], $this->format( $period ) );
		$this->assertSame( '2026-02-28 10:00:00', $next->get_moment()->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 31, $period[1]->get_reanchored_billing_day(), 'The restart re-anchors on the day of now.' );
		$this->assertSame( 31, $next->get_reanchored_billing_day() );
		$this->assertTrue( $this->has_logged( 'exhausted the roll cap' ) );

		$after = $terms->with_billing_day( 31 )->get_period_to_bill( $period[1]->get_moment(), $period[1]->get_moment() );
		$this->assertSame( [ '2026-02-28 10:00:00', '2026-03-31 10:00:00' ], $this->format( $after ), 'The renewal after the restart keeps the re-anchored day.' );
	}

	/**
	 * @dataProvider provide_out_of_range_billing_days
	 *
	 * @param int $billing_day Billing day.
	 */
	public function test_with_billing_day_refuses_a_day_outside_the_month( int $billing_day ): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->terms()->with_billing_day( $billing_day );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public function provide_out_of_range_billing_days(): array {
		return [
			'zero' => [ 0 ],
			'32'   => [ 32 ],
		];
	}

	public function test_day_and_week_cadences_ignore_the_billing_day(): void {
		$this->assertSame( [ '2026-02-01 10:00:00' ], $this->renewal_chain( $this->terms_on_day( 'day', 1, 5 ), '2026-01-31 10:00:00', 1 ) );
		$this->assertSame( [ '2026-02-07 10:00:00' ], $this->renewal_chain( $this->terms_on_day( 'week', 1, 5 ), '2026-01-31 10:00:00', 1 ) );
	}

	/**
	 * Month ends and the billing day are read in the store calendar, at the local time of day,
	 * across daylight saving changes; results are stored in UTC.
	 *
	 * @dataProvider provide_store_time_zones
	 *
	 * @param string             $timezone    Store time zone.
	 * @param string             $start       Contract start (UTC).
	 * @param int                $billing_day Expected billing day.
	 * @param array<int, string> $expected    First renewal and the three after it (UTC).
	 */
	public function test_month_steps_follow_the_store_calendar( string $timezone, string $start, int $billing_day, array $expected ): void {
		$this->set_store_timezone( $timezone );
		$terms = $this->terms();

		$day   = $terms->get_billing_day_from( $this->utc( $start ) );
		$first = $terms->get_first_renewal_from( $this->utc( $start ) )->format( 'Y-m-d H:i:s' );
		$chain = $this->renewal_chain( $terms->with_billing_day( $day ), $first, 3 );

		$this->assertSame( $billing_day, $day );
		$this->assertSame( $expected, array_merge( [ $first ], $chain ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int, 3: array<int, string>}>
	 */
	public function provide_store_time_zones(): array {
		return [
			// 31 Jan 08:00 in Sydney: 28 Feb, 31 Mar, 30 Apr, 31 May at 08:00 local (daylight saving ends 5 Apr).
			'Sydney, paid on the 31st local'   => [ 'Australia/Sydney', '2026-01-30 21:00:00', 31, [ '2026-02-27 21:00:00', '2026-03-30 21:00:00', '2026-04-29 22:00:00', '2026-05-30 22:00:00' ] ],
			// 30 Jan 22:00 in New York: 28 Feb, 30 Mar, 30 Apr, 30 May at 22:00 local (daylight saving starts 8 Mar).
			'New York, paid on the 30th local' => [ 'America/New_York', '2026-01-31 03:00:00', 30, [ '2026-03-01 03:00:00', '2026-03-31 02:00:00', '2026-05-01 02:00:00', '2026-05-31 02:00:00' ] ],
			// 31 Dec 23:30 in Kiritimati (UTC+14): 31 Jan, 28 Feb, 31 Mar, 30 Apr at 23:30 local.
			'Kiritimati, far east of UTC'      => [ 'Pacific/Kiritimati', '2026-12-31 09:30:00', 31, [ '2027-01-31 09:30:00', '2027-02-28 09:30:00', '2027-03-31 09:30:00', '2027-04-30 09:30:00' ] ],
		];
	}

	/**
	 * An offset-only store (no time zone identifier, a manual UTC offset) reads month steps at
	 * that fixed offset.
	 */
	public function test_month_steps_follow_an_offset_only_store(): void {
		$this->set_store_timezone( '', 10 );
		$terms = $this->terms();
		$start = $this->utc( '2026-01-30 21:00:00' ); // 31 Jan 07:00 at UTC+10.

		$day   = $terms->get_billing_day_from( $start );
		$first = $terms->get_first_renewal_from( $start )->format( 'Y-m-d H:i:s' );

		$this->assertSame( 31, $day );
		$this->assertSame(
			[ '2026-02-27 21:00:00', '2026-03-30 21:00:00', '2026-04-29 21:00:00', '2026-05-30 21:00:00' ],
			array_merge( [ $first ], $this->renewal_chain( $terms->with_billing_day( $day ), $first, 3 ) )
		);
	}

	/**
	 * A billing time inside the spring-forward gap moves an hour later (PHP's reading of a
	 * local time that does not exist) and stays there: later steps keep the moved local time.
	 */
	public function test_a_billing_time_in_the_spring_forward_gap_moves_an_hour_later_and_stays(): void {
		$this->set_store_timezone( 'America/New_York' );

		// 8 Feb 02:30 EST; 2:00-2:59 on 8 Mar does not exist (daylight saving starts).
		$chain = $this->renewal_chain( $this->terms_on_day( 'month', 1, 8 ), '2026-02-08 07:30:00', 3 );

		$this->assertSame( [ '2026-03-08 07:30:00', '2026-04-08 07:30:00', '2026-05-08 07:30:00' ], $chain, '03:30 EDT on 8 Mar, then 03:30 EDT.' );
	}

	/**
	 * Day and week steps are UTC instants: in a store with daylight saving they keep the UTC
	 * time of day across the change.
	 */
	public function test_day_and_week_steps_keep_the_utc_time_across_daylight_saving(): void {
		$this->set_store_timezone( 'America/New_York' );

		$this->assertSame(
			[ '2026-03-09 10:00:00', '2026-03-16 10:00:00' ],
			$this->renewal_chain( $this->terms_on_day( 'week', 1, 2 ), '2026-03-02 10:00:00', 2 )
		);
		$this->assertSame(
			[ '2026-03-08 10:00:00', '2026-03-09 10:00:00' ],
			$this->renewal_chain( $this->terms_on_day( 'day', 1, 7 ), '2026-03-07 10:00:00', 2 )
		);
	}

	public function test_an_interval_at_the_cap_steps_without_overflow(): void {
		$next = $this->terms( 'year', 1000 )->get_first_renewal_from( $this->utc( '2024-02-29 10:00:00' ) );

		$this->assertSame( '3024-02-29 10:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A due date more than a day off its scheduled date re-anchors: the period steps from the
	 * due date's own store day, a full cadence, and reports that day for the caller to store. A
	 * due date within a day of it, terms without a day, and day or week cadences keep the stored
	 * day; within a day, the steps count from the scheduled date.
	 *
	 * @dataProvider provide_off_schedule_due_dates
	 *
	 * @param string             $period      Plan period.
	 * @param int|null           $billing_day Stored billing day, or null.
	 * @param string             $due         Due moment.
	 * @param array<int, string> $expected    Expected period to bill.
	 * @param int|null           $reanchored  Expected re-anchored billing day, or null.
	 */
	public function test_an_off_schedule_due_date_reanchors_on_its_own_day( string $period, ?int $billing_day, string $due, array $expected, ?int $reanchored ): void {
		$billed = $this->terms_on_day( $period, 1, $billing_day )->get_period_to_bill( $this->utc( $due ), $this->utc( $due ) );

		$this->assertSame( $expected, $this->format( $billed ) );
		$this->assertSame( $reanchored, $billed[1]->get_reanchored_billing_day() );
	}

	/**
	 * @return array<string, array{0: string, 1: int|null, 2: string, 3: array<int, string>, 4: int|null}>
	 */
	public function provide_off_schedule_due_dates(): array {
		return [
			// A weekly contract from 1 Jan whose plan became monthly at its 29 Jan due date.
			'week to month, day 1, due 29 Jan'  => [ 'month', 1, '2026-01-29 10:00:00', [ '2026-01-29 10:00:00', '2026-02-28 10:00:00' ], 29 ],
			'week to month, leap year'          => [ 'month', 1, '2028-01-29 10:00:00', [ '2028-01-29 10:00:00', '2028-02-29 10:00:00' ], 29 ],
			// A weekly contract from 31 Jan whose plan became monthly at its 7 Mar due date.
			'week to month, day 31, due 7 Mar'  => [ 'month', 31, '2026-03-07 10:00:00', [ '2026-03-07 10:00:00', '2026-04-07 10:00:00' ], 7 ],
			'week to year, day 1, due 29 Jan'   => [ 'year', 1, '2026-01-29 10:00:00', [ '2026-01-29 10:00:00', '2027-01-29 10:00:00' ], 29 ],
			'two days late: day 15, due 17 Jan' => [ 'month', 15, '2026-01-17 10:00:00', [ '2026-01-17 10:00:00', '2026-02-17 10:00:00' ], 17 ],
			'two days early: day 1, due 30 Jan' => [ 'month', 1, '2026-01-30 10:00:00', [ '2026-01-30 10:00:00', '2026-02-28 10:00:00' ], 30 ],
			'two days after a clamped 28 Feb'   => [ 'month', 31, '2026-03-02 10:00:00', [ '2026-03-02 10:00:00', '2026-04-02 10:00:00' ], 2 ],
			'on the schedule: 28 Feb, day 31'   => [ 'month', 31, '2026-02-28 10:00:00', [ '2026-02-28 10:00:00', '2026-03-31 10:00:00' ], null ],
			'on the schedule: 30 Nov, day 31'   => [ 'month', 31, '2026-11-30 10:00:00', [ '2026-11-30 10:00:00', '2026-12-31 10:00:00' ], null ],
			'on the schedule: day 15'           => [ 'month', 15, '2026-01-15 10:00:00', [ '2026-01-15 10:00:00', '2026-02-15 10:00:00' ], null ],
			'a day late: day 15, due 16 Jan'    => [ 'month', 15, '2026-01-16 10:00:00', [ '2026-01-16 10:00:00', '2026-02-15 10:00:00' ], null ],
			'a day early: day 1, due 31 Jan'    => [ 'month', 1, '2026-01-31 10:00:00', [ '2026-01-31 10:00:00', '2026-03-01 10:00:00' ], null ],
			'a day after a clamped 28 Feb'      => [ 'month', 31, '2026-03-01 10:00:00', [ '2026-03-01 10:00:00', '2026-03-31 10:00:00' ], null ],
			'a day before a clamped 30 Nov'     => [ 'month', 31, '2026-11-29 10:00:00', [ '2026-11-29 10:00:00', '2026-12-31 10:00:00' ], null ],
			'a day late, yearly: 1 Mar, day 29' => [ 'year', 29, '2027-03-01 10:00:00', [ '2027-03-01 10:00:00', '2028-02-29 10:00:00' ], null ],
			'no stored day'                     => [ 'month', null, '2026-01-29 10:00:00', [ '2026-01-29 10:00:00', '2026-02-28 10:00:00' ], null ],
			'week cadence ignores the day'      => [ 'week', 5, '2026-01-29 10:00:00', [ '2026-01-29 10:00:00', '2026-02-05 10:00:00' ], null ],
			'day cadence ignores the day'       => [ 'day', 5, '2026-01-29 10:00:00', [ '2026-01-29 10:00:00', '2026-01-30 10:00:00' ], null ],
		];
	}

	/**
	 * The tolerance counts store-calendar days, not hours: a day across a DST change is 23 or
	 * 25 hours long, so one day off can be 25 hours and two days off can be 47.
	 *
	 * @dataProvider provide_due_dates_across_daylight_saving
	 *
	 * @param string             $timezone    Store time zone.
	 * @param int                $billing_day Stored billing day.
	 * @param string             $due         Due moment (UTC).
	 * @param array<int, string> $expected    Expected period to bill (UTC).
	 * @param int|null           $reanchored  Expected re-anchored billing day, or null.
	 */
	public function test_the_tolerance_counts_calendar_days_across_daylight_saving( string $timezone, int $billing_day, string $due, array $expected, ?int $reanchored ): void {
		$this->set_store_timezone( $timezone );
		$billed = $this->terms_on_day( 'month', 1, $billing_day )->get_period_to_bill( $this->utc( $due ), $this->utc( $due ) );

		$this->assertSame( $expected, $this->format( $billed ) );
		$this->assertSame( $reanchored, $billed[1]->get_reanchored_billing_day() );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: string, 3: array<int, string>, 4: int|null}>
	 */
	public function provide_due_dates_across_daylight_saving(): array {
		return [
			// 2 Nov 00:30 EST is 25 hours after 1 Nov 00:30 EDT, one calendar day: day 1 stays.
			'25 hours, one day: New York, day 1'  => [ 'America/New_York', 1, '2026-11-02 05:30:00', [ '2026-11-02 05:30:00', '2026-12-01 05:30:00' ], null ],
			// 7 Mar 10:00 EST is 47 hours before 9 Mar 10:00 EDT, two calendar days: re-anchors to 7.
			'47 hours, two days: New York, day 9' => [ 'America/New_York', 9, '2026-03-07 15:00:00', [ '2026-03-07 15:00:00', '2026-04-07 14:00:00' ], 7 ],
		];
	}

	/**
	 * In America/Nuuk 28 Mar 2026 23:30 falls in the daylight saving gap. A due date one day
	 * before it (a store zone change can land one there) is measured against the calendar date
	 * 28 Mar, not the moment the gap moves to the 29th, so it is one day off and day 28 stays.
	 */
	public function test_a_due_date_a_day_before_a_daylight_saving_gap_keeps_the_billing_day(): void {
		$this->set_store_timezone( 'America/Nuuk' );
		$due    = $this->utc( '2026-03-28 01:30:00' );
		$billed = $this->terms_on_day( 'month', 1, 28 )->get_period_to_bill( $due, $due );

		$this->assertSame( '2026-03-27 23:30', $due->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' ), 'The due date reads 27 Mar 23:30 in Nuuk.' );
		$this->assertSame( [ '2026-03-28 01:30:00', '2026-04-28 01:30:00' ], $this->format( $billed ), 'To 28 Apr 00:30 in Nuuk.' );
		$this->assertNull( $billed[1]->get_reanchored_billing_day() );
	}

	public function test_a_reanchored_schedule_continues_on_the_new_day(): void {
		$terms = $this->terms_on_day( 'month', 1, 1 );

		$billed = $terms->get_period_to_bill( $this->utc( '2026-01-29 10:00:00' ), $this->utc( '2026-01-29 10:00:00' ) );
		$day    = $billed[1]->get_reanchored_billing_day();
		$this->assertSame( 29, $day );
		$this->assertSame( 1, $billed[1]->get_replaced_billing_day(), 'It reports the day it replaces.' );

		$chain = $this->renewal_chain( $terms->with_billing_day( $day ), $billed[1]->get_moment()->format( 'Y-m-d H:i:s' ), 2 );
		$this->assertSame( [ '2026-03-29 10:00:00', '2026-04-29 10:00:00' ], $chain );
	}

	public function test_a_rerun_with_the_reanchored_day_bills_the_same_period(): void {
		$due   = $this->utc( '2026-01-29 10:00:00' );
		$first = $this->terms_on_day( 'month', 1, 1 )->get_period_to_bill( $due, $due );
		$this->assertSame( 29, $first[1]->get_reanchored_billing_day() );

		$rerun = $this->terms_on_day( 'month', 1, 29 )->get_period_to_bill( $due, $due );

		$this->assertSame( $this->format( $first ), $this->format( $rerun ), 'A retry after the day was stored bills the same period.' );
		$this->assertNull( $rerun[1]->get_reanchored_billing_day(), 'And re-anchors nothing.' );
	}

	public function test_get_next_due_after_reanchors_an_off_schedule_moment(): void {
		$terms = $this->terms_on_day( 'month', 1, 1 );

		$rolled = $terms->get_next_due_after( $this->utc( '2026-01-29 10:00:00' ), $this->utc( '2026-03-10 00:00:00' ) );
		$kept   = $terms->get_next_due_after( $this->utc( '2026-04-29 10:00:00' ), $this->utc( '2026-04-01 00:00:00' ) );
		$on     = $terms->get_next_due_after( $this->utc( '2026-02-01 10:00:00' ), $this->utc( '2026-03-10 00:00:00' ) );

		$this->assertSame( '2026-03-29 10:00:00', $rolled->get_moment()->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 29, $rolled->get_reanchored_billing_day() );
		$this->assertSame( '2026-04-29 10:00:00', $kept->get_moment()->format( 'Y-m-d H:i:s' ), 'A future moment is kept.' );
		$this->assertSame( 29, $kept->get_reanchored_billing_day(), 'A kept moment off the schedule re-anchors too.' );
		$this->assertSame( '2026-04-01 10:00:00', $on->get_moment()->format( 'Y-m-d H:i:s' ) );
		$this->assertNull( $on->get_reanchored_billing_day() );
	}

	/**
	 * A store time zone change between activation and renewal reads a near-midnight due date
	 * on the neighbouring day: within a day of its scheduled date, so the billing day stays and
	 * the period ends on the next scheduled date, never a day or two months away.
	 *
	 * @dataProvider provide_time_zone_changes
	 *
	 * @param string                     $period      Plan period.
	 * @param array{0: string, 1: float} $before      Store time zone (identifier, offset) at activation.
	 * @param array{0: string, 1: float} $after       Store time zone (identifier, offset) at renewal.
	 * @param int                        $billing_day Billing day stored at activation.
	 * @param string                     $due         Due moment (UTC), on the schedule in `$before`.
	 * @param string                     $end         Expected period end (UTC).
	 */
	public function test_a_store_time_zone_change_keeps_the_billing_day( string $period, array $before, array $after, int $billing_day, string $due, string $end ): void {
		$this->set_store_timezone( $before[0], $before[1] );
		$terms      = $this->terms_on_day( $period, 1, $billing_day );
		$day_before = $this->get_store_day_of( $due );
		$this->assertNull( $terms->get_period_to_bill( $this->utc( $due ), $this->utc( $due ) )[1]->get_reanchored_billing_day(), 'The due date is on the schedule before the change.' );

		$this->set_store_timezone( $after[0], $after[1] );
		$this->assertNotSame( $day_before, $this->get_store_day_of( $due ), 'The change moves it to another store day.' );
		$billed = $terms->get_period_to_bill( $this->utc( $due ), $this->utc( $due ) );

		$this->assertSame( [ $due, $end ], $this->format( $billed ) );
		$this->assertNull( $billed[1]->get_reanchored_billing_day() );
	}

	/**
	 * @return array<string, array{0: string, 1: array{0: string, 1: float}, 2: array{0: string, 1: float}, 3: int, 4: string, 5: string}>
	 */
	public function provide_time_zone_changes(): array {
		return [
			// 1 Mar 00:30 UTC reads 28 Feb 19:30 EST: to 1 Apr 19:30 EDT, not to 28 Mar.
			'UTC to New York, day 1, February' => [ 'month', [ 'UTC', 0.0 ], [ 'America/New_York', 0.0 ], 1, '2026-03-01 00:30:00', '2026-04-01 23:30:00' ],
			// 1 Apr 00:30 BST reads 31 Mar 19:30 EDT: to 1 May 19:30 EDT.
			'London to New York, day 1'        => [ 'month', [ 'Europe/London', 0.0 ], [ 'America/New_York', 0.0 ], 1, '2026-03-31 23:30:00', '2026-05-01 23:30:00' ],
			// 30 Apr 23:00 EDT reads 1 May 03:00 UTC: to 31 May, not to 1 Jun or 30 Jun.
			'New York to UTC, day 31'          => [ 'month', [ 'America/New_York', 0.0 ], [ 'UTC', 0.0 ], 31, '2026-05-01 03:00:00', '2026-05-31 03:00:00' ],
			// A clamped 30 Apr 23:30 UTC reads 1 May 01:30 CEST: to 31 May 01:30 CEST.
			'UTC to Berlin, clamped day 31'    => [ 'month', [ 'UTC', 0.0 ], [ 'Europe/Berlin', 0.0 ], 31, '2026-04-30 23:30:00', '2026-05-30 23:30:00' ],
			// A manual offset moved from UTC+2 to UTC: 1 Apr 00:30 reads 31 Mar 22:30.
			'offset +2 to offset 0'            => [ 'month', [ '', 2.0 ], [ '', 0.0 ], 1, '2026-03-31 22:30:00', '2026-05-01 22:30:00' ],
			// A clamped 28 Feb 2027 23:30 GMT reads 1 Mar 00:30 CET: still 29 Feb in 2028.
			'London to Paris, yearly day 29'   => [ 'year', [ 'Europe/London', 0.0 ], [ 'Europe/Paris', 0.0 ], 29, '2027-02-28 23:30:00', '2028-02-28 23:30:00' ],
		];
	}

	/**
	 * The day of the month of a UTC moment in the store calendar.
	 *
	 * @param string $moment UTC moment.
	 */
	private function get_store_day_of( string $moment ): int {
		return (int) $this->utc( $moment )->setTimezone( wp_timezone() )->format( 'j' );
	}

	public function test_a_store_time_zone_round_trip_keeps_the_billing_day(): void {
		$terms = $this->terms_on_day( 'month', 1, 1 );
		$this->set_store_timezone( 'Europe/London' );
		$due = $this->utc( '2026-03-01 00:30:00' );

		$this->set_store_timezone( 'America/New_York' );
		$there = $terms->get_period_to_bill( $due, $due );
		$this->set_store_timezone( 'Europe/London' );
		$back = $terms->get_period_to_bill( $there[1]->get_moment(), $there[1]->get_moment() );

		$this->assertSame( [ '2026-03-01 00:30:00', '2026-04-01 23:30:00' ], $this->format( $there ), '1 Apr 19:30 in New York.' );
		$this->assertSame( [ '2026-04-01 23:30:00', '2026-04-30 23:30:00' ], $this->format( $back ), '1 May 00:30 in London.' );
		$this->assertNull( $there[1]->get_reanchored_billing_day() );
		$this->assertNull( $back[1]->get_reanchored_billing_day() );
	}

	public function test_a_manual_offset_toggled_for_daylight_saving_keeps_the_billing_day(): void {
		$terms = $this->terms_on_day( 'month', 1, 30 );

		// 30 Apr 23:30 at UTC+1 is 1 May 00:30 at UTC+2.
		$this->set_store_timezone( '', 2.0 );
		$summer = $terms->get_period_to_bill( $this->utc( '2026-04-30 22:30:00' ), $this->utc( '2026-04-30 22:30:00' ) );
		// 30 May 00:30 at UTC+2 is 29 May 23:30 at UTC+1.
		$this->set_store_timezone( '', 1.0 );
		$winter = $terms->get_period_to_bill( $summer[1]->get_moment(), $summer[1]->get_moment() );

		$this->assertSame( [ '2026-04-30 22:30:00', '2026-05-29 22:30:00' ], $this->format( $summer ) );
		$this->assertSame( [ '2026-05-29 22:30:00', '2026-06-30 22:30:00' ], $this->format( $winter ) );
		$this->assertNull( $summer[1]->get_reanchored_billing_day() );
		$this->assertNull( $winter[1]->get_reanchored_billing_day() );
	}

	/**
	 * In America/Nuuk daylight saving starts at 23:00 on the last Saturday of March, so a
	 * 23:30 renewal on 28 Mar 2026 moves to 00:30 on the 29th. That is within a day of the
	 * 28th: the billing day stays 28 and April renews on the 28th.
	 */
	public function test_a_daylight_saving_gap_across_midnight_keeps_the_billing_day(): void {
		$this->set_store_timezone( 'America/Nuuk' );
		$terms  = $this->terms_on_day( 'month', 1, 28 );
		$moment = $this->utc( '2026-03-01 01:30:00' );
		$chain  = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$period = $terms->get_period_to_bill( $moment, $moment );
			$this->assertNull( $period[1]->get_reanchored_billing_day() );
			$moment  = $period[1]->get_moment();
			$chain[] = $moment->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' );
		}

		$this->assertSame( [ '2026-03-29 00:30', '2026-04-28 00:30', '2026-05-28 00:30' ], $chain, 'Store time: the gap moves the time, not the billing day.' );
	}
}
