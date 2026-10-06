<?php
/**
 * Integration tests for Lite's billing terms reader.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\BillingPolicy;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingTerms
 */
final class BillingTermsTest extends LiteIntegrationTestCase {

	public function test_reads_the_engine_billing_policy_shape(): void {
		$policy = new BillingPolicy(
			'week',
			2,
			null,
			6,
			[
				'length' => 14,
				'unit'   => 'day',
			]
		);

		$terms = BillingTerms::from_snapshot( [ 'billing_policy' => $policy->to_array() ] );

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
		$terms = BillingTerms::from_snapshot(
			[
				'billing_policy' => [
					'period'         => 'month',
					'interval'       => '1',
					'max_cycles'     => null,
					'trial_duration' => null,
				],
			]
		);

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertSame( 1, $terms->get_interval() );
		$this->assertNull( $terms->get_max_cycles() );
		$this->assertNull( $terms->get_trial_duration() );
	}

	/**
	 * @dataProvider provide_unusable_snapshots
	 *
	 * @param array<string, mixed>|null $snapshot Snapshot payload.
	 */
	public function test_unusable_payloads_read_as_no_terms( ?array $snapshot ): void {
		$this->assertNull( BillingTerms::from_snapshot( $snapshot ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public function provide_unusable_snapshots(): array {
		return [
			'no payload'        => [ null ],
			'no billing policy' => [ [ 'selling_plan_id' => 1 ] ],
			'policy not array'  => [ [ 'billing_policy' => 'month' ] ],
			'unknown period'    => [
				[
					'billing_policy' => [
						'period'   => 'fortnight',
						'interval' => 1,
					],
				],
			],
			'zero interval'     => [
				[
					'billing_policy' => [
						'period'   => 'month',
						'interval' => 0,
					],
				],
			],
			'missing interval'  => [ [ 'billing_policy' => [ 'period' => 'month' ] ] ],
		];
	}

	public function test_a_malformed_trial_reads_as_none(): void {
		$terms = BillingTerms::from_snapshot(
			[
				'billing_policy' => [
					'period'         => 'month',
					'interval'       => 1,
					'max_cycles'     => 'many',
					'trial_duration' => [
						'length' => 0,
						'unit'   => 'day',
					],
				],
			]
		);

		$this->assertInstanceOf( BillingTerms::class, $terms );
		$this->assertNull( $terms->get_trial_duration() );
		$this->assertNull( $terms->get_max_cycles() );
	}
}
