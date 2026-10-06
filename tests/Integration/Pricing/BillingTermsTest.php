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

	public function test_an_unusable_trial_and_length_read_as_none(): void {
		$terms = BillingTerms::from_plan(
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
		);

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertNull( $terms->get_trial_duration() );
		$this->assertNull( $terms->get_max_cycles() );
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
				'owner'          => 'another-extension',
				'billing_policy' => $billing_policy,
			]
		);
	}
}
