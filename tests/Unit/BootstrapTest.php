<?php
/**
 * Unit tests for the package bootstrap.
 *
 * The bootstrap wires the slice's feature modules into WordPress. These tests
 * assert that booting it registers the checkout, portal-list, and portal-cancel
 * hooks, and that it is idempotent. The engine boot is exercised separately; the
 * stubs make `Package::init()` a no-op-safe call here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Automattic\WooCommerce\SubscriptionsLite\Bootstrap;
use Automattic\WooCommerce\SubscriptionsLite\Portal\CancelHandler;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\Bootstrap
 */
final class BootstrapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'] = [];
		$this->reset_initialized_flag();
	}

	/**
	 * Reset the bootstrap's one-shot guard so each test boots from clean state.
	 */
	private function reset_initialized_flag(): void {
		$reflection = new ReflectionClass( Bootstrap::class );
		$property   = $reflection->getProperty( 'initialized' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	/**
	 * The hook names this slice's bootstrap is expected to register.
	 *
	 * @return array<int, string>
	 */
	private function registered_hook_names(): array {
		return array_map(
			static fn ( array $h ): string => (string) $h['hook'],
			$GLOBALS['woocommerce_subscriptions_lite_test_hooks']
		);
	}

	public function test_init_registers_the_checkout_hook(): void {
		Bootstrap::init();

		$this->assertContains( 'woocommerce_checkout_order_processed', $this->registered_hook_names() );
	}

	public function test_init_registers_the_portal_cancel_hook(): void {
		Bootstrap::init();

		$this->assertContains( 'template_redirect', $this->registered_hook_names() );
	}

	public function test_init_registers_the_subscriptions_menu_item_filter(): void {
		Bootstrap::init();

		$this->assertContains( 'woocommerce_account_menu_items', $this->registered_hook_names() );
	}

	public function test_init_is_idempotent(): void {
		Bootstrap::init();
		$first = count( $GLOBALS['woocommerce_subscriptions_lite_test_hooks'] );

		Bootstrap::init();
		$second = count( $GLOBALS['woocommerce_subscriptions_lite_test_hooks'] );

		$this->assertSame( $first, $second, 'A second init() does not re-register hooks.' );
	}
}
