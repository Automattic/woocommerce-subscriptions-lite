<?php
/**
 * Integration tests for the plan option formatter.
 *
 * The formatter turns an engine plan view into the PDP option text and the admin
 * panel's Frequency / Discount column strings. It runs against the real
 * wc_price(): the assertions compare the tag-stripped, entity-decoded text so
 * the expected strings stay readable while the real price HTML still flows
 * through the formatter.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\ProductPage;

use Automattic\WooCommerce\SubscriptionsEngine\Api\View\PlanView;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;
use Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\ProductPage\PlanOptionFormatter
 */
final class PlanOptionFormatterTest extends LiteIntegrationTestCase {

	/**
	 * Create a plan with the given pricing policies and cadence, unvalidated:
	 * the formatter reads whatever a plan stores.
	 *
	 * @param array<int, array<string, mixed>>|null $policies Pricing-policy entries, or null for no pricing policy.
	 * @param string                                $period   Billing period unit.
	 * @param int                                   $interval Billing interval count.
	 */
	private function make_priced_plan( ?array $policies = null, string $period = 'month', int $interval = 1 ): PlanView {
		return $this->make_unvalidated_plan(
			$period,
			$interval,
			null,
			[
				'pricing_policy' => null === $policies ? null : [
					'policies'      => $policies,
					'one_time_fees' => [],
				],
			]
		);
	}

