<?php
/**
 * Integration tests for RenewalGateways.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Payments;

use Automattic\WooCommerce\SubscriptionsEngine\Api\Payments;
use Automattic\WooCommerce\SubscriptionsLite\Payments\RenewalGateways;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Payments\RenewalGateways
 */
final class RenewalGatewaysTest extends LiteIntegrationTestCase {

	/**
	 * Make the `cod` gateway declare exactly `$features`.
	 *
	 * @param string[] $features Gateway features.
	 */
	private function declare_cod_features( array $features ): void {
		add_filter(
			'woocommerce_payment_gateway_supports',
			static function ( $supported, $feature, $gateway ) use ( $features ) {
				return is_object( $gateway ) && 'cod' === $gateway->id ? in_array( $feature, $features, true ) : $supported;
			},
			10,
			3
		);
	}

	public function test_a_gateway_with_the_engine_and_subscriptions_features_can_charge_renewals(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE, RenewalGateways::FEATURE ] );

		$this->assertTrue( RenewalGateways::can_charge_renewals( 'cod' ) );
	}

	public function test_a_gateway_with_only_the_engine_feature_cannot(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE ] );

		$this->assertFalse( RenewalGateways::can_charge_renewals( 'cod' ) );
	}

	public function test_a_gateway_with_only_the_subscriptions_feature_cannot(): void {
		$this->declare_cod_features( [ RenewalGateways::FEATURE ] );

		$this->assertFalse( RenewalGateways::can_charge_renewals( 'cod' ) );
	}

	public function test_an_unknown_gateway_cannot(): void {
		$this->assertFalse( RenewalGateways::can_charge_renewals( 'no-such-gateway' ) );
	}
}
