<?php
/**
 * Integration tests for the subscription erasure setting.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Privacy;

use Automattic\WooCommerce\SubscriptionsLite\Privacy\ErasureSetting;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use WC_Settings_Accounts;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Privacy\ErasureSetting
 */
final class ErasureSettingTest extends LiteIntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * The ids of WooCommerce's real Accounts and Privacy settings, in order.
	 *
	 * @return array<int, string>
	 */
	private function account_setting_ids(): array {
		require_once WC()->plugin_path() . '/includes/admin/settings/class-wc-settings-page.php';
		require_once WC()->plugin_path() . '/includes/admin/settings/class-wc-settings-accounts.php';

		return array_values( array_filter( array_column( ( new WC_Settings_Accounts() )->get_settings_for_section( '' ), 'id' ) ) );
	}

	/**
	 * The added field from a settings array.
	 *
	 * @param array<int, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function field_of( array $settings ): array {
		foreach ( $settings as $setting ) {
			if ( is_array( $setting ) && ErasureSetting::OPTION === ( $setting['id'] ?? null ) ) {
				return $setting;
			}
		}
		$this->fail( 'The subscription erasure field is present.' );
	}

	public function test_the_checkbox_follows_woocommerces_order_erasure_checkbox(): void {
		$ids = $this->account_setting_ids();

		$position = array_search( 'woocommerce_erasure_request_removes_order_data', $ids, true );
		$this->assertIsInt( $position );
		$this->assertSame( ErasureSetting::OPTION, $ids[ $position + 1 ] ?? null );
		$this->assertCount( 1, array_keys( $ids, ErasureSetting::OPTION, true ), 'One field for the option.' );
	}

	public function test_the_checkbox_defaults_off_and_explains_running_subscriptions_and_payment_methods(): void {
		$field = $this->field_of( ErasureSetting::add_setting( [ [ 'id' => 'woocommerce_erasure_request_removes_order_data' ] ] ) );

		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertSame( 'no', $field['default'] );
		$this->assertFalse( $field['autoload'] );
		$this->assertSame( 'Remove personal data from subscriptions', $field['desc'] );
		$this->assertStringContainsString( 'Running subscriptions are cancelled before their personal data is removed.', $field['desc_tip'] );
		$this->assertStringContainsString( 'saved payment methods, which can stop automatic renewals', $field['desc_tip'] );
		$this->assertStringContainsString( 'erase-personal-data.php', $field['desc_tip'], 'An admin gets a link to the erasure requests.' );
	}

	public function test_the_checkbox_is_not_added_twice(): void {
		$settings = [
			[ 'id' => 'woocommerce_erasure_request_removes_order_data' ],
			[
				'id'   => ErasureSetting::OPTION,
				'desc' => 'Added by another plugin',
			],
		];

		$this->assertSame( $settings, ErasureSetting::add_setting( $settings ) );
	}

	public function test_settings_without_the_order_checkbox_are_unchanged(): void {
		$settings = [ [ 'id' => 'woocommerce_registration_generate_password' ] ];

		$this->assertSame( $settings, ErasureSetting::add_setting( $settings ) );
	}

	public function test_is_enabled_reads_the_option_and_defaults_to_off(): void {
		delete_option( ErasureSetting::OPTION );
		$this->assertFalse( ErasureSetting::is_enabled() );

		update_option( ErasureSetting::OPTION, 'yes' );
		$this->assertTrue( ErasureSetting::is_enabled() );

		update_option( ErasureSetting::OPTION, 'no' );
		$this->assertFalse( ErasureSetting::is_enabled() );
	}
}