	/**
	 * Reduce wc_price() output to readable text: strip the price markup and
	 * decode the currency entity (`&#36;` -> `$`).
	 *
	 * @param string $html Formatter output carrying wc_price() HTML.
	 */
	private static function as_text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES );
	}

	public function test_percentage_discount_matches_the_spec_example_shape(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'percentage',
					'value' => 10.0,
				],
			]
		);

		$this->assertSame( '$21.60 / month (10% off)', self::as_text( PlanOptionFormatter::format( $plan, 24.0 ) ) );
	}

	public function test_the_price_carries_real_wc_price_markup(): void {
		$plan = $this->make_priced_plan( null );

		$this->assertStringContainsString( 'woocommerce-Price-amount', PlanOptionFormatter::format( $plan, 24.0 ) );
	}

	public function test_non_whole_percentage_keeps_its_precision(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'percentage',
					'value' => 12.5,
				],
			]
		);

		$this->assertStringEndsWith( '(12.5% off)', self::as_text( PlanOptionFormatter::format( $plan, 100.0 ) ) );
	}

	public function test_fixed_amount_discount(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'fixed_amount',
					'value' => 5.0,
				],
			]
		);

		$this->assertSame( '$15.00 / month ($5.00 off)', self::as_text( PlanOptionFormatter::format( $plan, 20.0 ) ) );
	}

	public function test_price_replacement_below_base_shows_the_difference(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'price',
					'value' => 12.0,
				],
			]
		);

		$this->assertSame( '$12.00 / month ($8.00 off)', self::as_text( PlanOptionFormatter::format( $plan, 20.0 ) ) );
	}

	public function test_price_replacement_increase_gets_no_suffix(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'price',
					'value' => 15.0,
				],
			]
		);

		$this->assertSame( '$15.00 / month', self::as_text( PlanOptionFormatter::format( $plan, 10.0 ) ) );
	}

	public function test_an_unknown_lead_entry_is_skipped_and_the_next_valid_entry_leads(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'tiered',
					'value' => 50.0,
				],
				[
					'type'  => 'percentage',
					'value' => 10.0,
				],
			]
		);

		$this->assertSame( '$21.60 / month (10% off)', self::as_text( PlanOptionFormatter::format( $plan, 24.0 ) ) );
		$this->assertSame( '10% off', self::as_text( PlanOptionFormatter::format_discount( $plan, 24.0 ) ) );
	}

	public function test_no_pricing_policy_renders_price_and_frequency_only(): void {
		$plan = $this->make_priced_plan( null );

		$this->assertSame( '$24.00 / month', self::as_text( PlanOptionFormatter::format( $plan, 24.0 ) ) );
	}

	public function test_zero_value_adjustments_produce_no_suffix(): void {
		$percentage = $this->make_priced_plan(
			[
				[
					'type'  => 'percentage',
					'value' => 0.0,
				],
			]
		);
		$fixed      = $this->make_priced_plan(
			[
				[
					'type'  => 'fixed_amount',
					'value' => 0.0,
				],
			]
		);

		$this->assertSame( '$24.00 / month', self::as_text( PlanOptionFormatter::format( $percentage, 24.0 ) ) );
		$this->assertSame( '$24.00 / month', self::as_text( PlanOptionFormatter::format( $fixed, 24.0 ) ) );
	}

	public function test_starting_cycle_above_one_appends_the_from_cycle_qualifier(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'           => 'fixed_amount',
					'value'          => 5.0,
					'starting_cycle' => 2,
				],
			]
		);

		// Cycle 1 is unaffected by the later-starting policy, so the price is the base.
		$this->assertSame( '$20.00 / month ($5.00 off (from cycle 2))', self::as_text( PlanOptionFormatter::format( $plan, 20.0 ) ) );
	}

	public function test_plural_frequency(): void {
		$plan = $this->make_priced_plan( null, 'month', 3 );

		$this->assertSame( '$24.00 every 3 months', self::as_text( PlanOptionFormatter::format( $plan, 24.0 ) ) );
	}

	public function test_format_frequency_is_capitalized_and_pluralized(): void {
		$this->assertSame( 'Every 1 month', PlanOptionFormatter::format_frequency( $this->make_priced_plan( null ) ) );
		$this->assertSame( 'Every 2 weeks', PlanOptionFormatter::format_frequency( $this->make_priced_plan( null, 'week', 2 ) ) );
	}

	public function test_plan_label_reads_a_single_interval_as_an_adjective(): void {
		$this->assertSame( 'Daily', PlanOptionFormatter::plan_label( $this->make_priced_plan( null, 'day', 1 ) ) );
		$this->assertSame( 'Weekly', PlanOptionFormatter::plan_label( $this->make_priced_plan( null, 'week', 1 ) ) );
		$this->assertSame( 'Monthly', PlanOptionFormatter::plan_label( $this->make_priced_plan( null, 'month', 1 ) ) );
		$this->assertSame( 'Yearly', PlanOptionFormatter::plan_label( $this->make_priced_plan( null, 'year', 1 ) ) );
	}

	public function test_plan_label_reads_a_multi_interval_as_a_count(): void {
		$this->assertSame( 'Every 2 weeks', PlanOptionFormatter::plan_label( $this->make_priced_plan( null, 'week', 2 ) ) );
		$this->assertSame( 'Every 3 months', PlanOptionFormatter::plan_label( $this->make_priced_plan( null, 'month', 3 ) ) );
	}

	public function test_cadence_suffix_uses_the_slash_form_at_interval_one(): void {
		$this->assertSame( '/ month', PlanOptionFormatter::cadence_suffix( $this->make_priced_plan( null, 'month', 1 ) ) );
		$this->assertSame( '/ week', PlanOptionFormatter::cadence_suffix( $this->make_priced_plan( null, 'week', 1 ) ) );
	}

	public function test_cadence_suffix_uses_the_every_form_above_interval_one(): void {
		$this->assertSame( 'every 2 weeks', PlanOptionFormatter::cadence_suffix( $this->make_priced_plan( null, 'week', 2 ) ) );
		$this->assertSame( 'every 3 months', PlanOptionFormatter::cadence_suffix( $this->make_priced_plan( null, 'month', 3 ) ) );
	}

	public function test_format_discount_returns_the_placeholder_when_there_is_nothing_to_show(): void {
		$this->assertSame( Formatter::PLACEHOLDER, PlanOptionFormatter::format_discount( $this->make_priced_plan( null ), 24.0 ) );
		$this->assertSame( Formatter::PLACEHOLDER, PlanOptionFormatter::format_discount( $this->make_priced_plan( [] ), 24.0 ) );
	}

	public function test_format_discount_returns_the_discount_string(): void {
		$plan = $this->make_priced_plan(
			[
				[
					'type'  => 'percentage',
					'value' => 10.0,
				],
			]
		);

		$this->assertSame( '10% off', PlanOptionFormatter::format_discount( $plan, 24.0 ) );
	}

	public function test_a_plan_without_usable_billing_formats_the_price_only(): void {
		$plan = $this->make_unvalidated_plan(
			'month',
			1,
			null,
			[
				'billing_policy' => null,
				'pricing_policy' => [
					'policies' => [
						[
							'type'  => 'percentage',
							'value' => 10.0,
						],
					],
				],
			]
		);

		$this->assertSame( '$21.60 (10% off)', self::as_text( PlanOptionFormatter::format( $plan, 24.0 ) ) );
		$this->assertSame( '', PlanOptionFormatter::cadence_suffix( $plan ) );
		$this->assertSame( '', PlanOptionFormatter::plan_label( $plan ) );
		$this->assertSame( '', PlanOptionFormatter::format_frequency( $plan ) );
	}
}
