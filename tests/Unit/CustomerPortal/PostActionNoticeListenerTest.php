<?php
/**
 * Unit tests for the post-action notice listener.
 *
 * Covers the per-outcome cancel copy (at-period-end vs immediate) and the hook
 * wiring. The session-queueing path needs a booted WooCommerce, so it is left
 * to the integration suite; the copy logic is pure and tested here.
 *
 * @package Automattic\WooCommerce\SubscriptionsLite\Tests
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsLite\Tests\Unit\CustomerPortal;

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\PostActionNoticeListener;

/**
 * @covers \Automattic\WooCommerce\SubscriptionsLite\CustomerPortal\PostActionNoticeListener
 */
final class PostActionNoticeListenerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['woocommerce_subscriptions_lite_test_hooks'] = [];
	}

	public function test_cancel_message_is_at_period_end_aware(): void {
		$listener = new PostActionNoticeListener();

		$scheduled = $listener->cancel_message( true );
		$this->assertStringContainsString( 'scheduled to cancel', $scheduled );

		$immediate = $listener->cancel_message( false );
		$this->assertStringContainsString( 'has been cancelled', $immediate );
		$this->assertNotSame( $scheduled, $immediate );
	}

	public function test_register_binds_the_three_post_action_hooks(): void {
		PostActionNoticeListener::register();

		$hook_names = array_map(
			static fn ( array $h ): string => (string) $h['hook'],
			$GLOBALS['woocommerce_subscriptions_lite_test_hooks']
		);

		$this->assertContains( PostActionNoticeListener::ACTION_CANCELLED, $hook_names );
		$this->assertContains( PostActionNoticeListener::ACTION_HELD, $hook_names );
		$this->assertContains( PostActionNoticeListener::ACTION_REACTIVATED, $hook_names );
	}
}
