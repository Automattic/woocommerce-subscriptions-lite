<?php
/**
 * Integration tests for Lite's billing terms reader.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms
 */
final class BillingTermsTest extends LiteIntegrationTestCase {

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

	public function test_an_unusable_trial_and_length_read_as_no_terms(): void {
		// Plans are read strictly: a payload contract creation cannot parse is not billable.
		$this->assertNull(
			BillingTerms::from_plan(
				$this->foreign_plan(
					[
						'period'         => 'month',
						'interval'       => 1,
						'max_cycles'     => 0,
						'trial_duration' => [
							'length' => 0,
							'unit'   => 'day',
						],
					]
				)
			)
		);
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

	/**
	 * @dataProvider provide_billing_payloads_to_validate
	 *
	 * @param array<string, mixed>|null $policy         Billing payload.
	 * @param string|null               $message_prefix Expected message prefix, or null when billable.
	 */
	public function test_validate_reports_why_a_payload_is_not_billable( ?array $policy, ?string $message_prefix ): void {
		$messages = BillingTerms::validate( $policy );

		if ( null === $message_prefix ) {
			$this->assertSame( [], $messages );
			return;
		}

		$this->assertCount( 1, $messages );
		$this->assertStringStartsWith( $message_prefix, $messages[0] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: string|null}>
	 */
	public function provide_billing_payloads_to_validate(): array {
		return [
			'billable'           => [
				[
					'period'         => 'week',
					'interval'       => 2,
					'trial_duration' => [
						'length' => 7,
						'unit'   => 'day',
					],
				],
				null,
			],
			'null'               => [ null, 'billing_policy must have a period' ],
			'unknown period'     => [
				[
					'period'   => 'fortnight',
					'interval' => 1,
				],
				'billing_policy must have a period',
			],
			'string interval'    => [
				[
					'period'   => 'month',
					'interval' => '1',
				],
				'billing_policy: ',
			],
			'unknown trial unit' => [
				[
					'period'         => 'month',
					'interval'       => 1,
					'trial_duration' => [
						'length' => 1,
						'unit'   => 'fortnight',
					],
				],
				'billing_policy: ',
			],
		];
	}

	public function test_first_renewal_from_applies_the_trial(): void {
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

		$next = BillingTerms::first_renewal_from( $plan, new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '2026-01-15 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
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
}
