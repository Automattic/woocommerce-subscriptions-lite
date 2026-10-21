<?php
/**
 * Integration tests for the strict integer parser.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Utilities;

use Automattic\WooCommerce\SubscriptionsLite\Utilities\Integers;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Utilities\Integers
 */
final class IntegersTest extends LiteIntegrationTestCase {

	/**
	 * @dataProvider provide_values
	 *
	 * @param mixed    $value    Raw value.
	 * @param int|null $expected Parsed integer, or null.
	 */
	public function test_parse_reads_only_integers_and_integer_strings( $value, ?int $expected ): void {
		$this->assertSame( $expected, Integers::parse( $value ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int|null}>
	 */
	public function provide_values(): array {
		return [
			'int'                 => [ 5, 5 ],
			'zero'                => [ 0, 0 ],
			'negative int'        => [ -3, -3 ],
			'digit string'        => [ '5', 5 ],
			'signed string'       => [ '+5', 5 ],
			'negative string'     => [ '-2', -2 ],
			'padded string'       => [ ' 5 ', 5 ],
			'negative zero'       => [ '-0', 0 ],
			'overflowing string'  => [ '99999999999999999999', null ],
			'leading zero string' => [ '007', null ],
			'decimal string'      => [ '1.5', null ],
			'exponent string'     => [ '1e2', null ],
			'word'                => [ 'abc', null ],
			'empty string'        => [ '', null ],
			'whole float'         => [ 2.0, null ],
			'bool'                => [ true, null ],
			'null'                => [ null, null ],
			'array'               => [ [ 1 ], null ],
		];
	}
}
