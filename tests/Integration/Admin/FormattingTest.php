<?php
/**
 * Integration tests for the admin date and money formatting helpers.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Admin;

use Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use Automattic\WooCommerce\SubscriptionsLite\Utilities\Formatter;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Admin\Formatting
 */
final class FormattingTest extends LiteIntegrationTestCase {

	public function test_a_date_shows_the_store_calendar_day(): void {
		$this->set_store_timezone( 'Pacific/Auckland' );
		update_option( 'date_format', 'F j, Y' );

		// 11:30 UTC on 29 Nov is 00:30 on 30 Nov in Auckland (UTC+13).
		$this->assertSame( 'November 30, 2026', Formatting::date( '2026-11-29 11:30:00' ) );
	}

	public function test_a_date_west_of_utc_shows_the_previous_day(): void {
		$this->set_store_timezone( 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );

		// 03:00 UTC on 1 Dec is 22:00 on 30 Nov in New York.
		$this->assertSame( 'November 30, 2026', Formatting::date( '2026-12-01 03:00:00' ) );
	}

	public function test_an_absent_date_shows_the_placeholder(): void {
		$this->assertSame( Formatter::PLACEHOLDER, Formatting::date( null ) );
	}
}
