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
	 * The `enabled` value of every registered gateway before the test.
	 *
	 * @var array<string, string>
	 */
	private $gateways_enabled_before = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$this->gateways_enabled_before[ $gateway_id ] = $gateway->enabled;
		}
	}

	public function tear_down(): void {
		foreach ( $this->gateways_enabled_before as $gateway_id => $enabled ) {
			WC()->payment_gateways()->payment_gateways()[ $gateway_id ]->enabled = $enabled;
		}
		parent::tear_down();
	}

	/**
	 * Enable exactly the given gateways and disable every other one.
	 *
	 * @param string[] $gateway_ids Gateway ids to enable.
	 */
	private function enable_only( array $gateway_ids ): void {
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
			$gateway->enabled = in_array( $gateway_id, $gateway_ids, true ) ? 'yes' : 'no';
		}
	}

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
		$this->enable_only( [ 'bacs', 'cod' ] );

		$this->assertTrue( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_a_disabled_gateway_that_can_charge_renewals_is_not_found(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE, RenewalGateways::FEATURE ] );
		$this->enable_only( [ 'bacs', 'cheque' ] );

		$this->assertFalse( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_enabled_gateways_that_cannot_charge_renewals_are_not_found(): void {
		$this->enable_only( [ 'bacs', 'cheque', 'cod' ] );

		$this->assertFalse( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_an_enabled_gateway_with_only_the_engine_feature_is_not_found(): void {
		$this->declare_cod_features( [ Payments::GATEWAY_FEATURE ] );
		$this->enable_only( [ 'cod' ] );

		$this->assertFalse( RenewalGateways::has_enabled_renewal_gateway() );
	}

	public function test_the_setup_message_links_to_the_payments_settings(): void {
		$message = RenewalGateways::get_setup_message();

		$this->assertStringContainsString( 'charge subscription renewals automatically', $message );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ), $message );
	}
}
