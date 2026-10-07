<?php
/**
 * Integration tests for Lite's billing terms reader.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Plan;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms
 */
final class BillingTermsTest extends LiteIntegrationTestCase {

	public function test_reads_the_plan_billing_policy(): void {
		$terms = BillingTerms::from_plan(
			$this->plan(
				new BillingPolicy(
					'week',
					2,
					null,
					6,
					[
						'length' => 14,
						'unit'   => 'day',
					]
				)
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
		$terms = BillingTerms::from_plan( $this->plan( new BillingPolicy( 'month', 1, null, null, null ) ) );

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertSame( 1, $terms->get_interval() );
		$this->assertNull( $terms->get_max_cycles() );
		$this->assertNull( $terms->get_trial_duration() );
	}

	public function test_no_plan_reads_as_no_terms(): void {
		$this->assertNull( BillingTerms::from_plan( null ) );
	}

	/**
	 * @dataProvider provide_unusable_policies
	 *
	 * @param string $period   Billing period.
	 * @param int    $interval Billing interval.
	 */
	public function test_unusable_policies_read_as_no_terms( string $period, int $interval ): void {
		$this->assertNull( BillingTerms::from_plan( $this->plan( new BillingPolicy( $period, $interval, null, null, null ) ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function provide_unusable_policies(): array {
		return [
			'unknown period' => [ 'fortnight', 1 ],
			'zero interval'  => [ 'month', 0 ],
		];
	}

	public function test_an_unusable_trial_and_length_read_as_none(): void {
		$terms = BillingTerms::from_plan(
			$this->plan(
				new BillingPolicy(
					'month',
					1,
					null,
					0,
					[
						'length' => 0,
						'unit'   => 'day',
					]
				)
			)
		);

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertNull( $terms->get_trial_duration() );
		$this->assertNull( $terms->get_max_cycles() );
	}

	/**
	 * An unsaved plan with the given billing policy.
	 *
	 * @param BillingPolicy $policy Billing policy.
	 */
	private function plan( BillingPolicy $policy ): Plan {
		return Plan::create(
			[
				'name'           => 'Plan',
				'billing_policy' => $policy,
				'category'       => Plan::DEFAULT_CATEGORY,
				'extension_slug' => Package::EXTENSION_SLUG,
			]
		);
	}
}
