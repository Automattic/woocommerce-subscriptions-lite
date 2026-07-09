<?php
/**
 * Integration tests for the shared Formatter utility.
 *
 * Formatter owns the surface-neutral billing-period wording that the admin,
 * product, cart, and customer-portal surfaces share. The methods take plain
 * period/interval primitives, so the assertions read the wording directly.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Utilities;

use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter
 */
final class FormatterTest extends LiteIntegrationTestCase {

	public function test_period_label_pluralizes_each_unit(): void {
		$this->assertSame( 'day', Formatter::period_label( 'day', 1 ) );
		$this->assertSame( 'days', Formatter::period_label( 'day', 2 ) );
		$this->assertSame( 'week', Formatter::period_label( 'week', 1 ) );
		$this->assertSame( 'weeks', Formatter::period_label( 'week', 3 ) );
		$this->assertSame( 'month', Formatter::period_label( 'month', 1 ) );
		$this->assertSame( 'months', Formatter::period_label( 'month', 6 ) );
		$this->assertSame( 'year', Formatter::period_label( 'year', 1 ) );
		$this->assertSame( 'years', Formatter::period_label( 'year', 2 ) );
	}

	public function test_period_label_falls_through_for_an_unknown_unit(): void {
		$this->assertSame( 'fortnight', Formatter::period_label( 'fortnight', 2 ) );
	}

	public function test_explicit_cadence_reads_every_n_periods(): void {
		$this->assertSame( 'Every 1 month', Formatter::explicit_cadence( 'month', 1 ) );
		$this->assertSame( 'Every 3 months', Formatter::explicit_cadence( 'month', 3 ) );
		$this->assertSame( 'Every 2 weeks', Formatter::explicit_cadence( 'week', 2 ) );
	}

	public function test_explicit_cadence_clamps_a_non_positive_interval_to_one(): void {
		$this->assertSame( 'Every 1 month', Formatter::explicit_cadence( 'month', 0 ) );
		$this->assertSame( 'Every 1 day', Formatter::explicit_cadence( 'day', -5 ) );
	}

	public function test_price_cadence_uses_the_slash_form_at_interval_one(): void {
		$this->assertSame( '/ day', Formatter::price_cadence( 'day', 1 ) );
		$this->assertSame( '/ week', Formatter::price_cadence( 'week', 1 ) );
		$this->assertSame( '/ month', Formatter::price_cadence( 'month', 1 ) );
		$this->assertSame( '/ year', Formatter::price_cadence( 'year', 1 ) );
	}

	public function test_price_cadence_uses_the_every_n_form_above_interval_one(): void {
		$this->assertSame( 'every 2 days', Formatter::price_cadence( 'day', 2 ) );
		$this->assertSame( 'every 2 weeks', Formatter::price_cadence( 'week', 2 ) );
		$this->assertSame( 'every 3 months', Formatter::price_cadence( 'month', 3 ) );
		$this->assertSame( 'every 2 years', Formatter::price_cadence( 'year', 2 ) );
	}

	public function test_price_cadence_is_empty_for_an_unknown_period(): void {
		$this->assertSame( '', Formatter::price_cadence( 'fortnight', 1 ) );
		$this->assertSame( '', Formatter::price_cadence( 'fortnight', 2 ) );
	}

	public function test_placeholder_is_a_plain_hyphen(): void {
		$this->assertSame( '-', Formatter::PLACEHOLDER );
	}
}
