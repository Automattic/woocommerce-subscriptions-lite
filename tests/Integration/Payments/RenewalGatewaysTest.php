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

	public function test_an_enabled_gateway_that_can_charge_renewals_is_found(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE, RenewalGateways::FEATURE ] );
		$this->enable_only_gateways( [ 'bacs', 'cod' ] );

		$this->assertTrue( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_a_disabled_gateway_that_can_charge_renewals_is_not_found(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE, RenewalGateways::FEATURE ] );
		$this->enable_only_gateways( [ 'bacs', 'cheque' ] );

		$this->assertFalse( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_enabled_gateways_that_cannot_charge_renewals_are_not_found(): void {
		$this->enable_only_gateways( [ 'bacs', 'cheque', 'cod' ] );

		$this->assertFalse( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_an_enabled_gateway_with_only_the_engine_feature_is_not_found(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE ] );
		$this->enable_only_gateways( [ 'cod' ] );

		$this->assertFalse( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_the_setup_message_links_to_the_payments_settings(): void {
		$message = RenewalGateways::get_setup_message();

		$this->assertStringContainsString( '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '">', $message );
		$this->assertStringContainsString( '</a>', $message );
	}
}
