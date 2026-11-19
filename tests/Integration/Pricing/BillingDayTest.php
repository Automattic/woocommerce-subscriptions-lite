<?php
/**
 * Integration tests for the billing day kept in contract meta.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Pricing;

use Automattic\WooCommerce\SubscriptionsEngine\Api\ContractLockedException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\Contracts;
use Automattic\WooCommerce\SubscriptionsLite\Package;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingDate;
use Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingDay;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Pricing\BillingDay
 */
final class BillingDayTest extends LiteIntegrationTestCase {

	public function test_a_stored_billing_day_reads_back(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id );
		try {
			BillingDay::store( $lock, 31 );
		} finally {
			$lock->release();
		}

		$this->assertSame( 31, BillingDay::read( $contract_id ) );
	}

	public function test_storing_again_overwrites_the_day(): void {
		$contract_id = $this->seed_contract();
		$lock        = $this->hold_lock( $contract_id );
		try {
			BillingDay::store( $lock, 31 );
			BillingDay::store( $lock, 31 );
			BillingDay::store( $lock, 10 );
		} finally {
			$lock->release();
		}

		$this->assertSame( 10, BillingDay::read( $contract_id ) );
		$this->assertSame( [ '10' ], array_map( 'strval', Contracts::get_meta( $contract_id, BillingDay::META_KEY ) ) );
	}

	public function test_a_reanchored_date_stores_and_logs_its_billing_day(): void {
		$contract_id = $this->seed_contract();
		$moment      = new DateTimeImmutable( '2026-02-28 10:00:00', new DateTimeZone( 'UTC' ) );
		$lock        = $this->hold_lock( $contract_id );
		try {
			BillingDay::store( $lock, 1 );
			$this->capture_log();
			BillingDay::store_reanchored_day( $lock, new BillingDate( $moment, 29, 1 ) );
		} finally {
			$lock->release();
		}

		$this->assertSame( 29, BillingDay::read( $contract_id ) );
		$this->assertTrue( $this->has_logged( sprintf( 'contract %d re-anchored its billing day from 1 to 29; next due 2026-02-28 10:00:00 UTC.', $contract_id ) ) );
	}

	public function test_a_date_on_the_schedule_leaves_the_billing_day_alone(): void {
		$contract_id = $this->seed_contract();
		$moment      = new DateTimeImmutable( '2026-02-28 10:00:00', new DateTimeZone( 'UTC' ) );
		$lock        = $this->hold_lock( $contract_id );
		try {
			BillingDay::store( $lock, 31 );
			$this->capture_log();
			BillingDay::store_reanchored_day( $lock, new BillingDate( $moment, null ) );
		} finally {
			$lock->release();
		}

		$this->assertSame( 31, BillingDay::read( $contract_id ) );
		$this->assertFalse( $this->has_logged( 're-anchored its billing day' ) );
	}

	public function test_a_contract_without_a_billing_day_reads_as_none(): void {
		$this->capture_log();

		$this->assertNull( BillingDay::read( $this->seed_contract() ) );
		$this->assertFalse( $this->has_logged( 'BillingDay' ) );
	}

	/**
	 * @dataProvider provide_malformed_days
	 *
	 * @param mixed $stored Raw meta value.
	 */
	public function test_a_malformed_billing_day_reads_as_none_and_is_logged( $stored ): void {
		$contract_id = $this->seed_contract();
		Contracts::update_meta( $contract_id, Package::EXTENSION_SLUG, BillingDay::META_KEY, $stored );
		$this->capture_log();

		$this->assertNull( BillingDay::read( $contract_id ) );
		$this->assertTrue( $this->has_logged( 'malformed billing day' ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_malformed_days(): array {
		return [
			'zero'       => [ 0 ],
			'32'         => [ 32 ],
			'negative'   => [ -1 ],
			'a fraction' => [ '1.5' ],
			'a word'     => [ 'last' ],
			'an array'   => [ [ 31 ] ],
		];
	}

	public function test_a_store_while_another_operation_holds_the_lock_throws(): void {
		$contract_id = $this->seed_contract();
		$stale       = $this->hold_lock( $contract_id, 'first' );
		$stale->release();
		$holder = $this->hold_lock( $contract_id, 'second' );

		try {
			$this->expectException( ContractLockedException::class );
			BillingDay::store( $stale, 31 );
		} finally {
			$holder->release();
			$this->assertNull( BillingDay::read( $contract_id ), 'Nothing was stored.' );
		}
	}
}
