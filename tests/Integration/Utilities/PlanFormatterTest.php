<?php
/**
 * Integration tests for the shared plan formatter.
 *
 * PlanFormatter owns the surface-neutral billing-period wording that the admin,
 * product, cart, and customer-portal surfaces share. The methods take plain
 * period/interval primitives, so the assertions read the wording directly.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Utilities;

use Automattic\WooCommerce\SubscriptionsLite\Utilities\PlanFormatter;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Utilities\PlanFormatter
 */
final class PlanFormatterTest extends LiteIntegrationTestCase {

	public function test_period_label_pluralizes_each_unit(): void {
		$this->assertSame( 'day', PlanFormatter::period_label( 'day', 1 ) );
		$this->assertSame( 'days', PlanFormatter::period_label( 'day', 2 ) );
		$this->assertSame( 'week', PlanFormatter::period_label( 'week', 1 ) );
		$this->assertSame( 'weeks', PlanFormatter::period_label( 'week', 3 ) );
		$this->assertSame( 'month', PlanFormatter::period_label( 'month', 1 ) );
		$this->assertSame( 'months', PlanFormatter::period_label( 'month', 6 ) );
		$this->assertSame( 'year', PlanFormatter::period_label( 'year', 1 ) );
		$this->assertSame( 'years', PlanFormatter::period_label( 'year', 2 ) );
	}

	public function test_period_label_falls_through_for_an_unknown_unit(): void {
		$this->assertSame( 'fortnight', PlanFormatter::period_label( 'fortnight', 2 ) );
	}

	public function test_explicit_cadence_reads_every_n_periods(): void {
		$this->assertSame( 'Every 1 month', PlanFormatter::explicit_cadence( 'month', 1 ) );
		$this->assertSame( 'Every 3 months', PlanFormatter::explicit_cadence( 'month', 3 ) );
		$this->assertSame( 'Every 2 weeks', PlanFormatter::explicit_cadence( 'week', 2 ) );
	}

	public function test_explicit_cadence_clamps_a_non_positive_interval_to_one(): void {
		$this->assertSame( 'Every 1 month', PlanFormatter::explicit_cadence( 'month', 0 ) );
		$this->assertSame( 'Every 1 day', PlanFormatter::explicit_cadence( 'day', -5 ) );
	}

	public function test_price_cadence_uses_the_slash_form_at_interval_one(): void {
		$this->assertSame( '/ day', PlanFormatter::price_cadence( 'day', 1 ) );
		$this->assertSame( '/ week', PlanFormatter::price_cadence( 'week', 1 ) );
		$this->assertSame( '/ month', PlanFormatter::price_cadence( 'month', 1 ) );
		$this->assertSame( '/ year', PlanFormatter::price_cadence( 'year', 1 ) );
	}

	public function test_price_cadence_uses_the_every_n_form_above_interval_one(): void {
		$this->assertSame( 'every 2 days', PlanFormatter::price_cadence( 'day', 2 ) );
		$this->assertSame( 'every 2 weeks', PlanFormatter::price_cadence( 'week', 2 ) );
		$this->assertSame( 'every 3 months', PlanFormatter::price_cadence( 'month', 3 ) );
		$this->assertSame( 'every 2 years', PlanFormatter::price_cadence( 'year', 2 ) );
	}

	public function test_price_cadence_is_empty_for_an_unknown_period(): void {
		$this->assertSame( '', PlanFormatter::price_cadence( 'fortnight', 1 ) );
		$this->assertSame( '', PlanFormatter::price_cadence( 'fortnight', 2 ) );
	}

	public function test_placeholder_is_a_plain_hyphen(): void {
		$this->assertSame( '-', PlanFormatter::PLACEHOLDER );
	}
}
