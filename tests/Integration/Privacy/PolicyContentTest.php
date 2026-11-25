<?php
/**
 * Integration tests for the suggested privacy policy text.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\Privacy;

use Automattic\WooCommerce\SubscriptionsLite\Privacy\PolicyContent;
use Automattic\WooCommerce\SubscriptionsLite\Tests\Integration\LiteIntegrationTestCase;
use ReflectionProperty;
use WP_Privacy_Policy_Content;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Privacy\PolicyContent
 */
final class PolicyContentTest extends LiteIntegrationTestCase {

	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_the_text_is_added_on_admin_init(): void {
		$this->assertSame( 10, has_action( 'admin_init', [ PolicyContent::class, 'add' ] ) );
	}

	public function test_the_text_is_registered_with_the_privacy_policy_guide(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );

		$content = new ReflectionProperty( WP_Privacy_Policy_Content::class, 'policy_content' );
		$content->setAccessible( true );
		$before = $content->getValue();

		// wp_add_privacy_policy_content() only accepts content during or after admin_init.
		global $wp_actions;
		$admin_init_runs          = $wp_actions['admin_init'] ?? null;
		$wp_actions['admin_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		try {
			PolicyContent::add();
			$registered = $content->getValue();
		} finally {
			$content->setValue( null, $before );
			if ( null === $admin_init_runs ) {
				unset( $wp_actions['admin_init'] );
			} else {
				$wp_actions['admin_init'] = $admin_init_runs; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
			}
		}

		$this->assertContains(
			[
				'plugin_name' => 'WooCommerce Subscriptions Lite',
				'policy_text' => PolicyContent::get_content(),
			],
			$registered
		);
	}

	public function test_the_text_names_what_is_stored_and_the_saved_payment_method_interaction(): void {
		$content = PolicyContent::get_content();

		$this->assertStringContainsString( 'billing and shipping addresses, email address and phone number', $content );
		$this->assertStringContainsString( 'a reference to the saved payment method', $content );
		$this->assertStringContainsString( 'WooCommerce also removes the customer&#039;s saved payment methods', $content );
		$this->assertStringContainsString( 'keeps subscription data in the database', $content );
	}
}
